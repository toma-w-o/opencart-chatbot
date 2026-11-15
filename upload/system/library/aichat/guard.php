<?php
namespace Aichat;

/**
 * Conversation token (HMAC), rate limits, budget cap, usage accounting.
 */
class Guard {
	// Prompt-caching price multipliers relative to the input price.
	// Cache reads are 0.1x on most models (0.05x on Sonnet/Opus 5.5); we use
	// the higher figure so the budget cap errs on the safe side.
	const CACHE_WRITE_FACTOR = 1.25;
	const CACHE_READ_FACTOR = 0.1;

	private $db;
	private $settings;

	public function __construct($registry, Settings $settings = null) {
		$this->db = $registry->get('db');
		$this->settings = $settings ? $settings : new Settings($registry);
	}

	// ---------------------------------------------------------------- token

	public static function newPublicId() {
		return bin2hex(random_bytes(16));
	}

	/** token = <public_id>.<hmac(public_id|store_id)> (base64url, truncated to 128 bits) */
	public function makeToken($publicId, $storeId) {
		return $publicId . '.' . $this->sign($publicId, $storeId);
	}

	/** Returns the public_id for a valid token of this store, or false. */
	public function parseToken($token, $storeId) {
		$token = (string)$token;

		if (!preg_match('/^([a-f0-9]{32})\.([A-Za-z0-9_-]{22})$/', $token, $m)) {
			return false;
		}

		if (!hash_equals($this->sign($m[1], $storeId), $m[2])) {
			return false;
		}

		return $m[1];
	}

	private function sign($publicId, $storeId) {
		$raw = hash_hmac('sha256', 'aichat|' . $publicId . '|' . (int)$storeId, $this->settings->getSecret(), true);

		return rtrim(strtr(base64_encode(substr($raw, 0, 16)), '+/', '-_'), '=');
	}

	// ---------------------------------------------------------------- rate limits

