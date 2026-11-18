<?php
namespace Aichat;

/**
 * Typed access to the module settings.
 *
 * Storage: table `setting`, code `module_aichat`.
 *  - global keys live under store_id 0 as `module_aichat_g_<name>`
 *  - per-store keys live under each store_id as `module_aichat_s_<name>`
 *
 * Public keys use the spec names (`g_model`, `s_greeting`, ...). The short
 * forms (`model`, `greeting`) and the full forms (`module_aichat_g_model`) are
 * accepted too.
 *
 * The API key is NOT a setting: it lives in DIR_STORAGE/aichat/key.php and is
 * never returned by getGlobal()/getStore().
 */
class Settings {
	const CODE = 'module_aichat';
	const DEFAULT_MODEL = 'claude-sonnet-5-5';
	const DEFAULT_LIGHT_MODEL = 'claude-haiku-5-5';

	private $db;
	private $rows = null;

	public function __construct($registry) {
		$this->db = $registry->get('db');
	}

	/** Global defaults, keyed by spec name. */
	public static function globalDefaults() {
		return array(
			'g_model'                => self::DEFAULT_MODEL,
			'g_instructions'         => '',
			'g_secret'               => '',
			'g_price_in'             => 0.0,
			'g_price_out'            => 0.0,
			'g_budget_month'         => 0.0,
			'g_turnstile_site'       => '',
			'g_turnstile_secret'     => '',
			'g_retention_months'     => 6,    // GDPR (round 6): conversations kept 6 months by default
			'g_image_retention_days' => 30,
			'g_ip_days'              => 30,   // IP address + user agent of a conversation blanked after N days
			'g_request_months'       => 12,   // requests (contacts, stock alerts, returns, order changes)
			'g_miss_days'            => 90,   // "searched but not found" log
			'g_warn_email'           => '',
			'g_trust_cf_ip'          => 0,
			'g_couriers'             => self::defaultCouriers(),
			// round 3
			'g_model_light'          => self::DEFAULT_LIGHT_MODEL,
			'g_price_light_in'       => 0.1,  // USD per million tokens of g_model_light
			'g_price_light_out'      => 0.5,
			'g_tg_chat_id'           => '',   // Telegram forum group id, e.g. -1001234567890
			'g_tg_translate'         => 0,
			'g_tg_staff_lang'        => 'bg', // language the staff reads (code or name)
			'g_tg_canned'            => array(), // list of {code, text}; staff types /code in the topic
			'g_tg_staff_ids'         => array(), // numeric Telegram user ids allowed to take / answer chats (empty = every group member)
			'g_report_weekly'        => 0,
			'g_report_email'         => '',
			'g_timezone'             => 'Europe/Sofia',
			// internal (written by the module, not by the admin form)
			'g_schema'               => 0,
			'g_warn_sent'            => array(),
			'g_tg_secret'            => '',   // webhook path secret + secret-token header (generated)
			'g_report_week'          => '',   // ISO week the last weekly report was handled for
			'g_cleanup_day'          => '',   // day the last retention cleanup ran
		);
	}

	/** Global keys the module maintains itself; saveGlobal() ignores them. */
	public static function internalKeys() {
		return array('g_secret', 'g_schema', 'g_warn_sent', 'g_tg_secret', 'g_report_week', 'g_cleanup_day');
	}

	/**
	 * Courier list seeded at install: {code, name, type: speedy|link, hosts[],
	 * track_url with {number}, pattern (regex for a bare tracking number),
	 * aliases[] (other names staff write in comments / status names)}.
	 */
	public static function defaultCouriers() {
		return array(
			self::speedySeed(),
			self::nextLevelSeed(),
		);
	}

	public static function speedySeed() {
		return array(
			'code'      => 'speedy',
			'name'      => 'Speedy',
			'type'      => 'speedy',
			'hosts'     => array('speedy.bg'),
			'track_url' => 'https://www.speedy.bg/bg/track-shipment?shipmentNumber={number}',
			'pattern'   => '^6\\d{10}$',
			'aliases'   => array('Спиди', 'Speedy'),
		);
	}

