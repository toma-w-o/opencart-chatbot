<?php
namespace Aichat;

/**
 * Tables + events. All methods are idempotent and static (call as
 * \Aichat\Installer::install($this->registry) from the admin controller).
 */
class Installer {
	const EVENT_CODE = 'aichat';
	const VERSION = '1.0.0';

	public static function install($registry) {
		self::createTables($registry->get('db'));

		$settings = new Settings($registry);
		$settings->ensureSecret();

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

		return true;
	}

	/** Same as install(); safe to run on every admin save after an update. */
	public static function upgrade($registry) {
		return self::install($registry);
	}

	/**
	 * @param bool $dropData false keeps conversations, settings and the key file
	 */
	public static function uninstall($registry, $dropData = true) {
		self::unregisterEvents($registry);

		if ($dropData) {
			$db = $registry->get('db');
			foreach (array('aichat_conversation', 'aichat_message', 'aichat_usage', 'aichat_rate') as $table) {
				$db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . $table . "`");
			}

			$settings = new Settings($registry);
			$settings->deleteAll();
			$settings->setApiKey('');
		}

		return true;
	}

	public static function registerEvents($registry) {
		$model = self::eventModel($registry);

		if ($model) {
			$model->deleteEventByCode(self::EVENT_CODE);
			$model->addEvent(self::EVENT_CODE, 'catalog/view/common/footer/after', 'extension/module/aichat/footer');
		} else {
			// Fallback without the admin model (e.g. CLI / catalog context)
			$db = $registry->get('db');
			$db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = '" . self::EVENT_CODE . "'");
			$db->query("INSERT INTO `" . DB_PREFIX . "event` SET `code` = '" . self::EVENT_CODE . "', `trigger` = 'catalog/view/common/footer/after', `action` = 'extension/module/aichat/footer', `status` = '1', `sort_order` = '0'");
		}
	}

	public static function unregisterEvents($registry) {
		$model = self::eventModel($registry);

		if ($model) {
			$model->deleteEventByCode(self::EVENT_CODE);
		} else {
			$registry->get('db')->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = '" . self::EVENT_CODE . "'");
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
			`first_url` VARCHAR(1000) NOT NULL DEFAULT '',
			`last_url` VARCHAR(1000) NOT NULL DEFAULT '',
			`message_count` INT(11) NOT NULL DEFAULT '0',
			`input_tokens` INT(11) NOT NULL DEFAULT '0',
			`output_tokens` INT(11) NOT NULL DEFAULT '0',
			`cost` DECIMAL(14,6) NOT NULL DEFAULT '0.000000',
			`flagged` TINYINT(1) NOT NULL DEFAULT '0',
			`note` TEXT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`conversation_id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `store_date` (`store_id`, `date_added`),
			KEY `customer_id` (`customer_id`),
			KEY `mode` (`mode`)
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
