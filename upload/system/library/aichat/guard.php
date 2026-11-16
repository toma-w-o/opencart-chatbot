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
	/** Messages per hour, whole store, from browsers that could not run Turnstile ("unavailable"). */
	const UNAVAILABLE_STORE_HOUR = 60;
	/** Failed order verifications per store per hour before the store counts as "under attack". */
	const ORDER_FAIL_STORE_HOUR = 20;
	/** While under attack: order checks per IP (/64) per hour for conversations without Turnstile. */
	const ORDER_ATTEMPTS_UNDER_ATTACK = 2;
	/** Cost caps (USD per calendar day). */
	const CONV_COST_DAY = 0.5;
	const IP_COST_DAY = 2.0;
	/** Without a store budget: share of the global monthly budget one store may use per day / per month. */
	const STORE_DAY_SHARE = 0.1;
	const STORE_MONTH_SHARE = 0.5;
	/** Concurrent model requests per store (MySQL GET_LOCK slots). */
	const MAX_CONCURRENT = 4;
	/** Lifetime of a signed image URL (seconds). */
	const IMAGE_URL_TTL = 7200;

	/** Cloudflare's published ranges (https://www.cloudflare.com/ips/), checked before CF-Connecting-IP is believed. */
	public static function cloudflareRanges() {
		return array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
			'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
			'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
			'2a06:98c0::/29', '2c0f:f248::/32',
		);
	}

	private $db;
	private $settings;
	private $slots = array();

	public function __construct($registry, Settings $settings = null) {
		$this->db = $registry->get('db');
		$this->settings = $settings ? $settings : new Settings($registry);
	}

	/** HMAC signature of a short-lived image URL (no conversation token in URLs). */
	public function imageSig($uploadId, $expires, $thumb) {
		$raw = hash_hmac('sha256', 'aichat|image|' . (int)$uploadId . '|' . (int)$expires . '|' . ($thumb ? 1 : 0), $this->settings->getSecret(), true);

		return rtrim(strtr(base64_encode(substr($raw, 0, 16)), '+/', '-_'), '=');
	}

	public function checkImageSig($uploadId, $expires, $thumb, $sig) {
		return is_string($sig) && (int)$expires >= time() && (int)$expires <= time() + self::IMAGE_URL_TTL + 60 && hash_equals($this->imageSig($uploadId, $expires, $thumb), $sig);
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
		$ipKey = self::rateIp($ip);

		if (!$this->hit('m:c:' . $publicId, 60, $perMinute)) {
			return 'rate_limited';
		}

		// Several tabs/visitors behind one IP (IPv6: one /64): allow a few conversations' worth
		if (!$human && !$this->hit('m:i:' . $ipKey, 60, $perMinute > 0 ? $perMinute * 3 : 0)) {
			return 'rate_limited';
		}

		if ($conversation && (int)$store['s_max_msgs_chat'] > 0 && (int)$conversation['user_messages'] >= (int)$store['s_max_msgs_chat']) {
			return 'chat_limit';
		}

		// a conversation created by an image upload counts as new until its first message
		if (!$conversation || (int)$conversation['user_messages'] === 0) {
			if (!$human && !$this->hit('c:i:' . $ipKey . ':' . date('Y-m-d'), 86400, self::IP_CHATS_DAY)) {
				return 'chat_day_limit';
			}
			// daily chat cap of the store (s_max_chats_day, 0 = off): atomic counter, no read-then-write race
			$cap = (int)$store['s_max_chats_day'];
			if ($cap > 0 && ($this->chatsToday($storeId) >= $cap || !$this->hit('cap:s:' . (int)$storeId . ':' . date('Y-m-d'), 86400, $cap))) {
				return 'chat_day_limit';
			}
		}

		return '';
	}

	public function chatsToday($storeId) {
		$query = $this->db->query("SELECT `chats` FROM `" . DB_PREFIX . "aichat_usage` WHERE `store_id` = '" . (int)$storeId . "' AND `day` = '" . date('Y-m-d') . "'");

		return $query->num_rows ? (int)$query->row['chats'] : 0;
	}

	/**
	 * Visitors whose browser cannot run Turnstile: a per-IP (/64) budget AND a
	 * small store-wide hourly budget, so the fallback cannot be used to skip
	 * the challenge at scale.
	 */
	public function hitUnavailable($ip, $storeId = 0) {
		return $this->hit('t:u:' . self::rateIp($ip), 3600, self::UNAVAILABLE_PER_HOUR)
			&& $this->hit('t:u:s:' . (int)$storeId, 3600, self::UNAVAILABLE_STORE_HOUR);
	}

	// ---------------------------------------------------------------- order verification (store-wide safety)

	/** Failed order verifications of this store in the current hour. */
	public function orderFailures($storeId) {
		return $this->amount('of:s:' . (int)$storeId, 3600);
	}

	/** Adds failed order verifications (the controller counts them per request). */
	public function recordOrderFailures($storeId, $count) {
		if ((int)$count > 0) {
			$this->addAmount('of:s:' . (int)$storeId, 3600, (int)$count);
		}
	}

	/** Many failed verifications this hour (spread over many IPs): stricter rules for unverified visitors. */
	public function underOrderAttack($storeId) {
		return $this->orderFailures($storeId) >= self::ORDER_FAIL_STORE_HOUR;
	}

	/**
	 * For Orders::findOrder (before a guest check): true = may check now.
	 * Normally always true (the per-IP / per-chat limits apply as before);
	 * while the store is under attack, a conversation that has not passed
	 * Turnstile gets ORDER_ATTEMPTS_UNDER_ATTACK checks per IP (/64) per hour.
	 * Honest customers are slowed, never locked out shop-wide.
	 */
	public function orderGate($storeId, $ip, $conversation = null) {
		if (!$this->underOrderAttack($storeId)) {
			return true;
		}

		if (is_array($conversation) && isset($conversation['human_verified']) && (int)$conversation['human_verified'] === 1) {
			return true;
		}

		return $this->hit('o:x:' . self::rateIp($ip), 3600, self::ORDER_ATTEMPTS_UNDER_ATTACK);
	}

	// ---------------------------------------------------------------- amounts (counters in aichat_rate)

	/** Adds $n to a windowed counter (same table as the rate limits). */
	public function addAmount($key, $window, $n) {
		$start = (int)(floor(time() / $window) * $window);
		$rateKey = $this->db->escape(substr(hash('sha256', $key), 0, 64));
		$n = max(0, min(2000000000, (int)$n));

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_rate` (`rate_key`, `window_start`, `hits`) VALUES ('" . $rateKey . "', '" . $start . "', " . $n . ")
			ON DUPLICATE KEY UPDATE `hits` = IF(`window_start` = VALUES(`window_start`), LEAST(`hits` + VALUES(`hits`), 2000000000), VALUES(`hits`)), `window_start` = VALUES(`window_start`)");
	}

	/** Current value of a windowed counter (0 when the window has passed). */
	public function amount($key, $window) {
		$start = (int)(floor(time() / $window) * $window);
		$query = $this->db->query("SELECT `hits` FROM `" . DB_PREFIX . "aichat_rate` WHERE `rate_key` = '" . $this->db->escape(substr(hash('sha256', $key), 0, 64)) . "' AND `window_start` = '" . $start . "'");

		return $query->num_rows ? (int)$query->row['hits'] : 0;
	}

	// ---------------------------------------------------------------- concurrency slots

	/**
	 * One of MAX_CONCURRENT slots per store (MySQL GET_LOCK, released with
	 * the DB connection at the latest). Returns false when all are taken.
	 */
	public function acquireSlot($storeId) {
		$prefix = 'aichat_' . substr(md5((defined('DB_DATABASE') ? DB_DATABASE : '') . '|' . DB_PREFIX), 0, 10) . '_' . (int)$storeId . '_';
		$order = range(1, self::MAX_CONCURRENT);
		shuffle($order);

		foreach ($order as $n) {
			$name = $prefix . $n;
			try {
				$row = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($name) . "', 0) AS got")->row;
			} catch (\Exception $e) {
				return true; // no named locks on this server: no cap rather than no chat
			}
			if (!$row || $row['got'] === null) {
				return true;
			}
			if ((int)$row['got'] === 1) {
				$this->slots[] = $name;
				return true;
			}
		}

		return false;
	}

	public function releaseSlots() {
		foreach ($this->slots as $name) {
			try {
				$this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($name) . "')");
			} catch (\Exception $e) {
				// released with the connection anyway
			}
		}

		$this->slots = array();
	}

	// ---------------------------------------------------------------- client ip

	/**
	 * REMOTE_ADDR, or CF-Connecting-IP when g_trust_cf_ip is on AND the request
	 * really comes from Cloudflare (REMOTE_ADDR inside Cloudflare's ranges).
	 * A client talking to the origin directly cannot choose its IP.
	 */
	public function clientIp(array $server) {
		$ip = isset($server['REMOTE_ADDR']) ? (string)$server['REMOTE_ADDR'] : '';

		if ((int)$this->settings->getGlobal('g_trust_cf_ip') && !empty($server['HTTP_CF_CONNECTING_IP']) && self::fromCloudflare($ip)) {
			$cf = trim((string)$server['HTTP_CF_CONNECTING_IP']);
			if (filter_var($cf, FILTER_VALIDATE_IP)) {
				$ip = $cf;
			}
		}

		return substr($ip, 0, 45);
	}

	public static function fromCloudflare($ip) {
		foreach (self::cloudflareRanges() as $cidr) {
			if (self::ipInRange($ip, $cidr)) {
				return true;
			}
		}

		return false;
	}

	/** $ip inside $cidr (IPv4 or IPv6). */
	public static function ipInRange($ip, $cidr) {
		$parts = explode('/', (string)$cidr, 2);
		$net = @inet_pton($parts[0]);
		$addr = @inet_pton((string)$ip);

		if ($net === false || $addr === false || strlen($net) !== strlen($addr)) {
			return false;
		}

		$bits = isset($parts[1]) ? (int)$parts[1] : strlen($net) * 8;
		$bytes = (int)floor($bits / 8);

		if ($bytes && substr($net, 0, $bytes) !== substr($addr, 0, $bytes)) {
			return false;
		}

		$rest = $bits % 8;

		if ($rest) {
			$mask = (0xFF << (8 - $rest)) & 0xFF;
			return (ord($net[$bytes]) & $mask) === (ord($addr[$bytes]) & $mask);
		}

		return true;
	}

	/**
	 * Key for per-IP limits: IPv4 as is, IPv6 cut to its /64 (one customer
	 * line usually owns a whole /64), IPv4-mapped IPv6 as IPv4.
	 */
	public static function rateIp($ip) {
		$ip = (string)$ip;
		$bin = @inet_pton($ip);

		if ($bin === false) {
			return $ip;
		}

		if (strlen($bin) === 16) {
			if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xFF\xFF") {
				return inet_ntop(substr($bin, 12));
			}
			return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
		}

		return $ip;
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

	/**
	 * '' when an API call is allowed, otherwise 'budget' (store / global money
	 * is used up) or 'cost_limit' (this conversation / this IP spent its daily
	 * share). Checked before every call.
	 *
	 *  - g_budget_month: all stores together;
	 *  - s_budget_month: this store; when it is not set and there is a global
	 *    budget, one store may use at most STORE_MONTH_SHARE of the global
	 *    budget per month and STORE_DAY_SHARE per day, so abuse of one store
	 *    cannot silence the others;
	 *  - CONV_COST_DAY per conversation and IP_COST_DAY per IP (/64) per day.
	 */
	public function checkBudget($storeId, $conversationId = 0, $ip = '') {
		$global = (float)$this->settings->getGlobal('g_budget_month');

		if ($global > 0 && $this->monthCost(null) >= $global) {
			return 'budget';
		}

		$store = (float)$this->settings->getStore($storeId, 's_budget_month');

		if ($store > 0 && $this->monthCost($storeId) >= $store) {
			return 'budget';
		}

		if ($store <= 0 && $global > 0) {
			if ($this->monthCost($storeId) >= $global * self::STORE_MONTH_SHARE || $this->dayCost($storeId) >= $global * self::STORE_DAY_SHARE) {
				return 'budget';
			}
		}

		if ($conversationId && $this->amount('cost:c:' . (int)$conversationId . ':' . date('Y-m-d'), 86400) >= self::CONV_COST_DAY * 1000000) {
			return 'cost_limit';
		}

		if ((string)$ip !== '' && $this->amount('cost:i:' . self::rateIp($ip) . ':' . date('Y-m-d'), 86400) >= self::IP_COST_DAY * 1000000) {
			return 'cost_limit';
		}

		return '';
	}

	/** USD spent today by one store. */
	public function dayCost($storeId) {
		$query = $this->db->query("SELECT `cost` FROM `" . DB_PREFIX . "aichat_usage` WHERE `store_id` = '" . (int)$storeId . "' AND `day` = '" . date('Y-m-d') . "'");

		return $query->num_rows ? (float)$query->row['cost'] : 0.0;
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
	public function recordUsage($storeId, $conversationId, $usage, $light = false, $ip = '') {
		$t = self::tokens($usage);
		$cost = $this->cost($usage, $light);

		$this->bumpDay($storeId, 0, 0, $t['prompt'], $t['output'], $cost);

		// daily cost counters (micro-USD) for the per-conversation / per-IP caps
		$micro = (int)round($cost * 1000000);
		if ($micro > 0) {
			if ($conversationId) {
				$this->addAmount('cost:c:' . (int)$conversationId . ':' . date('Y-m-d'), 86400, $micro);
			}
			if ((string)$ip !== '') {
				$this->addAmount('cost:i:' . self::rateIp($ip) . ':' . date('Y-m-d'), 86400, $micro);
			}
		}

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