	/**
	 * Fixed-window counter. Counts this hit and returns true while the
	 * number of hits in the current window is <= $limit. $limit <= 0 = no limit.
	 */
	public function hit($key, $window, $limit) {
		if ($limit <= 0) {
			return true;
		}

		$start = (int)(floor(time() / $window) * $window);
		$rateKey = $this->db->escape(substr(hash('sha256', $key), 0, 64));

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_rate` (`rate_key`, `window_start`, `hits`) VALUES ('" . $rateKey . "', '" . $start . "', 1)
			ON DUPLICATE KEY UPDATE `hits` = IF(`window_start` = VALUES(`window_start`), `hits` + 1, 1), `window_start` = VALUES(`window_start`)");

		$query = $this->db->query("SELECT `hits` FROM `" . DB_PREFIX . "aichat_rate` WHERE `rate_key` = '" . $rateKey . "'");

		if (mt_rand(1, 200) === 1) {
			$this->db->query("DELETE FROM `" . DB_PREFIX . "aichat_rate` WHERE `window_start` < '" . (time() - 3 * 86400) . "'");
		}

		return !$query->num_rows || (int)$query->row['hits'] <= $limit;
	}

	/**
	 * Per-message limits. Returns '' when allowed, otherwise an error code.
	 *
	 * @param array $store   per-store settings (Settings::getStore($id))
	 * @param array|null $conversation existing conversation row or null (new chat)
	 */
	public function checkMessage(array $store, $conversation, $publicId, $ip) {
		$perMinute = (int)$store['s_max_msgs_minute'];

		if (!$this->hit('m:c:' . $publicId, 60, $perMinute)) {
			return 'rate_limited';
		}

		// Several tabs/visitors behind one IP: allow a few conversations' worth
		if (!$this->hit('m:i:' . $ip, 60, $perMinute > 0 ? $perMinute * 3 : 0)) {
			return 'rate_limited';
		}

		if ($conversation && (int)$store['s_max_msgs_chat'] > 0 && (int)$conversation['user_messages'] >= (int)$store['s_max_msgs_chat']) {
			return 'chat_limit';
		}

		if (!$conversation && !$this->hit('c:i:' . $ip . ':' . date('Y-m-d'), 86400, (int)$store['s_max_chats_day'])) {
			return 'chat_day_limit';
		}

		return '';
	}

	// ---------------------------------------------------------------- budget

	/** '' when an API call is allowed, otherwise 'budget'. Checked before every call. */
	public function checkBudget($storeId) {
		$global = (float)$this->settings->getGlobal('g_budget_month');

		if ($global > 0 && $this->monthCost(null) >= $global) {
			return 'budget';
		}

		$store = (float)$this->settings->getStore($storeId, 's_budget_month');

		if ($store > 0 && $this->monthCost($storeId) >= $store) {
			return 'budget';
		}

		return '';
	}

	/** USD spent in the current calendar month; $storeId null = all stores. */
	public function monthCost($storeId = null) {
		$sql = "SELECT COALESCE(SUM(`cost`), 0) AS total FROM `" . DB_PREFIX . "aichat_usage` WHERE `day` >= '" . date('Y-m-01') . "'";

		if ($storeId !== null) {
			$sql .= " AND `store_id` = '" . (int)$storeId . "'";
		}

		return (float)$this->db->query($sql)->row['total'];
	}

	// ---------------------------------------------------------------- usage

	/** Normalises an API `usage` object into token counts. */
	public static function tokens($usage) {
		$usage = is_array($usage) ? $usage : array();

		$in = isset($usage['input_tokens']) ? (int)$usage['input_tokens'] : 0;
		$write = isset($usage['cache_creation_input_tokens']) ? (int)$usage['cache_creation_input_tokens'] : 0;
		$read = isset($usage['cache_read_input_tokens']) ? (int)$usage['cache_read_input_tokens'] : 0;
		$out = isset($usage['output_tokens']) ? (int)$usage['output_tokens'] : 0;

		return array(
			'input'       => $in,
			'cache_write' => $write,
			'cache_read'  => $read,
			'output'      => $out,
			'prompt'      => $in + $write + $read, // total prompt size
		);
	}

	/** Price of one API response's usage (USD), from the owner-entered per-MTok prices. */
	public function cost($usage) {
		$t = self::tokens($usage);
		$priceIn = (float)$this->settings->getGlobal('g_price_in');
		$priceOut = (float)$this->settings->getGlobal('g_price_out');

		$input = $t['input'] + $t['cache_write'] * self::CACHE_WRITE_FACTOR + $t['cache_read'] * self::CACHE_READ_FACTOR;

		return round(($input * $priceIn + $t['output'] * $priceOut) / 1000000, 6);
	}

	/**
	 * Records one API call: daily usage row + conversation totals. Returns
	 * array(prompt_tokens, output_tokens, cost).
	 */
	public function recordUsage($storeId, $conversationId, $usage) {
		$t = self::tokens($usage);
		$cost = $this->cost($usage);

		$this->bumpDay($storeId, 0, 0, $t['prompt'], $t['output'], $cost);

		if ($conversationId) {
			$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `input_tokens` = `input_tokens` + " . (int)$t['prompt'] . ", `output_tokens` = `output_tokens` + " . (int)$t['output'] . ", `cost` = `cost` + " . sprintf('%.6F', $cost) . " WHERE `conversation_id` = '" . (int)$conversationId . "'");
		}

		return array($t['prompt'], $t['output'], $cost);
	}

	public function countChat($storeId) {
		$this->bumpDay($storeId, 1, 0, 0, 0, 0);
	}

	public function countMessage($storeId) {
		$this->bumpDay($storeId, 0, 1, 0, 0, 0);
	}

	private function bumpDay($storeId, $chats, $messages, $in, $out, $cost) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_usage` (`store_id`, `day`, `chats`, `messages`, `input_tokens`, `output_tokens`, `cost`)
			VALUES ('" . (int)$storeId . "', '" . date('Y-m-d') . "', " . (int)$chats . ", " . (int)$messages . ", " . (int)$in . ", " . (int)$out . ", " . sprintf('%.6F', $cost) . ")
			ON DUPLICATE KEY UPDATE `chats` = `chats` + VALUES(`chats`), `messages` = `messages` + VALUES(`messages`), `input_tokens` = `input_tokens` + VALUES(`input_tokens`), `output_tokens` = `output_tokens` + VALUES(`output_tokens`), `cost` = `cost` + VALUES(`cost`)");
	}
}
