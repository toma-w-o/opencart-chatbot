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

	const TURNSTILE_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	/** New conversations per IP per day (shared IPs: mobile carriers, offices). */
	const IP_CHATS_DAY = 30;
	/** Messages per IP per hour when the visitor's browser could not run Turnstile at all. */
	const UNAVAILABLE_PER_HOUR = 20;
	const WARN_RATIO = 0.8;

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

	/**
	 * Preview (admin test chat) token for an aichat_preview row:
	 * "p<preview_id>.<hmac>". The admin controller calls
	 * \Aichat\Guard::makePreviewToken($previewId, $this->registry) and relays
	 * messages to the store's catalog `send` with the extra field `preview`.
	 */
	public static function makePreviewToken($previewId, $registry) {
		$settings = new Settings($registry);

		return 'p' . (int)$previewId . '.' . self::previewSig((int)$previewId, $settings->getSecret());
	}

	/** preview_id of a valid preview token, or 0. */
	public function parsePreviewToken($token) {
		if (!is_string($token) || !preg_match('/^p(\d{1,10})\.([A-Za-z0-9_-]{22})$/', $token, $m)) {
			return 0;
		}

		return hash_equals(self::previewSig((int)$m[1], $this->settings->getSecret()), $m[2]) ? (int)$m[1] : 0;
	}

	/** The test conversation of a preview has a fixed public_id derived from it (id + creation time: ids may be reused after a reinstall). */
	public function previewPublicId($previewId, $created = '') {
		return substr(hash_hmac('sha256', 'aichat|preview-conv|' . (int)$previewId . '|' . $created, $this->settings->getSecret()), 0, 32);
	}

	private static function previewSig($previewId, $secret) {
		$raw = hash_hmac('sha256', 'aichat|preview|' . (int)$previewId, $secret, true);

		return rtrim(strtr(base64_encode(substr($raw, 0, 16)), '+/', '-_'), '=');
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
	 * @param bool $human    conversation passed Turnstile: the per-IP limits
	 *                       (shared IPs) are skipped, the per-conversation ones stay
	 * @param int $storeId   for the store's daily chat cap
	 */
	public function checkMessage(array $store, $conversation, $publicId, $ip, $human = false, $storeId = 0) {
		$perMinute = (int)$store['s_max_msgs_minute'];

		if (!$this->hit('m:c:' . $publicId, 60, $perMinute)) {
			return 'rate_limited';
		}

		// Several tabs/visitors behind one IP: allow a few conversations' worth
		if (!$human && !$this->hit('m:i:' . $ip, 60, $perMinute > 0 ? $perMinute * 3 : 0)) {
			return 'rate_limited';
		}

		if ($conversation && (int)$store['s_max_msgs_chat'] > 0 && (int)$conversation['user_messages'] >= (int)$store['s_max_msgs_chat']) {
			return 'chat_limit';
		}

		if (!$conversation) {
			// daily chat cap of the store (s_max_chats_day, 0 = off)
			$cap = (int)$store['s_max_chats_day'];
			if ($cap > 0 && $this->chatsToday($storeId) >= $cap) {
				return 'chat_day_limit';
			}
			if (!$human && !$this->hit('c:i:' . $ip . ':' . date('Y-m-d'), 86400, self::IP_CHATS_DAY)) {
				return 'chat_day_limit';
			}
		}

		return '';
	}

	public function chatsToday($storeId) {
		$query = $this->db->query("SELECT `chats` FROM `" . DB_PREFIX . "aichat_usage` WHERE `store_id` = '" . (int)$storeId . "' AND `day` = '" . date('Y-m-d') . "'");

		return $query->num_rows ? (int)$query->row['chats'] : 0;
	}

	/** Extra per-IP budget for visitors whose browser cannot run Turnstile. */
	public function hitUnavailable($ip) {
		return $this->hit('t:u:' . $ip, 3600, self::UNAVAILABLE_PER_HOUR);
	}

	// ---------------------------------------------------------------- client ip

	/** REMOTE_ADDR, or CF-Connecting-IP when g_trust_cf_ip is on (site behind Cloudflare). */
	public function clientIp(array $server) {
		$ip = isset($server['REMOTE_ADDR']) ? (string)$server['REMOTE_ADDR'] : '';

		if ((int)$this->settings->getGlobal('g_trust_cf_ip') && !empty($server['HTTP_CF_CONNECTING_IP'])) {
			$cf = trim((string)$server['HTTP_CF_CONNECTING_IP']);
			if (filter_var($cf, FILTER_VALIDATE_IP)) {
				$ip = $cf;
			}
		}

		return substr($ip, 0, 45);
	}

	// ---------------------------------------------------------------- turnstile

	public function turnstileEnabled() {
		return trim((string)$this->settings->getGlobal('g_turnstile_site')) !== '' && trim((string)$this->settings->getGlobal('g_turnstile_secret')) !== '';
	}

	public static function turnstileUrl() {
		return defined('AICHAT_TURNSTILE_URL') ? (string)constant('AICHAT_TURNSTILE_URL') : self::TURNSTILE_URL;
	}

	/**
	 * Cloudflare siteverify. Returns 'ok', 'fail' (token rejected) or
	 * 'outage' (Cloudflare unreachable / 5xx / garbage: fall back to the rate limits).
	 */
	public function verifyTurnstile($token, $ip) {
		$token = (string)$token;

		if ($token === '' || strlen($token) > 2048) {
			return 'fail';
		}

		if (!function_exists('curl_init')) {
			return 'outage';
		}

		$ch = curl_init(self::turnstileUrl());
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query(array(
				'secret'   => trim((string)$this->settings->getGlobal('g_turnstile_secret')),
				'response' => $token,
				'remoteip' => $ip,
			)),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_TIMEOUT        => 6,
		));
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $status >= 500 || $status === 0) {
			return 'outage';
		}

		$json = json_decode((string)$body, true);

		if (!is_array($json) || !array_key_exists('success', $json)) {
			return 'outage';
		}

		if ($json['success'] === true) {
			return 'ok';
		}

		// Cloudflare's own failure (not the visitor's token) counts as outage
		$codes = isset($json['error-codes']) && is_array($json['error-codes']) ? $json['error-codes'] : array();
		if (in_array('internal-error', $codes, true)) {
			return 'outage';
		}

		return 'fail';
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

	/**
	 * 80% warning e-mail, once per calendar month per scope (all stores /
	 * one store), to g_warn_email or the store's e-mail, through OpenCart's
	 * Mail class. Call after usage was recorded. Never throws.
	 *
	 * Test hook: with the constant AICHAT_MAIL_FILE defined the mail is
	 * appended to that file instead of being sent.
	 */
	public function budgetWarnings($registry, $storeId) {
		try {
			$month = date('Y-m');
			$sent = $this->settings->getGlobal('g_warn_sent');
			$sent = is_array($sent) ? $sent : array();
			$changed = false;
			$scopes = array();

			$global = (float)$this->settings->getGlobal('g_budget_month');
			if ($global > 0) {
				$scopes['all'] = array($global, null);
			}

			$store = (float)$this->settings->getStore($storeId, 's_budget_month');
			if ($store > 0) {
				$scopes['store_' . (int)$storeId] = array($store, (int)$storeId);
			}

			foreach ($scopes as $scope => $info) {
				if (isset($sent[$scope]) && $sent[$scope] === $month) {
					continue;
				}

				$spent = $this->monthCost($info[1]);

				if ($spent < $info[0] * self::WARN_RATIO) {
					continue;
				}

				$sent[$scope] = $month;
				$changed = true;
				$this->mailWarning($registry, $scope, $info[1], $spent, $info[0]);
			}

			if ($changed) {
				$this->settings->setInternal('g_warn_sent', $sent);
			}
		} catch (\Exception $e) {
			$log = $registry->get('log');
			if ($log) {
				$log->write('AI Chat budget warning: ' . $e->getMessage());
			}
		}
	}

	private function mailWarning($registry, $scope, $storeId, $spent, $budget) {
		$config = $registry->get('config');
		$to = trim((string)$this->settings->getGlobal('g_warn_email'));
		$storeName = StoreInfo::name($registry, $storeId === null ? 0 : (int)$storeId, $this->settings);
		$from = trim((string)$config->get('config_email'));

		if ($storeId === null || (int)$storeId !== (int)$config->get('config_store_id')) {
			// "all stores": the main store's e-mail and name
			$row = $this->db->query("SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = 'config' AND `key` IN ('config_email', 'config_name')")->rows;
			foreach ($row as $r) {
				if ($r['key'] === 'config_email' && $to === '') {
					$to = trim($r['value']);
				}
			}
		}

		if ($to === '') {
			$to = $from;
		}

		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return false;
		}

		$what = $storeId === null ? 'all stores' : 'store "' . $storeName . '"';
		$subject = 'AI Chat: 80% of the monthly budget used (' . $what . ')';
		$text = "AI Chat has used " . sprintf('%.2f', $spent) . " USD of the monthly budget of " . sprintf('%.2f', $budget) . " USD for " . $what . " in " . date('F Y') . ".\n"
			. "When the budget is reached the chat stops answering until next month or until the budget is raised in the admin (Extensions > Modules > AI Chat).\n\n"
			. "AI Chat е използвал " . sprintf('%.2f', $spent) . " от месечния бюджет " . sprintf('%.2f', $budget) . " USD (" . $what . ").\n";

		// OpenCart's mail engine + settings of the store concerned (store 0 for "all stores")
		return Mailer::send($registry, $to, $subject, $text, array('store_id' => $storeId === null ? 0 : (int)$storeId, 'from' => $from !== '' ? $from : $to, 'sender' => $storeName));
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

	/**
	 * Price of one API response's usage (USD), from the owner-entered per-MTok
	 * prices; $light = true uses the light model's prices (translation).
	 */
	public function cost($usage, $light = false) {
		$t = self::tokens($usage);
		$priceIn = (float)$this->settings->getGlobal($light ? 'g_price_light_in' : 'g_price_in');
		$priceOut = (float)$this->settings->getGlobal($light ? 'g_price_light_out' : 'g_price_out');

		$input = $t['input'] + $t['cache_write'] * self::CACHE_WRITE_FACTOR + $t['cache_read'] * self::CACHE_READ_FACTOR;

		return round(($input * $priceIn + $t['output'] * $priceOut) / 1000000, 6);
	}

	/**
	 * Records one API call: daily usage row + conversation totals. Returns
	 * array(prompt_tokens, output_tokens, cost).
	 */
	public function recordUsage($storeId, $conversationId, $usage, $light = false) {
		$t = self::tokens($usage);
		$cost = $this->cost($usage, $light);

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
