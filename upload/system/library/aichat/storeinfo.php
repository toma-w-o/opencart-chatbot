<?php
namespace Aichat;

/**
 * One place for "which store is this": name, e-mail, URL and mail settings
 * of a store, read from the database (works in the catalog, the admin, the
 * Telegram webhook and the weekly report alike).
 *
 * Name: s_store_name (module setting, optional) -> the store's own
 * config_name -> oc_store.name -> the host of the store URL without "www.".
 * Never empty.
 */
class StoreInfo {
	private static $cache = array();

	/** Mail keys OpenCart 3 reads when it sends (catalog/controller/mail/*). */
	public static function mailKeys() {
		return array('config_mail_engine', 'config_mail_parameter', 'config_mail_smtp_hostname', 'config_mail_smtp_username', 'config_mail_smtp_password', 'config_mail_smtp_port', 'config_mail_smtp_timeout');
	}

	/**
	 * @return array {name, email, url, host, mail: {config_mail_engine, ...}}
	 */
	public static function get($registry, $storeId, Settings $settings = null) {
		$storeId = (int)$storeId;
		$db = $registry->get('db');
		$keys = array_merge(array('config_email', 'config_name', 'config_url', 'config_ssl'), self::mailKeys());
		$in = array();
		foreach ($keys as $k) {
			$in[] = "'" . $db->escape($k) . "'";
		}

		// store 0 first, the store's own rows override (exactly how catalog/controller/startup/startup.php loads them)
		$rows = $db->query("SELECT `store_id`, `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` IN ('0', '" . $storeId . "') AND `code` = 'config' AND `key` IN (" . implode(',', $in) . ") ORDER BY `store_id` ASC")->rows;
		$merged = array();
		$own = array();

		foreach ($rows as $row) {
			$merged[$row['key']] = (string)$row['value'];
			if ((int)$row['store_id'] === $storeId) {
				$own[$row['key']] = (string)$row['value'];
			}
		}

		$store = null;
		if ($storeId > 0) {
			$store = $db->query("SELECT `name`, `url`, `ssl` FROM `" . DB_PREFIX . "store` WHERE `store_id` = '" . $storeId . "'")->row;
		}

		$decode = function ($v) {
			return trim(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));
		};

		// URL: own config_url, else oc_store.url; store 0: config_url / HTTP_CATALOG / HTTP_SERVER
		$url = isset($own['config_url']) ? $decode($own['config_url']) : '';
		if ($url === '' && $store) {
			$url = (string)$store['url'];
		}
		if ($url === '' && $storeId === 0) {
			$url = defined('HTTP_CATALOG') ? HTTP_CATALOG : (defined('HTTP_SERVER') ? HTTP_SERVER : '');
		}
		$host = preg_replace('/^www\./i', '', strtolower((string)parse_url($url, PHP_URL_HOST)));

		$candidates = array();
		if ($settings) {
			$candidates[] = $decode($settings->getStore($storeId, 's_store_name'));
		}
		$candidates[] = isset($own['config_name']) ? $decode($own['config_name']) : '';
		$candidates[] = $store ? $decode($store['name']) : '';
		$candidates[] = $host;

		$name = '';
		foreach ($candidates as $c) {
			if ($c !== '') {
				$name = $c;
				break;
			}
		}
		if ($name === '') {
			$name = 'Store ' . $storeId;
		}

		$mail = array();
		foreach (self::mailKeys() as $k) {
			$mail[$k] = isset($merged[$k]) ? (string)$merged[$k] : '';
		}

		return array(
			'name'  => $name,
			'email' => isset($merged['config_email']) ? $decode($merged['config_email']) : '',
			'url'   => $url,
			'host'  => $host,
			'mail'  => $mail,
		);
	}

	/** Display name of a store (never empty). */
	public static function name($registry, $storeId, Settings $settings = null) {
		$info = self::get($registry, $storeId, $settings ? $settings : new Settings($registry));

		return $info['name'];
	}

	/**
	 * Recipient of request e-mails: s_notify_email -> the store's config_email.
	 * @return string '' when neither is a valid address
	 */
	public static function notifyEmail($registry, Settings $settings, $storeId) {
		$explicit = trim(html_entity_decode((string)$settings->getStore($storeId, 's_notify_email'), ENT_QUOTES, 'UTF-8'));

		if ($explicit !== '' && filter_var($explicit, FILTER_VALIDATE_EMAIL)) {
			return $explicit;
		}

		$info = self::get($registry, $storeId, $settings);

		return filter_var($info['email'], FILTER_VALIDATE_EMAIL) ? $info['email'] : '';
	}
}
