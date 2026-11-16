<?php
/**
 * Race test for \Aichat\Settings (generated secrets and writes) and the
 * bounded catalog search, against a SCRATCH database (it deletes and rewrites
 * module settings; never point it at a live shop).
 *
 *   php tests/settings_race_test.php <oc dir> <db host> <user> <pass> <scratch db> <prefix> [workers]
 *
 * The parent starts N workers at the same moment; each asks for g_secret and
 * g_tg_secret (generated if missing) and writes one shared key 20 times.
 * Afterwards every key must have exactly one row and all workers must have got
 * the same secrets.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$args = $argv;
$isWorker = isset($args[1]) && $args[1] === 'worker';
if ($isWorker) {
	array_splice($args, 1, 1);
}
list(, $oc, $host, $user, $pass, $name, $prefix) = $args + array_fill(0, 7, '');
$workers = isset($args[7]) ? max(2, (int)$args[7]) : 12;

if ($oc === '' || $name === '') {
	fwrite(STDERR, "usage: php tests/settings_race_test.php <oc dir> <db host> <user> <pass> <scratch db> <prefix> [workers]\n");
	exit(1);
}

define('DB_PREFIX', $prefix);
define('DB_DATABASE', $name);
define('DIR_STORAGE', sys_get_temp_dir() . '/aichat_race_storage/');
require $oc . '/system/library/db.php';
require $oc . '/system/library/db/mysqli.php';
spl_autoload_register(function ($class) use ($oc) {
	$file = $oc . '/system/library/' . str_replace('\\', '/', strtolower($class)) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

class RaceRegistry {
	private $d = array();
	public function get($k) {
		return isset($this->d[$k]) ? $this->d[$k] : null;
	}
	public function set($k, $v) {
		$this->d[$k] = $v;
	}
}

class RaceConfig {
	public function get($k) {
		return null;
	}
}

$registry = new RaceRegistry();
$db = new DB('mysqli', $host, $user, $pass, $name, 3306);
$registry->set('db', $db);

if ($isWorker) {
	$startAt = (float)getenv('RACE_START');
	while (microtime(true) < $startAt) {
		usleep(1000);
	}
	$settings = new \Aichat\Settings($registry);
	$tg = $settings->telegramSecret();
	$hmac = $settings->getSecret();
	for ($i = 0; $i < 20; $i++) {
		$settings->saveGlobal(array('g_report_email' => 'race' . getmypid() . '-' . $i . '@example.com'));
	}
	echo json_encode(array('tg' => $tg, 'hmac' => $hmac)), "\n";
	exit(0);
}

$fails = 0;
function check($label, $ok, $detail = '') {
	global $fails;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	$fails += $ok ? 0 : 1;
}

$p = DB_PREFIX;
$count = function ($key) use ($db, $p) {
	return (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "setting` WHERE `code` = 'module_aichat' AND `store_id` = 0 AND `key` = 'module_aichat_" . $key . "'")->row['n'];
};

// ---- duplicates left by old versions are cleaned and reads are deterministic
$db->query("DELETE FROM `" . $p . "setting` WHERE `code` = 'module_aichat' AND `key` = 'module_aichat_g_tg_secret'");
$db->query("INSERT INTO `" . $p . "setting` SET `store_id` = 0, `code` = 'module_aichat', `key` = 'module_aichat_g_tg_secret', `value` = 'older0000000000000000000000000000000000000', `serialized` = 0");
$db->query("INSERT INTO `" . $p . "setting` SET `store_id` = 0, `code` = 'module_aichat', `key` = 'module_aichat_g_tg_secret', `value` = 'newer0000000000000000000000000000000000000', `serialized` = 0");
$settings = new \Aichat\Settings($registry);
check('duplicate rows: the newest one is read', $settings->telegramSecret() === 'newer0000000000000000000000000000000000000');
$removed = $settings->removeDuplicates();
check('removeDuplicates() leaves one row (newest)', $removed >= 1 && $count('g_tg_secret') === 1 && (new \Aichat\Settings($registry))->telegramSecret() === 'newer0000000000000000000000000000000000000');

// ---- parallel generation and writes
foreach (array('g_secret', 'g_tg_secret', 'g_report_email') as $k) {
	$db->query("DELETE FROM `" . $p . "setting` WHERE `code` = 'module_aichat' AND `key` = 'module_aichat_" . $k . "'");
}

$start = microtime(true) + 1.5;
$procs = array();
for ($i = 0; $i < $workers; $i++) {
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . implode(' ', array_map('escapeshellarg', array($oc, $host, $user, $pass, $name, $prefix)));
	$procs[] = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$i], null, array('RACE_START' => (string)$start) + $_ENV + array('PATH' => getenv('PATH')));
}

$results = array();
foreach ($procs as $i => $proc) {
	$out = stream_get_contents($pipes[$i][1]);
	$err = stream_get_contents($pipes[$i][2]);
	proc_close($proc);
	$j = json_decode(trim($out), true);
	if (!is_array($j)) {
		check('worker ' . $i . ' answered', false, $out . $err);
		continue;
	}
	$results[] = $j;
}

$tg = array_unique(array_map(function ($r) {
	return $r['tg'];
}, $results));
$hmac = array_unique(array_map(function ($r) {
	return $r['hmac'];
}, $results));

check($workers . ' parallel workers finished', count($results) === $workers);
check('g_tg_secret generated once: every worker got the same secret', count($tg) === 1, implode(', ', $tg));
check('g_secret generated once: every worker got the same secret', count($hmac) === 1, implode(', ', $hmac));
check('one row per key after parallel generation and ' . (20 * $workers) . ' parallel writes', $count('g_tg_secret') === 1 && $count('g_secret') === 1 && $count('g_report_email') === 1,
	'tg=' . $count('g_tg_secret') . ' secret=' . $count('g_secret') . ' email=' . $count('g_report_email'));
check('the stored secret is the one the workers use', (new \Aichat\Settings($registry))->telegramSecret() === reset($tg));

// ---- bounded catalog search: a statement over the time limit returns no rows instead of blocking
$registry->set('config', new RaceConfig());
$tools = new \Aichat\Tools($registry, 0, 1);
$m = new ReflectionMethod('\Aichat\Tools', 'bounded');
$m->setAccessible(true);
$version = (string)$db->query("SELECT VERSION() AS v")->row['v'];
$limited = (stripos($version, 'mariadb') !== false && version_compare(preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version), '10.1.2', '>='))
	|| (stripos($version, 'mariadb') === false && version_compare(preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version), '5.7.8', '>='));
$t0 = microtime(true);
$rows = $m->invoke($tools, "SELECT SLEEP(" . (\Aichat\Tools::SEARCH_SECONDS + 3) . ") AS s FROM `" . $p . "product` LIMIT 1");
$took = microtime(true) - $t0;
if ($limited) {
	check('A-19: search statement stopped after about ' . \Aichat\Tools::SEARCH_SECONDS . ' s (' . $version . ')', $took < \Aichat\Tools::SEARCH_SECONDS + 2, sprintf('%.1f s, %s', $took, json_encode($rows)));
} else {
	echo "skip A-19 time limit: " . $version . " has no statement time limit\n";
}
$fast = $m->invoke($tools, "SELECT COUNT(*) AS n FROM `" . $p . "product`");
check('A-19: normal statements are unaffected', isset($fast[0]['n']) && (int)$fast[0]['n'] > 0);

echo $fails ? "\n$fails FAILED\n" : "\nall ok\n";
exit($fails ? 1 : 0);
