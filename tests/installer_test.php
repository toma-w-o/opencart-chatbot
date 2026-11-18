<?php
/**
 * Installer::upgrade() against the test database with OpenCart's own DB driver:
 * simulates an install from round 1 (no human_verified column, no admin menu
 * event, g_schema unset), upgrades, checks, then upgrades again (no-op).
 *
 *   php tests/installer_test.php <oc dir> <db host> <user> <pass> <db> <prefix>
 */
list(, $oc, $host, $user, $pass, $name, $prefix) = $argv + array_fill(0, 7, '');
error_reporting(E_ALL & ~E_DEPRECATED);
define('DB_PREFIX', $prefix);
define('DIR_STORAGE', rtrim($oc, '/') . '/system/storage/');
require $oc . '/system/library/db.php';
require $oc . '/system/library/db/mysqli.php';
spl_autoload_register(function ($class) use ($oc) {
	$file = $oc . '/system/library/' . str_replace('\\', '/', strtolower($class)) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

class TestRegistry {
	private $data = array();
	public function get($k) {
		return isset($this->data[$k]) ? $this->data[$k] : null;
	}
	public function set($k, $v) {
		$this->data[$k] = $v;
	}
}

$registry = new TestRegistry();
$db = new DB('mysqli', $host, $user, $pass, $name, 3306);
$registry->set('db', $db);
$fails = 0;
function check($label, $ok, $detail = "") {
	global $fails;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
	$fails += $ok ? 0 : 1;
}
function events($db) {
	return $db->query("SELECT `trigger`, `action` FROM `" . DB_PREFIX . "event` WHERE `code` = 'aichat' ORDER BY `trigger`")->rows;
}

// round-1 state
if ($db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "aichat_conversation` LIKE 'human_verified'")->num_rows) {
	$db->query("ALTER TABLE `" . DB_PREFIX . "aichat_conversation` DROP `human_verified`");
}
$db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = 'aichat' AND `trigger` LIKE 'admin/%'");
$db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `key` IN ('module_aichat_g_schema', 'module_aichat_g_couriers')");
$before = count(events($db));
$secret = $db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `key` = 'module_aichat_g_secret'")->row;

$did = \Aichat\Installer::upgrade($registry);
check('upgrade from round 1 runs', $did === true && $before === 1);
check('human_verified column added', (bool)$db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "aichat_conversation` LIKE 'human_verified'")->num_rows);
$ev = events($db);
check('events: footer + admin menu', count($ev) === 2 && $ev[0]['trigger'] === 'admin/view/common/column_left/before' && $ev[0]['action'] === 'extension/module/aichat/menu' && $ev[1]['trigger'] === 'catalog/view/common/footer/after');
$settings = new \Aichat\Settings($registry);
check('g_schema = ' . \Aichat\Installer::SCHEMA, (int)$settings->getGlobal('g_schema') === \Aichat\Installer::SCHEMA);
$couriers = $settings->couriers();
check('g_couriers seeded with Speedy', isset($couriers['speedy']) && $couriers['speedy']['hosts'] === array('speedy.bg') && strpos($couriers['speedy']['track_url'], '{number}') !== false);
$secret2 = $db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `key` = 'module_aichat_g_secret'")->row;
check('HMAC secret kept', $secret && $secret['value'] === $secret2['value']);
check('second upgrade is a no-op', \Aichat\Installer::upgrade($registry) === false && count(events($db)) === 2);
check('install() again is idempotent', \Aichat\Installer::install($registry) === true && count(events($db)) === 2);
// admin controller's own menu row (code aichat_menu) must not end up duplicated
$db->query("INSERT INTO `" . DB_PREFIX . "event` SET `code` = 'aichat_menu', `trigger` = 'admin/view/common/column_left/before', `action` = 'extension/module/aichat/menu', `status` = '1', `sort_order` = '0'");
\Aichat\Installer::upgrade($registry);
$n = $db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "event` WHERE `action` = 'extension/module/aichat/menu'")->row['n'];
check('menu event never duplicated (admin aichat_menu row)', (int)$n === 1);

