<?php
namespace Aichat;

/**
 * Tables + events. All methods are idempotent and static (call as
 * \Aichat\Installer::install($this->registry) from the admin controller).
 */
class Installer {
	const EVENT_CODE = 'aichat';
	const VERSION = '1.2.0';
	/** Bump when a step is added to migrate(). */
	const SCHEMA = 3;

	/** Event list: trigger => action (all under code EVENT_CODE). */
	public static function events() {
		return array(
			'catalog/view/common/footer/after'     => 'extension/module/aichat/footer',
			'admin/view/common/column_left/before' => 'extension/module/aichat/menu',
		);
	}

	public static function install($registry) {
		$db = $registry->get('db');
		self::createTables($db);
		self::migrate($db, 0);

		$settings = new Settings($registry);
		$settings->ensureSecret();
		$settings->telegramSecret();

		// Defaults for the global keys that are not set yet (never overwrite)
		$missing = array();
		foreach (Settings::globalDefaults() as $key => $value) {
			if ($key !== 'g_secret' && self::isUnset($registry, 0, 'module_aichat_' . $key)) {
				$missing[$key] = $value;
			}
		}
		if ($missing) {
			$settings->saveGlobal($missing);
		}

		self::registerEvents($registry);
		$settings->setInternal('g_schema', self::SCHEMA);

		return true;
	}

	/**
	 * Idempotent, versioned (g_schema). Cheap when up to date (one settings
	 * read), so the admin pages can call it on every load. Returns true when
	 * something was upgraded.
	 */
	public static function upgrade($registry) {
		$settings = new Settings($registry);

		if ((int)$settings->getGlobal('g_schema') >= self::SCHEMA && self::eventsPresent($registry)) {
			return false;
		}

		self::install($registry);

		return true;
	}

	/** Schema steps after the CREATE TABLEs; every step checks before it changes. */
	private static function migrate($db, $from) {
		// 2: Turnstile verification remembered per conversation (0 no, 1 verified, 2 Turnstile was down)
		self::addColumn($db, 'aichat_conversation', 'human_verified', "TINYINT(1) NOT NULL DEFAULT '0' AFTER `order_attempts`");
		self::addIndex($db, 'aichat_conversation', 'date_modified', '`date_modified`');

		// 3: handover, test chats, ratings (new tables are created by createTables())
		self::addColumn($db, 'aichat_conversation', 'tg_chat_id', "VARCHAR(32) NOT NULL DEFAULT '' AFTER `note`");
		self::addColumn($db, 'aichat_conversation', 'tg_topic_id', "INT(11) NOT NULL DEFAULT '0' AFTER `tg_chat_id`");
		self::addColumn($db, 'aichat_conversation', 'staff_name', "VARCHAR(64) NOT NULL DEFAULT '' AFTER `tg_topic_id`");
		self::addColumn($db, 'aichat_conversation', 'waiting_since', "DATETIME NULL DEFAULT NULL AFTER `staff_name`");
		self::addColumn($db, 'aichat_conversation', 'is_test', "TINYINT(1) NOT NULL DEFAULT '0' AFTER `waiting_since`");
		self::addIndex($db, 'aichat_conversation', 'tg_topic', '`tg_chat_id`, `tg_topic_id`');
		self::addColumn($db, 'aichat_message', 'rating', "TINYINT(1) NOT NULL DEFAULT '0' AFTER `cost`");
	}

	/** Tables of the module (uninstall drops them). */
	public static function tables() {
		return array('aichat_conversation', 'aichat_message', 'aichat_usage', 'aichat_rate', 'aichat_upload', 'aichat_kb', 'aichat_request', 'aichat_search_miss', 'aichat_preview');
	}

	public static function addColumn($db, $table, $column, $definition) {
		$query = $db->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` LIKE '" . $db->escape($column) . "'");

		if (!$query->num_rows) {
			$db->query("ALTER TABLE `" . DB_PREFIX . $table . "` ADD `" . $column . "` " . $definition);
			return true;
		}

		return false;
	}

	private static function addIndex($db, $table, $name, $columns) {
		$query = $db->query("SHOW INDEX FROM `" . DB_PREFIX . $table . "` WHERE `Key_name` = '" . $db->escape($name) . "'");

		if (!$query->num_rows) {
			$db->query("ALTER TABLE `" . DB_PREFIX . $table . "` ADD INDEX `" . $name . "` (" . $columns . ")");
		}
	}

	private static function eventsPresent($registry) {
		$db = $registry->get('db');
		foreach (self::events() as $trigger => $action) {
			$n = (int)$db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "event` WHERE `trigger` = '" . $db->escape($trigger) . "' AND `action` = '" . $db->escape($action) . "'")->row['n'];
			if ($n !== 1) {
				return false; // missing or duplicated
			}
		}