	public static function nextLevelSeed() {
		return array(
			'code'      => 'nextlevel',
			'name'      => 'NextLevel',
			'type'      => 'link',
			'hosts'     => array('nextlevel.delivery'),
			'track_url' => 'https://nextlevel.delivery/bg/track?awb={number}',
			'pattern'   => '^1000\\d{9}$',
			'aliases'   => array('Next Level', 'НекстЛевъл', 'Некст Левъл'),
		);
	}

	/**
	 * A courier number pattern as a complete PCRE (anchored on the whole
	 * number), or '' when empty / not a valid regular expression.
	 */
	public static function patternRegex($pattern) {
		$pattern = trim((string)$pattern);

		if ($pattern === '' || strlen($pattern) > 200) {
			return '';
		}

		$core = preg_replace('/^\^/', '', $pattern);
		$core = preg_replace('/(?<!\\\\)\$$/', '', $core);
		$regex = '~^(?:' . str_replace('~', '\\~', $core) . ')$~i';

		return @preg_match($regex, '') === false ? '' : $regex;
	}

	/** Per-store defaults, keyed by spec name. */
	public static function storeDefaults() {
		$hours = array();
		foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $d) {
			$hours[$d] = array('from' => '', 'to' => '');
		}

		return array(
			's_status'            => 0,
			's_bot_name'          => '',
			's_avatar'            => 'default',
			's_greeting'          => array(),
			's_instructions'      => '',
			's_contacts'          => array('phone' => '', 'email' => '', 'viber' => '', 'whatsapp' => '', 'note' => ''),
			's_work_hours'        => $hours,
			's_emoji'             => 1,
			's_order_link_button' => 0,
			's_budget_month'      => 0.0,
			's_max_chats_day'     => 0,
			's_max_msgs_chat'     => 40,
			's_max_msgs_minute'   => 6,
			's_max_len'           => 1000,
			// round 2: appearance
			's_color_accent'          => '#4f46e5',
			's_color_header_bg'       => '#4f46e5',
			's_color_header_text'     => '#ffffff',
			's_color_user_bg'         => '#4f46e5',
			's_color_user_text'       => '#ffffff',
			's_launcher_style'        => 'avatar',
			's_launcher_text'         => array(),
			's_launcher_shape'        => 'circle',
			's_launcher_size'         => 56,
			's_launcher_avatar_size'  => 0, // 0 = automatic: 45, or 36 when text is shown
			's_pos_desktop_side'      => 'right',
			's_pos_desktop_x'         => 20,
			's_pos_desktop_y'         => 20,
			's_pos_mobile_side'       => 'right',
			's_pos_mobile_x'          => 20,
			's_pos_mobile_y'          => 20,
			's_zindex'                => 2147483000,
			's_custom_css'            => '',
			// round 2: behaviour
			's_welcome_back'          => array(),
			's_quick_replies'         => array(),
			's_resume_days'           => 7,
			's_courier'               => '',
			// round 3
			's_couriers'              => array(), // courier codes enabled for this store (empty = all)
			's_handover_timeout'      => 60,      // seconds until an untaken handover returns to the bot
			's_tg_chat_id'            => '',      // Telegram group override for this store
			's_proactive'             => 0,
			's_proactive_delay'       => 20,
			's_proactive_text'        => array(), // by language_id
			's_sound'                 => 1,
			's_feat_images'           => 1,
			's_feat_rating'           => 1,
			's_feat_cart'             => 1,
			// round 4
			's_store_name'            => '', // optional; empty = config_name -> store name -> host
			's_notify_email'          => '', // optional; empty = the store's config_email
			's_attention'             => 1,  // launcher avatar plays a short grimace while the panel is closed
			's_attention_interval'    => 5,  // seconds between grimaces
			// round 6: GDPR
			's_privacy_text'          => array(), // by language_id; empty = default text of the catalog language file
			's_privacy_url'           => array(), // by language_id; empty = the store's privacy information page
			's_privacy_accept'        => 0,       // "Accept" required before the first message
			's_wait_consent'          => 0,       // widget waits for window.AICHAT_CONSENT / event aichat:consent
			's_min_model'             => 1,       // order tools: no phone / e-mail / house number to the model
			's_tg_mask'               => 0,       // mask address / phone / e-mail in Telegram topics
			's_tg_delete_days'        => 7,       // delete Telegram topics N days after the handover ended (0 = never)
		);
	}

	/**
	 * getGlobal() -> whole array (secret excluded); getGlobal('g_model', $default) -> one value.
	 */
	public function getGlobal($key = null, $default = null) {
		$values = $this->load(0, 'g');

		if ($key === null) {
			$all = array_merge(self::globalDefaults(), $values);
			unset($all['g_secret'], $all['g_warn_sent'], $all['g_report_week'], $all['g_cleanup_day']);
			// write-only secret: never part of the whole-settings array (admin form, views)
			$all['g_turnstile_secret'] = '';
			return $this->cast($all);
		}

		$name = $this->normalise($key, 'g');

		// Turnstile secret lives in a secret file (audit L2); the old setting is only a fallback before the upgrade moved it
		if ($name === 'g_turnstile_secret') {
			$secret = $this->getNamedSecret('turnstile');
			if ($secret !== '') {
				return $secret;
			}
		}

		if (array_key_exists($name, $values)) {
			return $this->castOne($name, $values[$name]);
		}

		if ($default !== null) {
			return $default;
		}

		$defaults = self::globalDefaults();

		return isset($defaults[$name]) ? $defaults[$name] : null;
	}

	/**
	 * getStore($id) -> whole array; getStore($id, 's_bot_name', $default) -> one value.
	 */
	public function getStore($storeId, $key = null, $default = null) {
		$values = $this->load((int)$storeId, 's');

		if ($key === null) {
			return $this->cast(array_replace_recursive(self::storeDefaults(), $values));
		}

		$name = $this->normalise($key, 's');

		if (array_key_exists($name, $values)) {
			$value = $values[$name];
			$defaults = self::storeDefaults();
			if (isset($defaults[$name]) && is_array($defaults[$name]) && is_array($value) && $defaults[$name]) {
				$value = array_replace_recursive($defaults[$name], $value);
			}
			return $this->castOne($name, $value);
		}

		if ($default !== null) {
			return $default;
		}

		$defaults = self::storeDefaults();

		return isset($defaults[$name]) ? $defaults[$name] : null;
	}

	/** Saves the given global keys only; other keys are left untouched. `g_secret` is never written here. */
	public function saveGlobal(array $data) {
		$allowed = self::globalDefaults();
		foreach (self::internalKeys() as $internal) {
			unset($allowed[$internal]);
		}

		// write-only, stored with setTurnstileSecret()
		unset($allowed['g_turnstile_secret']);

		foreach ($data as $key => $value) {
			$name = $this->normalise($key, 'g');
			if (array_key_exists($name, $allowed)) {
				$this->write(0, $name, $value);
			}
		}

		$this->rows = null;
	}

	/** Saves the given per-store keys only. */
	public function saveStore($storeId, array $data) {
		$allowed = self::storeDefaults();

		foreach ($data as $key => $value) {
			$name = $this->normalise($key, 's');
			if (array_key_exists($name, $allowed)) {
				$this->write((int)$storeId, $name, $value);
			}
		}

		$this->rows = null;
	}

	/** HMAC secret; generated on first use if the installer did not create one. */
	public function getSecret() {
		$secret = (string)$this->getGlobal('g_secret', '');

		if (strlen($secret) < 32) {
			$secret = $this->generateOnce('g_secret', function ($v) {
				return strlen((string)$v) >= 32;
			}, function () {
				return bin2hex(random_bytes(32));
			});
		}

		return $secret;
	}

	public function ensureSecret() {
		return $this->getSecret();
	}

	/** Webhook secret for Telegram (path parameter and secret-token header); generated on first use. */
	public function telegramSecret() {
		$secret = (string)$this->getGlobal('g_tg_secret', '');

		if (!preg_match('/^[A-Za-z0-9_-]{32,}$/', $secret)) {
			$secret = $this->generateOnce('g_tg_secret', function ($v) {
				return (bool)preg_match('/^[A-Za-z0-9_-]{32,}$/', (string)$v);
			}, function () {
				return bin2hex(random_bytes(24));
			});
		}

		return $secret;
	}

	/**
	 * Generate-if-missing for a global key, race-safe: under a named lock the
	 * value is read again straight from the database, so of two simultaneous
	 * requests only the first generates and both return the same value.
	 */
	private function generateOnce($name, $valid, $make) {
		$lock = $this->lock('0|' . $name); // the same lock as write(0, $name)

		try {
			$current = $this->readRaw(0, $name);
			if ($current !== null && call_user_func($valid, $current)) {
				$value = (string)$current;
			} else {
				$value = (string)call_user_func($make);
				$this->writeRaw(0, $name, $value);
			}
		} finally {
			$this->unlock($lock);
		}

		$this->rows = null;

		return $value;
	}

	/**
	 * Duplicate rows of one key (left by the old unlocked DELETE + INSERT when
	 * two requests wrote at once): keeps the newest row, as load() reads it.
	 * Called by the installer / upgrade.
	 */
	public function removeDuplicates() {
		$this->db->query("DELETE s1 FROM `" . DB_PREFIX . "setting` s1 JOIN `" . DB_PREFIX . "setting` s2 ON (s2.`code` = s1.`code` AND s2.`store_id` = s1.`store_id` AND s2.`key` = s1.`key` AND s2.`setting_id` > s1.`setting_id`) WHERE s1.`code` = '" . self::CODE . "'");
		$this->rows = null;

		return (int)$this->db->countAffected();
	}

	/** MySQL / MariaDB named lock per key (5 s); '' when the server has none (then unlocked, as before). */
	private function lock($name) {
		$lockName = 'aichat_set_' . substr(md5((defined('DB_DATABASE') ? DB_DATABASE : '') . '|' . DB_PREFIX . '|' . $name), 0, 40);

		try {
			$row = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lockName) . "', 5) AS got")->row;
		} catch (\Exception $e) {
			return '';
		}

		return ($row && (string)$row['got'] === '1') ? $lockName : '';
	}

	private function unlock($lockName) {
		if ($lockName !== '') {
			try {
				$this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
			} catch (\Exception $e) {
				// released with the connection anyway
			}
		}
	}

	/** One stored value straight from the database (newest row), or null. */
	private function readRaw($storeId, $name) {
		$row = $this->db->query("SELECT `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '" . (int)$storeId . "' AND `code` = '" . self::CODE . "' AND `key` = '" . $this->db->escape(self::CODE . '_' . $name) . "' ORDER BY `setting_id` DESC LIMIT 1")->row;

		if (!$row) {
			return null;
		}

		return $row['serialized'] ? json_decode($row['value'], true) : $row['value'];
	}

	/** New Telegram webhook secret (admin "regenerate"); the webhook must be set again afterwards. */
	public function regenerateTelegramSecret() {
		$this->write(0, 'g_tg_secret', bin2hex(random_bytes(24)));
		$this->rows = null;
		$this->rows = null;

		return $this->telegramSecret();
	}

	/** Courier codes enabled for a store, default first (s_couriers empty = every configured courier). */
	public function storeCouriers($storeId) {
		$all = $this->couriers();
		$enabled = $this->getStore($storeId, 's_couriers');
		$enabled = is_array($enabled) ? array_values(array_filter(array_map('strval', $enabled))) : array();
		$out = array();

		foreach ($all as $code => $c) {
			if (!$enabled || in_array($code, $enabled, true)) {
				$out[$code] = $c;
			}
		}

		$default = (string)$this->getStore($storeId, 's_courier');

		if ($default !== '' && isset($out[$default])) {
			$out = array($default => $out[$default]) + $out;
		}

		return $out;
	}

	/** Writes one module-maintained global key (g_schema, g_warn_sent). */
	public function setInternal($key, $value) {
		$name = $this->normalise($key, 'g');

		if (in_array($name, self::internalKeys(), true) && $name !== 'g_secret') {
			$this->write(0, $name, $value);
			$this->rows = null;
		}
	}

	/** Couriers as a clean list: code => {code, name, type, hosts[], track_url}. */
	public function couriers() {
		$out = array();
		$list = $this->getGlobal('g_couriers');

		foreach (is_array($list) ? $list : array() as $c) {
			if (!is_array($c) || empty($c['code'])) {
				continue;
			}
			$code = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$c['code']));
			if ($code === '') {
				continue;
			}
			$hosts = isset($c['hosts']) ? $c['hosts'] : array();
			if (is_string($hosts)) {
				$hosts = preg_split('/[\s,;]+/', $hosts, -1, PREG_SPLIT_NO_EMPTY);
			}
			$clean = array();
			foreach (is_array($hosts) ? $hosts : array() as $h) {
				$h = strtolower(trim((string)$h));
				if (strpos($h, '://') !== false) {
					$h = (string)parse_url($h, PHP_URL_HOST);
				}
				$h = preg_replace('/^www\./', '', $h);
				if ($h !== '') {
					$clean[] = $h;
				}
			}
			$type = isset($c['type']) && $c['type'] === 'speedy' ? 'speedy' : 'link';
			$aliases = isset($c['aliases']) ? $c['aliases'] : array();
			if (is_string($aliases)) {
				$aliases = preg_split('/\s*[,;\n]\s*/u', $aliases, -1, PREG_SPLIT_NO_EMPTY);
			}
			$cleanAliases = array();
			foreach (is_array($aliases) ? $aliases : array() as $a) {
				$a = trim(html_entity_decode((string)$a, ENT_QUOTES, 'UTF-8'));
				if ($a !== '' && !in_array($a, $cleanAliases, true)) {
					$cleanAliases[] = $a;
				}
			}
			$pattern = isset($c['pattern']) ? html_entity_decode(trim((string)$c['pattern']), ENT_QUOTES, 'UTF-8') : '';
			$out[$code] = array(
				'code'      => $code,
				'name'      => isset($c['name']) && trim((string)$c['name']) !== '' ? html_entity_decode(trim((string)$c['name']), ENT_QUOTES, 'UTF-8') : $code,
				'type'      => $type,
				'hosts'     => $clean,
				'track_url' => isset($c['track_url']) ? html_entity_decode(trim((string)$c['track_url']), ENT_QUOTES, 'UTF-8') : '',
				'pattern'   => $pattern,
				'regex'     => self::patternRegex($pattern),
				'aliases'   => $cleanAliases,
			);
		}

		return $out;
	}

	// ---- courier API credentials (file per courier, never in the database, never echoed)

	public static function courierFile($code) {
		$code = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$code));

		return rtrim(DIR_STORAGE, '/') . '/aichat/courier_' . $code . '.php';
	}

	/** $secret e.g. array('username' => .., 'password' => ..); an empty array deletes it. */
	public function setCourierSecret($code, array $secret) {
		$code = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$code));

		if ($code === '') {
			return false;
		}

		$this->protectDir();
		$file = self::courierFile($code);
		$clean = array();

		foreach ($secret as $k => $v) {
			if (is_scalar($v) && trim((string)$v) !== '') {
				$clean[(string)$k] = trim((string)$v);
			}
		}

		if (!$clean) {
			if (is_file($file)) {
				@unlink($file);
			}
			return true;
		}

		$php = "<?php\n// AI Chat courier credentials. Do not share.\nreturn " . var_export($clean, true) . ";\n";
		$ok = @file_put_contents($file, $php, LOCK_EX) !== false;

		if ($ok) {
			@chmod($file, 0600);
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($file, true);
			}
		}

		return $ok;
	}

	public function hasCourierSecret($code) {
		return (bool)$this->getCourierSecret($code);
	}

	/** Server-side only (courier adapters). */
	public function getCourierSecret($code) {
		$file = self::courierFile($code);

		if (!is_file($file)) {
			return array();
		}

		$data = include $file;

		return is_array($data) ? $data : array();
	}

	// ---- other secrets (Telegram bot token ...): one file each, write-only like the API key

	public static function secretFile($name) {
		$name = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)$name));

		return rtrim(DIR_STORAGE, '/') . '/aichat/secret_' . $name . '.php';
	}

	/** setSecret('tg_token', '123:abc'); an empty value deletes it. */
	public function setSecret($name, $value) {
		if (preg_replace('/[^a-z0-9_]/i', '', (string)$name) === '') {
			return false;
		}

		$this->protectDir();
		$file = self::secretFile($name);
		$value = trim((string)$value);

		if ($value === '') {
			if (is_file($file)) {
				@unlink($file);
			}
			return true;
		}

		$php = "<?php\n// AI Chat secret. Do not share.\nreturn " . var_export($value, true) . ";\n";
		$ok = @file_put_contents($file, $php, LOCK_EX) !== false;

		if ($ok) {
			@chmod($file, 0600);
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($file, true);
			}
		}

		return $ok;
	}

	/** Turnstile secret key (write-only like the API key); '' deletes it. */
	public function setTurnstileSecret($value) {
		return $this->setSecret('turnstile', $value);
	}

	public function hasTurnstileSecret() {
		return trim((string)$this->getGlobal('g_turnstile_secret')) !== '';
	}

	/**
	 * Schema 5: moves a Turnstile secret still stored as a setting into the
	 * secret file and blanks the setting. Idempotent.
	 */
	public function migrateTurnstileSecret() {
		$values = $this->load(0, 'g');
		$old = isset($values['g_turnstile_secret']) ? trim((string)$values['g_turnstile_secret']) : '';

		if ($old === '') {
			return false;
		}

		if ($this->getNamedSecret('turnstile') === '' && !$this->setSecret('turnstile', $old)) {
			return false;
		}

		$this->write(0, 'g_turnstile_secret', '');
		$this->rows = null;

		return true;
	}

	public function hasSecret($name) {
		return $this->getNamedSecret($name) !== '';
	}

	/** Server-side only. Never send this to a view or the browser. */
	public function getNamedSecret($name) {
		$file = self::secretFile($name);

		if (!is_file($file)) {
			return '';
		}

		$value = include $file;

		return is_string($value) ? trim($value) : '';
	}

	// ---- API key (file, never in the database, never echoed) ----

	public static function keyFile() {
		return rtrim(DIR_STORAGE, '/') . '/aichat/key.php';
	}

	public function setApiKey($key) {
		$key = trim((string)$key);
		$this->protectDir();

		if ($key === '') {
			if (is_file(self::keyFile())) {
				@unlink(self::keyFile());
			}
			return true;
		}

		$php = "<?php\n// AI Chat API key. Do not share.\nreturn " . var_export($key, true) . ";\n";
		$ok = @file_put_contents(self::keyFile(), $php, LOCK_EX) !== false;

		if ($ok) {
			@chmod(self::keyFile(), 0600);
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate(self::keyFile(), true);
			}
		}

		return $ok;
	}

	private function protectDir() {
		$dir = dirname(self::keyFile());

		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}

		if (!is_file($dir . '/.htaccess')) {
			@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
		}

		if (!is_file($dir . '/index.html')) {
			@file_put_contents($dir . '/index.html', '');
		}
	}

	public function hasApiKey() {
		return $this->getApiKey() !== '';
	}

	/** Server-side only (client.php). Never send this to a view or the browser. */
	public function getApiKey() {
		$file = self::keyFile();

		if (!is_file($file)) {
			return '';
		}

		$key = include $file;

		return is_string($key) ? trim($key) : '';
	}

	public function deleteAll() {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . self::CODE . "'");
		$this->rows = null;
	}

	// ---- internals ----

	private function normalise($key, $scope) {
		$key = (string)$key;

		if (strpos($key, self::CODE . '_') === 0) {
			$key = substr($key, strlen(self::CODE) + 1);
		}

		if (strpos($key, $scope . '_') !== 0) {
			$key = $scope . '_' . $key;
		}

		return $key;
	}

	private function load($storeId, $scope) {
		if ($this->rows === null) {
			$this->rows = array();
			// ordered: if a key has duplicate rows, the newest one wins (deterministic)
			$query = $this->db->query("SELECT `store_id`, `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . self::CODE . "' ORDER BY `setting_id` ASC");

			foreach ($query->rows as $row) {
				$value = $row['serialized'] ? json_decode($row['value'], true) : $row['value'];
				$this->rows[(int)$row['store_id']][$row['key']] = $value;
			}
		}

		$prefix = self::CODE . '_' . $scope . '_';
		$out = array();

		if (isset($this->rows[$storeId])) {
			foreach ($this->rows[$storeId] as $key => $value) {
				if (strpos($key, $prefix) === 0) {
					$out[$scope . '_' . substr($key, strlen($prefix))] = $value;
				}
			}
		}

		return $out;
	}

	/** DELETE + INSERT of one key under a named lock, so simultaneous writers cannot leave two rows. */
	private function write($storeId, $name, $value) {
		$lock = $this->lock($storeId . '|' . $name);

		try {
			$this->writeRaw($storeId, $name, $value);
		} finally {
			$this->unlock($lock);
		}
	}

	private function writeRaw($storeId, $name, $value) {
		$key = self::CODE . '_' . $name;

		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '" . (int)$storeId . "' AND `code` = '" . self::CODE . "' AND `key` = '" . $this->db->escape($key) . "'");

		if (is_array($value)) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '" . (int)$storeId . "', `code` = '" . self::CODE . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape(json_encode($value)) . "', `serialized` = '1'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '" . (int)$storeId . "', `code` = '" . self::CODE . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape((string)$value) . "', `serialized` = '0'");
		}
	}

	private function cast(array $values) {
		foreach ($values as $name => $value) {
			$values[$name] = $this->castOne($name, $value);
		}

		return $values;
	}

	private function castOne($name, $value) {
		$defaults = array_merge(self::globalDefaults(), self::storeDefaults());

		if (!array_key_exists($name, $defaults)) {
			return $value;
		}

		$type = gettype($defaults[$name]);

		if ($type === 'integer') {
			return (int)$value;
		}

		if ($type === 'double') {
			return (float)$value;
		}

		if ($type === 'array') {
			return is_array($value) ? $value : array();
		}

		if ($name === 's_custom_css') {
			return self::sanitizeCss(is_scalar($value) ? (string)$value : '');
		}

		return is_scalar($value) ? (string)$value : '';
	}

	// ---- custom CSS of the widget (audit I5) ----

	/** Root class of the widget; every rule is scoped below it. */
	const CSS_ROOT = '.aichat-root';

	/**
	 * Owner's custom CSS made safe for the storefront, on save and on output:
	 * no markup, comments, escapes, @import / @font-face / other at-rules
	 * (only @media, @supports, @keyframes), no external resources (url()
	 * other than data: images, image-set(), expression(), javascript:,
	 * behaviours, bindings) and every selector scoped inside the widget root
	 * (no sibling combinators on the root, no ".aichat-rootX" look-alikes).
	 */
	public static function sanitizeCss($css) {
		$css = (string)$css;

		if (trim($css) === '') {
			return '';
		}

		$css = function_exists('mb_substr') ? mb_substr($css, 0, 5000, 'UTF-8') : substr($css, 0, 5000);
		$css = preg_replace('~/\*.*?(\*/|$)~s', '', $css);       // comments
		$css = str_replace(array('<', '\\', "\r", "\0"), '', $css); // markup, CSS escapes ("u\72l(")

		return trim(self::cssBlocks($css, 0));
	}

	private static function cssBlocks($str, $depth) {
		$out = '';
		$pos = 0;
		$len = strlen($str);

		while ($pos < $len) {
			$open = strpos($str, '{', $pos);
			if ($open === false) {
				break;
			}
			$head = trim(substr($str, $pos, $open - $pos));
			$level = 1;
			$j = $open + 1;
			while ($j < $len && $level > 0) {
				if ($str[$j] === '{') {
					$level++;
				} elseif ($str[$j] === '}') {
					$level--;
				}
				$j++;
			}
			$body = substr($str, $open + 1, max(0, $j - $open - 2));
			$pos = $j;
			// a head may carry leftovers of a broken previous rule ("a:b; .x")
			if (strpos($head, ';') !== false) {
				$head = trim(substr($head, strrpos($head, ';') + 1));
			}
			if ($head === '') {
				continue;
			}

			if ($head[0] === '@') {
				if ($depth < 2 && preg_match('/^@(media|supports)\b/i', $head) && !preg_match('/url\s*\(|expression|javascript/i', $head)) {
					$inner = self::cssBlocks($body, $depth + 1);
					if ($inner !== '') {
						$out .= $head . '{' . $inner . '}';
					}
				} elseif (preg_match('/^@(-webkit-)?keyframes\s+[A-Za-z0-9_-]+$/i', $head)) {
					$frames = '';
					foreach (self::cssRules($body) as $rule) {
						if (preg_match('/^(from|to|\d{1,3}(\.\d+)?%)(\s*,\s*(from|to|\d{1,3}(\.\d+)?%))*$/i', $rule[0])) {
							$decl = self::cssDeclarations($rule[1]);
							if ($decl !== '') {
								$frames .= $rule[0] . '{' . $decl . '}';
							}
						}
					}
					if ($frames !== '') {
						$out .= $head . '{' . $frames . '}';
					}
				}
				continue;
			}

			$selectors = array();
			foreach (explode(',', $head) as $sel) {
				$sel = self::cssSelector($sel);
				if ($sel !== '') {
					$selectors[] = $sel;
				}
			}
			$decl = self::cssDeclarations($body);
			if ($selectors && $decl !== '') {
				$out .= implode(', ', $selectors) . '{' . $decl . '}';
			}
		}

		return $out;
	}

	/** Flat "selector { declarations }" pairs (keyframes). */
	private static function cssRules($str) {
		$out = array();

		if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $str, $m, PREG_SET_ORDER)) {
			foreach ($m as $r) {
				$out[] = array(trim($r[1]), $r[2]);
			}
		}

		return $out;
	}

	/** One selector scoped below the root, or '' when it cannot be made safe. */
	private static function cssSelector($sel) {
		$sel = trim(preg_replace('/\s+/', ' ', $sel));

		if ($sel === '' || preg_match('/[@{}"\'`]|url\s*\(|expression|javascript/i', $sel)) {
			return '';
		}

		// html / body / :root mean the widget root
		$sel = preg_replace('/^(:root|html|body)(?![\w-])/i', self::CSS_ROOT, $sel);

		if (strpos($sel, self::CSS_ROOT) === 0) {
			$rest = (string)substr($sel, strlen(self::CSS_ROOT));
			if ($rest !== '' && preg_match('/^[\w-]/', $rest)) {
				// ".aichat-rootX" is another element: scope it like any selector
				return self::CSS_ROOT . ' ' . $sel;
			}
			// the root's own compound (classes, pseudo, attributes) then a combinator
			$compound = preg_match('/^((?:[.#:\[][^\s>+~]*)*)/', $rest, $cm) ? $cm[1] : '';
			$after = ltrim((string)substr($rest, strlen($compound)));
			if ($after !== '' && ($after[0] === '~' || $after[0] === '+')) {
				return ''; // sibling of the root: outside the widget
			}
			if (preg_match('/:has\(|:not\(/i', $compound) && preg_match('/[~+]/', $compound)) {
				return '';
			}
			return $sel;
		}

		return self::CSS_ROOT . ' ' . $sel;
	}

	/** Splits on ";" outside quotes and parentheses (data: URLs contain ";"). */
	private static function splitDeclarations($body) {
		$parts = array();
		$cur = '';
		$depth = 0;
		$quote = '';

		for ($i = 0, $n = strlen($body); $i < $n; $i++) {
			$ch = $body[$i];
			if ($quote !== '') {
				if ($ch === $quote) {
					$quote = '';
				}
			} elseif ($ch === '"' || $ch === "'") {
				$quote = $ch;
			} elseif ($ch === '(') {
				$depth++;
			} elseif ($ch === ')') {
				$depth = max(0, $depth - 1);
			} elseif ($ch === ';' && $depth === 0) {
				$parts[] = $cur;
				$cur = '';
				continue;
			}
			$cur .= $ch;
		}

		$parts[] = $cur;

		return $parts;
	}

	/** Declarations without external resources or script-like values. */
	private static function cssDeclarations($body) {
		$out = array();

		foreach (self::splitDeclarations($body) as $decl) {
			$decl = trim($decl);
			if ($decl === '' || strpos($decl, ':') === false || preg_match('/[{}]/', $decl)) {
				continue;
			}
			if (preg_match('/expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding|@import|image-set\s*\(|\bsrc\s*:/i', $decl)) {
				continue;
			}
			if (preg_match_all('/url\s*\(\s*([^)]*)\)/i', $decl, $m)) {
				$ok = true;
				foreach ($m[1] as $u) {
					if (!preg_match('~^["\']?data:image/(png|jpe?g|gif|webp|svg\+xml)[;,]~i', trim($u))) {
						$ok = false;
					}
				}
				if (!$ok) {
					continue;
				}
			}
			$out[] = $decl;
		}

		return implode(';', $out);
	}
}