// ---- schema 2 state (round 2 install): no round 3 tables / columns, g_schema 2
$p = DB_PREFIX;
foreach (array('aichat_upload', 'aichat_kb', 'aichat_request', 'aichat_search_miss', 'aichat_preview') as $t) {
	$db->query("DROP TABLE IF EXISTS `" . $p . $t . "`");
}
if ($db->query("SHOW INDEX FROM `" . $p . "aichat_conversation` WHERE `Key_name` = 'tg_topic'")->num_rows) {
	$db->query("ALTER TABLE `" . $p . "aichat_conversation` DROP INDEX `tg_topic`");
}
foreach (array('tg_chat_id', 'tg_topic_id', 'staff_name', 'waiting_since', 'is_test') as $col) {
	if ($db->query("SHOW COLUMNS FROM `" . $p . "aichat_conversation` LIKE '" . $col . "'")->num_rows) {
		$db->query("ALTER TABLE `" . $p . "aichat_conversation` DROP `" . $col . "`");
	}
}
if ($db->query("SHOW COLUMNS FROM `" . $p . "aichat_message` LIKE 'rating'")->num_rows) {
	$db->query("ALTER TABLE `" . $p . "aichat_message` DROP `rating`");
}
$db->query("UPDATE `" . $p . "setting` SET `value` = '2' WHERE `key` = 'module_aichat_g_schema'");
$db->query("DELETE FROM `" . $p . "setting` WHERE `key` IN ('module_aichat_g_tg_secret', 'module_aichat_g_model_light')");
$conversations = (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_conversation`")->row['n'];
$settings = new \Aichat\Settings($registry);
check('schema 2 state prepared', (int)$settings->getGlobal('g_schema') === 2);
check('upgrade from schema 2 runs', \Aichat\Installer::upgrade($registry) === true);
$tables = array();
foreach (array('aichat_upload', 'aichat_kb', 'aichat_request', 'aichat_search_miss', 'aichat_preview') as $t) {
	$tables[] = (bool)$db->query("SHOW TABLES LIKE '" . $p . $t . "'")->num_rows;
}
check('schema 3 tables created', !in_array(false, $tables, true));
$cols = array();
foreach (array('tg_chat_id', 'tg_topic_id', 'staff_name', 'waiting_since', 'is_test') as $col) {
	$cols[] = (bool)$db->query("SHOW COLUMNS FROM `" . $p . "aichat_conversation` LIKE '" . $col . "'")->num_rows;
}
check('schema 3 conversation columns + message.rating', !in_array(false, $cols, true) && $db->query("SHOW COLUMNS FROM `" . $p . "aichat_message` LIKE 'rating'")->num_rows === 1);
check('search_miss unique key (store, language, query)', (bool)$db->query("SHOW INDEX FROM `" . $p . "aichat_search_miss` WHERE `Key_name` = 'store_lang_query'")->num_rows);
check('utf8mb4 tables', stripos($db->query("SHOW CREATE TABLE `" . $p . "aichat_kb`")->row['Create Table'], 'utf8mb4') !== false);
$settings = new \Aichat\Settings($registry);
check('g_schema current, g_tg_secret generated, g_model_light default', (int)$settings->getGlobal('g_schema') === \Aichat\Installer::SCHEMA && preg_match('/^[a-f0-9]{48}$/', (string)$settings->getGlobal('g_tg_secret')) && $settings->getGlobal('g_model_light') === 'claude-haiku-5-5');
check('conversations kept', (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_conversation`")->row['n'] === $conversations);
check('schema 3 upgrade again is a no-op', \Aichat\Installer::upgrade($registry) === false);
\Aichat\Installer::install($registry);
check('install() over schema 3 idempotent', (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_conversation`")->row['n'] === $conversations);

// round 3 store defaults: every key the admin saves must exist (saveStore drops unknown keys)
$defaults = \Aichat\Settings::storeDefaults();
$missing = array();
foreach (array('s_couriers', 's_courier', 's_handover_timeout', 's_tg_chat_id', 's_proactive', 's_proactive_delay', 's_proactive_text', 's_sound', 's_feat_images', 's_feat_rating', 's_feat_cart') as $k) {
	if (!array_key_exists($k, $defaults)) {
		$missing[] = $k;
	}
}
$g = \Aichat\Settings::globalDefaults();
foreach (array('g_model_light', 'g_tg_chat_id', 'g_tg_translate', 'g_tg_staff_lang', 'g_tg_canned', 'g_report_weekly', 'g_report_email') as $k) {
	if (!array_key_exists($k, $g)) {
		$missing[] = $k;
	}
}
check('round 3 setting keys with defaults', !$missing && $defaults['s_handover_timeout'] === 60 && $defaults['s_proactive_delay'] === 20 && $defaults['s_sound'] === 1, implode(',', $missing));
$settings->saveGlobal(array('g_tg_secret' => 'hijack', 'g_report_week' => 'x'));
check('internal keys not writable through saveGlobal', $settings->getGlobal('g_tg_secret') !== 'hijack' && $settings->getGlobal('g_report_week') !== 'x');

// Telegram token: write-only secret file
$settings->setSecret('tg_token', '123:abcdefghijklmnopqrstuvwxyz');
check('setSecret / hasSecret (tg_token), not in getGlobal()', $settings->hasSecret('tg_token') && !$settings->hasSecret('other') && strpos(json_encode($settings->getGlobal()), 'abcdefghijklmnop') === false);
$settings->setSecret('tg_token', '');
check('secret removed', !$settings->hasSecret('tg_token'));

// courier secret file: write-only storage
$settings->setCourierSecret('selftest', array('username' => 'u', 'password' => 'p'));
check('courier secret stored', $settings->hasCourierSecret('selftest') && !$settings->hasCourierSecret('none') && strpos(json_encode($settings->getGlobal()), '"p"') === false);
$settings->setCourierSecret('selftest', array());
check('courier secret removed', !$settings->hasCourierSecret('selftest'));

// ---- schema 3 state (1.0.x install) -> 4: aichat_request.mailed, courier pattern / aliases, NextLevel seed
if ($db->query("SHOW COLUMNS FROM `" . $p . "aichat_request` LIKE 'mailed'")->num_rows) {
	$db->query("ALTER TABLE `" . $p . "aichat_request` DROP `mailed`");
}
$db->query("UPDATE `" . $p . "setting` SET `value` = '3' WHERE `key` = 'module_aichat_g_schema'");
$old = array(
	array('code' => 'speedy', 'name' => 'Speedy', 'type' => 'speedy', 'hosts' => array('speedy.bg'), 'track_url' => 'https://www.speedy.bg/bg/track-shipment?shipmentNumber={number}'),
	array('code' => 'econt', 'name' => 'Econt', 'type' => 'link', 'hosts' => array('econt.com'), 'track_url' => 'https://www.econt.com/services/track-shipment/{number}'),
);
$db->query("UPDATE `" . $p . "setting` SET `value` = '" . $db->escape(json_encode($old)) . "' WHERE `key` = 'module_aichat_g_couriers'");
$requests = (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_request`")->row['n'];
$settings = new \Aichat\Settings($registry);
check('upgrade from schema 3 runs', \Aichat\Installer::upgrade($registry) === true);
check('aichat_request.mailed added (NULL = not attempted)', (bool)$db->query("SHOW COLUMNS FROM `" . $p . "aichat_request` WHERE `Field` = 'mailed' AND `Null` = 'YES'")->num_rows);
$settings = new \Aichat\Settings($registry);
$c = $settings->couriers();
check('Speedy row gets the seed pattern + aliases, other rows untouched', $c['speedy']['pattern'] === '^6\\d{10}$' && in_array('Спиди', $c['speedy']['aliases'], true) && $c['econt']['pattern'] === '' && $c['econt']['track_url'] === 'https://www.econt.com/services/track-shipment/{number}', json_encode($c, JSON_UNESCAPED_UNICODE));
check('NextLevel row seeded', isset($c['nextlevel']) && $c['nextlevel']['type'] === 'link' && $c['nextlevel']['hosts'] === array('nextlevel.delivery') && $c['nextlevel']['track_url'] === 'https://nextlevel.delivery/bg/track?awb={number}' && $c['nextlevel']['pattern'] === '^1000\\d{9}$');
check('g_schema = ' . \Aichat\Installer::SCHEMA . ', requests kept', (int)$settings->getGlobal('g_schema') === \Aichat\Installer::SCHEMA && (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_request`")->row['n'] === $requests);
check('schema 4 upgrade again is a no-op', \Aichat\Installer::upgrade($registry) === false && count($settings->couriers()) === 3);
// the owner deletes NextLevel: install() over schema 4 does not bring it back
$settings->saveGlobal(array('g_couriers' => $old));
\Aichat\Installer::install($registry);
$settings = new \Aichat\Settings($registry);
check('deleted NextLevel row stays deleted after install() over schema 4', !isset($settings->couriers()['nextlevel']));
$settings->saveGlobal(array('g_couriers' => \Aichat\Settings::defaultCouriers()));
$d4 = \Aichat\Settings::storeDefaults();
check('round 4 store keys with defaults', $d4['s_store_name'] === '' && $d4['s_notify_email'] === '' && $d4['s_attention'] === 1 && $d4['s_attention_interval'] === 5);

// ---- schema 6 state (1.2.0 install) -> 7: GDPR tables / columns, personal-data permission for the Administrator group
$pd = \Aichat\Installer::PD_PERMISSION;
$adminGroup = function () use ($db, $p) {
	$row = $db->query("SELECT `user_group_id`, `permission` FROM `" . $p . "user_group` WHERE `name` = 'Administrator' ORDER BY `user_group_id` LIMIT 1")->row;
	$perm = $row ? json_decode($row['permission'], true) : array();
	return array($row ? (int)$row['user_group_id'] : 0, is_array($perm) ? $perm : array());
};
$setPd = function ($on) use ($db, $p, $adminGroup, $pd) {
	list($id, $perm) = $adminGroup();
	foreach (array('access', 'modify') as $type) {
		$list = isset($perm[$type]) ? array_values(array_diff($perm[$type], array($pd))) : array();
		if ($on) {
			$list[] = $pd;
		}
		$perm[$type] = $list;
	}
	$db->query("UPDATE `" . $p . "user_group` SET `permission` = '" . $db->escape(json_encode($perm)) . "' WHERE `user_group_id` = '" . $id . "'");
};
foreach (array('aichat_dsr', 'aichat_access_log') as $t) {
	$db->query("DROP TABLE IF EXISTS `" . $p . $t . "`");
}
foreach (array('tg_done', 'date_added') as $idx) {
	if ($db->query("SHOW INDEX FROM `" . $p . "aichat_conversation` WHERE `Key_name` = '" . $idx . "'")->num_rows) {
		$db->query("ALTER TABLE `" . $p . "aichat_conversation` DROP INDEX `" . $idx . "`");
	}
}
foreach (array('restricted', 'privacy_accepted_at', 'tg_done_at') as $col) {
	if ($db->query("SHOW COLUMNS FROM `" . $p . "aichat_conversation` LIKE '" . $col . "'")->num_rows) {
		$db->query("ALTER TABLE `" . $p . "aichat_conversation` DROP `" . $col . "`");
	}
}
$setPd(false);
$db->query("UPDATE `" . $p . "setting` SET `value` = '6' WHERE `key` = 'module_aichat_g_schema'");
$conversations = (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_conversation`")->row['n'];
check('upgrade from schema 6 runs', \Aichat\Installer::upgrade($registry) === true);
$cols = array();
foreach (array('restricted', 'privacy_accepted_at', 'tg_done_at') as $col) {
	$cols[] = (bool)$db->query("SHOW COLUMNS FROM `" . $p . "aichat_conversation` LIKE '" . $col . "'")->num_rows;
}
check('schema 7: conversation columns restricted, privacy_accepted_at, tg_done_at + tables aichat_dsr, aichat_access_log', !in_array(false, $cols, true) && $db->query("SHOW TABLES LIKE '" . $p . "aichat_dsr'")->num_rows && $db->query("SHOW TABLES LIKE '" . $p . "aichat_access_log'")->num_rows && stripos($db->query("SHOW CREATE TABLE `" . $p . "aichat_dsr`")->row['Create Table'], 'utf8mb4') !== false);
list(, $perm) = $adminGroup();
check('schema 7: ' . $pd . ' granted to the Administrator group (access + modify)', in_array($pd, $perm['access'], true) && in_array($pd, $perm['modify'], true));
$settings = new \Aichat\Settings($registry);
check('g_schema 7, conversations kept, new global keys with defaults', (int)$settings->getGlobal('g_schema') === 7 && (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_conversation`")->row['n'] === $conversations && (int)$settings->getGlobal('g_ip_days') === 30 && (int)$settings->getGlobal('g_request_months') === 12 && (int)$settings->getGlobal('g_miss_days') === 90);
check('schema 7 upgrade again is a no-op', \Aichat\Installer::upgrade($registry) === false);
$setPd(false);
\Aichat\Installer::install($registry);
list(, $perm) = $adminGroup();
check('permission taken away by the owner is not granted again by install() over schema 7', !in_array($pd, isset($perm['access']) ? $perm['access'] : array(), true));
$setPd(true);
// from schema 1 (no g_schema row at all): every step, the permission once, no duplicate
$db->query("DELETE FROM `" . $p . "setting` WHERE `key` = 'module_aichat_g_schema'");
\Aichat\Installer::upgrade($registry);
list(, $perm) = $adminGroup();
check('upgrade from schema 1 / 0: permission entry not duplicated', count(array_keys($perm['access'], $pd)) === 1 && count(array_keys($perm['modify'], $pd)) === 1 && (int)(new \Aichat\Settings($registry))->getGlobal('g_schema') === 7);

exit($fails ? 1 : 0);
