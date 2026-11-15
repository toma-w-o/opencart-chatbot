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
			'g_retention_months'     => 12,
			'g_image_retention_days' => 30,
		);
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
		);
	}

	/**
	 * getGlobal() -> whole array (secret excluded); getGlobal('g_model', $default) -> one value.
	 */
	public function getGlobal($key = null, $default = null) {
		$values = $this->load(0, 'g');

		if ($key === null) {
			$all = array_merge(self::globalDefaults(), $values);
			unset($all['g_secret']);
			return $this->cast($all);
		}

		$name = $this->normalise($key, 'g');

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
		unset($allowed['g_secret']);

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
			$secret = bin2hex(random_bytes(32));
			$this->write(0, 'g_secret', $secret);
			$this->rows = null;
		}

		return $secret;
	}

	public function ensureSecret() {
		return $this->getSecret();
	}

	// ---- API key (file, never in the database, never echoed) ----

	public static function keyFile() {
		return rtrim(DIR_STORAGE, '/') . '/aichat/key.php';
	}

	public function setApiKey($key) {
		$key = trim((string)$key);
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
			$query = $this->db->query("SELECT `store_id`, `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . self::CODE . "'");

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

	private function write($storeId, $name, $value) {
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

		return is_scalar($value) ? (string)$value : '';
	}
}
