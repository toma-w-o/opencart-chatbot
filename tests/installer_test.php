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
function check($label, $ok) {
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

// courier secret file: write-only storage
$settings->setCourierSecret('selftest', array('username' => 'u', 'password' => 'p'));
check('courier secret stored', $settings->hasCourierSecret('selftest') && !$settings->hasCourierSecret('none') && strpos(json_encode($settings->getGlobal()), '"p"') === false);
$settings->setCourierSecret('selftest', array());
check('courier secret removed', !$settings->hasCourierSecret('selftest'));

exit($fails ? 1 : 0);