		return true;
	}

	/**
	 * @param bool $dropData false keeps conversations, settings and the key file
	 */
	public static function uninstall($registry, $dropData = true) {
		self::unregisterEvents($registry);

		if ($dropData) {
			$db = $registry->get('db');
			foreach (self::tables() as $table) {
				$db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . $table . "`");
			}
			Uploads::deleteAllFiles();

			$settings = new Settings($registry);
			$settings->deleteAll();
			$settings->setApiKey('');
			foreach (array_merge((array)glob(dirname(Settings::keyFile()) . '/courier_*.php'), (array)glob(dirname(Settings::keyFile()) . '/secret_*.php')) as $file) {
				@unlink($file);
			}
		}

		return true;
	}

	/**
	 * (Re)registers our events. Rows with the same trigger + action under any
	 * code (e.g. "aichat_menu" added by the admin controller) are removed
	 * first, so there is never a duplicate.
	 */
	public static function registerEvents($registry) {
		self::unregisterEvents($registry);
		$model = self::eventModel($registry);
		$db = $registry->get('db');

		foreach (self::events() as $trigger => $action) {
			if ($model) {
				$model->addEvent(self::EVENT_CODE, $trigger, $action);
			} else {
				// Fallback without the admin model (e.g. CLI / catalog context)
				$db->query("INSERT INTO `" . DB_PREFIX . "event` SET `code` = '" . self::EVENT_CODE . "', `trigger` = '" . $db->escape($trigger) . "', `action` = '" . $db->escape($action) . "', `status` = '1', `sort_order` = '0'");
			}
		}
	}

	public static function unregisterEvents($registry) {
		$db = $registry->get('db');
		$db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = '" . self::EVENT_CODE . "'");

		foreach (self::events() as $trigger => $action) {
			$db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `trigger` = '" . $db->escape($trigger) . "' AND `action` = '" . $db->escape($action) . "'");
		}
	}

	public static function createTables($db) {
		$charset = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_conversation` (
			`conversation_id` INT(11) NOT NULL AUTO_INCREMENT,
			`store_id` INT(11) NOT NULL DEFAULT '0',
			`language_id` INT(11) NOT NULL DEFAULT '0',
			`customer_id` INT(11) NOT NULL DEFAULT '0',
			`public_id` VARCHAR(32) NOT NULL,
			`ip` VARCHAR(45) NOT NULL DEFAULT '',
			`user_agent` VARCHAR(255) NOT NULL DEFAULT '',
			`mode` VARCHAR(16) NOT NULL DEFAULT 'bot',
			`verified_order_id` INT(11) NOT NULL DEFAULT '0',
			`order_attempts` INT(11) NOT NULL DEFAULT '0',
			`human_verified` TINYINT(1) NOT NULL DEFAULT '0',
			`first_url` VARCHAR(1000) NOT NULL DEFAULT '',
			`last_url` VARCHAR(1000) NOT NULL DEFAULT '',
			`message_count` INT(11) NOT NULL DEFAULT '0',
			`input_tokens` INT(11) NOT NULL DEFAULT '0',
			`output_tokens` INT(11) NOT NULL DEFAULT '0',
			`cost` DECIMAL(14,6) NOT NULL DEFAULT '0.000000',
			`flagged` TINYINT(1) NOT NULL DEFAULT '0',
			`note` TEXT NULL,
			`tg_chat_id` VARCHAR(32) NOT NULL DEFAULT '',
			`tg_topic_id` INT(11) NOT NULL DEFAULT '0',
			`staff_name` VARCHAR(64) NOT NULL DEFAULT '',
			`waiting_since` DATETIME NULL DEFAULT NULL,
			`is_test` TINYINT(1) NOT NULL DEFAULT '0',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`conversation_id`),
			KEY `tg_topic` (`tg_chat_id`, `tg_topic_id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `store_date` (`store_id`, `date_added`),
			KEY `customer_id` (`customer_id`),
			KEY `mode` (`mode`),
			KEY `date_modified` (`date_modified`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_message` (
			`message_id` INT(11) NOT NULL AUTO_INCREMENT,
			`conversation_id` INT(11) NOT NULL,
			`role` VARCHAR(16) NOT NULL,
			`content` MEDIUMTEXT NOT NULL,
			`meta` MEDIUMTEXT NULL,
			`input_tokens` INT(11) NOT NULL DEFAULT '0',
			`output_tokens` INT(11) NOT NULL DEFAULT '0',
			`cost` DECIMAL(14,6) NOT NULL DEFAULT '0.000000',
			`rating` TINYINT(1) NOT NULL DEFAULT '0',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`message_id`),
			KEY `conversation_id` (`conversation_id`, `message_id`),
			KEY `date_added` (`date_added`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_usage` (
			`store_id` INT(11) NOT NULL,
			`day` DATE NOT NULL,
			`chats` INT(11) NOT NULL DEFAULT '0',
			`messages` INT(11) NOT NULL DEFAULT '0',
			`input_tokens` BIGINT(20) NOT NULL DEFAULT '0',
			`output_tokens` BIGINT(20) NOT NULL DEFAULT '0',
			`cost` DECIMAL(14,6) NOT NULL DEFAULT '0.000000',
			PRIMARY KEY (`store_id`, `day`),
			KEY `day` (`day`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_rate` (
			`rate_key` VARCHAR(64) NOT NULL,
			`window_start` INT(11) NOT NULL,
			`hits` INT(11) NOT NULL DEFAULT '0',
			PRIMARY KEY (`rate_key`),
			KEY `window_start` (`window_start`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_upload` (
			`upload_id` INT(11) NOT NULL AUTO_INCREMENT,
			`conversation_id` INT(11) NOT NULL,
			`file` VARCHAR(64) NOT NULL,
			`mime` VARCHAR(32) NOT NULL DEFAULT 'image/jpeg',
			`bytes` INT(11) NOT NULL DEFAULT '0',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`upload_id`),
			KEY `conversation_id` (`conversation_id`),
			KEY `date_added` (`date_added`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_kb` (
			`kb_id` INT(11) NOT NULL AUTO_INCREMENT,
			`store_id` INT(11) NOT NULL DEFAULT '-1',
			`language_id` INT(11) NOT NULL DEFAULT '0',
			`question` TEXT NOT NULL,
			`answer` TEXT NOT NULL,
			`status` TINYINT(1) NOT NULL DEFAULT '1',
			`source_message_id` INT(11) NOT NULL DEFAULT '0',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`kb_id`),
			KEY `store_lang` (`store_id`, `language_id`, `status`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_request` (
			`request_id` INT(11) NOT NULL AUTO_INCREMENT,
			`store_id` INT(11) NOT NULL DEFAULT '0',
			`conversation_id` INT(11) NOT NULL DEFAULT '0',
			`type` VARCHAR(16) NOT NULL,
			`order_id` INT(11) NOT NULL DEFAULT '0',
			`product_id` INT(11) NOT NULL DEFAULT '0',
			`email` VARCHAR(96) NOT NULL DEFAULT '',
			`phone` VARCHAR(32) NOT NULL DEFAULT '',
			`data` MEDIUMTEXT NULL,
			`status` VARCHAR(8) NOT NULL DEFAULT 'new',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`request_id`),
			KEY `store_status` (`store_id`, `status`, `date_added`),
			KEY `conversation_id` (`conversation_id`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_search_miss` (
			`miss_id` INT(11) NOT NULL AUTO_INCREMENT,
			`store_id` INT(11) NOT NULL DEFAULT '0',
			`language_id` INT(11) NOT NULL DEFAULT '0',
			`query` VARCHAR(191) NOT NULL,
			`hits` INT(11) NOT NULL DEFAULT '1',
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`miss_id`),
			UNIQUE KEY `store_lang_query` (`store_id`, `language_id`, `query`),
			KEY `date_modified` (`date_modified`)
		) " . $charset);

		$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aichat_preview` (
			`preview_id` INT(11) NOT NULL AUTO_INCREMENT,
			`store_id` INT(11) NOT NULL DEFAULT '0',
			`url` VARCHAR(1000) NOT NULL DEFAULT '',
			`data` MEDIUMTEXT NULL,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`preview_id`),
			KEY `date_added` (`date_added`)
		) " . $charset);
	}

	private static function eventModel($registry) {
		$load = $registry->get('load');

		if (!$load || !defined('DIR_APPLICATION') || !is_file(DIR_APPLICATION . 'model/setting/event.php')) {
			return null;
		}

		$load->model('setting/event');
		$model = $registry->get('model_setting_event');

		if ($model && method_exists($model, 'addEvent') && method_exists($model, 'deleteEventByCode')) {
			return $model;
		}

		return null;
	}

	private static function isUnset($registry, $storeId, $key) {
		$query = $registry->get('db')->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '" . (int)$storeId . "' AND `code` = 'module_aichat' AND `key` = '" . $registry->get('db')->escape($key) . "'");

		return !$query->num_rows;
	}
}
