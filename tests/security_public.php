<?php
/**
 * Regression tests for the public-surface security audit (findings A-1 ... A-18
 * and the guard / mail parts of the second audit). Every check here failed on
 * 1.0.1 and passes on 1.0.2.
 *
 *   SMOKE_DB=localhost:oc:oc:oc:oc_ SMOKE_OC=<rig>/oc SMOKE_MAIL=<rig>/mail.log \
 *     php tests/security_public.php http://127.0.0.1:8080/ http://127.0.0.1:8081/
 *
 * Needs the rig of tests/setup_oc.sh (fake model API, fake services incl. Telegram
 * on 8098, mails to SMOKE_MAIL). Settings changed here are restored at the end.
 * Part 1 runs the library classes directly against the rig database; part 2
 * talks HTTP to the stores. Exit code 1 on any failure.
 */

$store0 = isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8080/';
$store1 = isset($argv[2]) ? $argv[2] : 'http://127.0.0.1:8081/';
$dsn = getenv('SMOKE_DB') ? getenv('SMOKE_DB') : 'localhost:oc:oc:oc:oc_';
$oc = getenv('SMOKE_OC');
$mailFile = getenv('SMOKE_MAIL');

if (!$oc || !is_dir($oc . '/system/storage')) {
	fwrite(STDERR, "SMOKE_OC (rig oc dir) is required\n");
	exit(2);
}

list($dbHost, $dbUser, $dbPass, $dbName, $dbPrefix) = explode(':', $dsn);
define('DB_PREFIX', $dbPrefix);
define('DB_DATABASE', $dbName);
define('DIR_STORAGE', $oc . '/system/storage/');
define('DIR_APPLICATION', $oc . '/catalog/');
define('DIR_SYSTEM', $oc . '/system/');

$lib = dirname(__DIR__) . '/upload/system/library/aichat/';
spl_autoload_register(function ($class) use ($lib) {
	if (strpos($class, 'Aichat\\') === 0) {
		$file = $lib . strtolower(str_replace('\\', '/', substr($class, 7))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});

$fails = 0;
$passes = 0;

function check($name, $ok, $detail = '') {
	global $fails, $passes;
	if ($ok) {
		$passes++;
		echo "ok   $name\n";
	} else {
		$fails++;
		echo "FAIL $name\n" . ($detail !== '' ? '     ' . substr((string)$detail, 0, 1500) . "\n" : '');
	}
}

// ---------------------------------------------------------------- minimal OpenCart stand-ins

class TestDb {
	public $m;
	public function __construct($host, $user, $pass, $name) {
		$this->m = new mysqli($host, $user, $pass, $name);
		$this->m->set_charset('utf8mb4');
	}
	public function query($sql) {
		$r = $this->m->query($sql);
		if ($r === false) {
			throw new \Exception('SQL: ' . $this->m->error . ' in ' . $sql);
		}
		$o = new stdClass();
		$o->rows = array();
		if ($r instanceof mysqli_result) {
			while ($row = $r->fetch_assoc()) {
				$o->rows[] = $row;
			}
		}
		$o->row = $o->rows ? $o->rows[0] : array();
		$o->num_rows = count($o->rows);
		return $o;
	}
	public function escape($v) {
		return $this->m->real_escape_string((string)$v);
	}
	public function countAffected() {
		return $this->m->affected_rows;
	}
	public function getLastId() {
		return $this->m->insert_id;
	}
}

class TestLog {
	public $lines = array();
	public function write($s) {
		$this->lines[] = $s;
	}
}

class TestRegistry {
	private $d = array();
	public function get($k) {
		return isset($this->d[$k]) ? $this->d[$k] : null;
	}
	public function set($k, $v) {
		$this->d[$k] = $v;
	}
}

$db = new TestDb($dbHost, $dbUser, $dbPass, $dbName);
$registry = new TestRegistry();
$registry->set('db', $db);
$log = new TestLog();
$registry->set('log', $log);

function q($sql) {
	global $db;
	return $db->query(str_replace('oc_', DB_PREFIX, $sql));
}

$restore = array();

/** Sets a module setting and remembers the old rows (restored at the end). */
function setting($storeId, $key, $value) {
	global $db, $restore;
	$full = 'module_aichat_' . $key;
	$id = $storeId . '|' . $full;
	if (!isset($restore[$id])) {
		$restore[$id] = q("SELECT store_id, code, `key`, value, serialized FROM oc_setting WHERE store_id = " . (int)$storeId . " AND code = 'module_aichat' AND `key` = '" . $db->escape($full) . "'")->rows;
	}
	q("DELETE FROM oc_setting WHERE store_id = " . (int)$storeId . " AND code = 'module_aichat' AND `key` = '" . $db->escape($full) . "'");
	if ($value !== null) {
		$ser = is_array($value) ? 1 : 0;
		q("INSERT INTO oc_setting SET store_id = " . (int)$storeId . ", code = 'module_aichat', `key` = '" . $db->escape($full) . "', value = '" . $db->escape(is_array($value) ? json_encode($value) : (string)$value) . "', serialized = " . $ser);
	}
}

function restoreSettings() {
	global $db, $restore;
	foreach ($restore as $id => $rows) {
		list($storeId, $full) = explode('|', $id, 2);
		q("DELETE FROM oc_setting WHERE store_id = " . (int)$storeId . " AND code = 'module_aichat' AND `key` = '" . $db->escape($full) . "'");
		foreach ($rows as $r) {
			q("INSERT INTO oc_setting SET store_id = " . (int)$r['store_id'] . ", code = 'module_aichat', `key` = '" . $db->escape($r['key']) . "', value = '" . $db->escape($r['value']) . "', serialized = " . (int)$r['serialized']);
		}
	}
	$restore = array();
}

function resetRates() {
	q("DELETE FROM oc_aichat_rate");
}

/** Sets a Guard windowed counter (same key derivation as Guard::hit / addAmount). */
function counter($key, $window, $value) {
	global $db;
	$start = (int)(floor(time() / $window) * $window);
	$k = substr(hash('sha256', $key), 0, 64);
	q("REPLACE INTO oc_aichat_rate (rate_key, window_start, hits) VALUES ('" . $db->escape($k) . "', " . $start . ", " . (int)$value . ")");
}

function settings() {
	global $registry;
	return new \Aichat\Settings($registry);
}

register_shutdown_function(function () {
	restoreSettings();
	resetRates();
});

resetRates();

// ================================================================ part 1: library units

echo "--- A-1 / A-2: client IP and rate keys\n";
check('A-1 Cloudflare range recognised (v4)', \Aichat\Guard::fromCloudflare('173.245.48.7') && \Aichat\Guard::fromCloudflare('104.16.1.1'));
check('A-1 Cloudflare range recognised (v6)', \Aichat\Guard::fromCloudflare('2606:4700:10::6816:1'));
check('A-1 non-Cloudflare peers are not', !\Aichat\Guard::fromCloudflare('127.0.0.1') && !\Aichat\Guard::fromCloudflare('203.0.113.9') && !\Aichat\Guard::fromCloudflare('2001:db8::1') && !\Aichat\Guard::fromCloudflare('garbage'));
setting(0, 'g_trust_cf_ip', 1);
$g = new \Aichat\Guard($registry, settings());
check('A-1 trust on, peer not Cloudflare: header ignored', $g->clientIp(array('REMOTE_ADDR' => '198.51.100.4', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9')) === '198.51.100.4');
check('A-1 trust on, peer is Cloudflare: header used', $g->clientIp(array('REMOTE_ADDR' => '162.158.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9')) === '203.0.113.9');
setting(0, 'g_trust_cf_ip', 0);
$g = new \Aichat\Guard($registry, settings());
check('A-1 trust off: header ignored even from Cloudflare', $g->clientIp(array('REMOTE_ADDR' => '162.158.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9')) === '162.158.1.2');
check('A-2 IPv6 keyed by /64', \Aichat\Guard::rateIp('2001:db8:1:2:aaaa::1') === \Aichat\Guard::rateIp('2001:db8:1:2:ffff:1:2:3') && \Aichat\Guard::rateIp('2001:db8:1:2::9') === '2001:db8:1:2::/64');
check('A-2 neighbouring /64 is another key', \Aichat\Guard::rateIp('2001:db8:1:3::1') !== \Aichat\Guard::rateIp('2001:db8:1:2::1'));
check('A-2 IPv4 and IPv4-mapped IPv6', \Aichat\Guard::rateIp('203.0.113.5') === '203.0.113.5' && \Aichat\Guard::rateIp('::ffff:203.0.113.5') === '203.0.113.5');
resetRates();
$codes = array();
for ($i = 0; $i < 7; $i++) {
	$codes[] = $g->checkMessage(array('s_max_msgs_minute' => 2, 's_max_msgs_chat' => 0, 's_max_chats_day' => 0), null, 'pub' . $i, '2001:db8:7:7:' . dechex($i + 1) . '::1', false, 0);
}
check('A-2 per-IP message limit counts the whole /64 (6/min with s_max_msgs_minute 2)', array_slice($codes, 0, 6) === array('', '', '', '', '', '') && $codes[6] === 'rate_limited', json_encode($codes));
resetRates();

echo "--- store-wide order-verification safety (A-1/A-2, M3)\n";
resetRates();
check('not under attack by default', !$g->underOrderAttack(0) && $g->orderGate(0, '203.0.113.5', array('human_verified' => 0)));
$g->recordOrderFailures(0, \Aichat\Guard::ORDER_FAIL_STORE_HOUR);
check('under attack after ' . \Aichat\Guard::ORDER_FAIL_STORE_HOUR . ' failures in the hour', $g->underOrderAttack(0) && !$g->underOrderAttack(1));
$n = 0;
for ($i = 0; $i < 5; $i++) {
	$n += $g->orderGate(0, '203.0.113.5', array('human_verified' => 0)) ? 1 : 0;
}
check('under attack: unverified conversation gets ' . \Aichat\Guard::ORDER_ATTEMPTS_UNDER_ATTACK . ' checks per IP per hour', $n === \Aichat\Guard::ORDER_ATTEMPTS_UNDER_ATTACK, $n);
check('under attack: Turnstile-verified conversation not slowed', $g->orderGate(0, '203.0.113.5', array('human_verified' => 1)));
check('under attack: honest customer from another IP still gets checks (no shop-wide lockout)', $g->orderGate(0, '198.51.100.77', array('human_verified' => 0)));
resetRates();

echo "--- M1: cost caps\n";
resetRates();
check('cost: fresh conversation allowed', $g->checkBudget(1, 999999, '203.0.113.5') === '');
counter('cost:c:999999:' . date('Y-m-d'), 86400, (int)(\Aichat\Guard::CONV_COST_DAY * 1000000));
check('cost: conversation over ' . \Aichat\Guard::CONV_COST_DAY . ' USD today -> cost_limit', $g->checkBudget(1, 999999, '203.0.113.5') === 'cost_limit');
resetRates();
counter('cost:i:203.0.113.5:' . date('Y-m-d'), 86400, (int)(\Aichat\Guard::IP_COST_DAY * 1000000));
check('cost: IP over ' . \Aichat\Guard::IP_COST_DAY . ' USD today -> cost_limit', $g->checkBudget(1, 1, '203.0.113.5') === 'cost_limit' && $g->checkBudget(1, 1, '198.51.100.1') === '');
resetRates();
// recordUsage feeds the counters
setting(0, 'g_price_in', 1000000); // 1 USD per token: one call is plenty
$gp = new \Aichat\Guard($registry, settings());
$before = q("SELECT cost FROM oc_aichat_usage WHERE store_id = 1 AND day = '" . date('Y-m-d') . "'")->row;
$gp->recordUsage(1, 999998, array('input_tokens' => 3, 'output_tokens' => 0), false, '2001:db8:5:5::1');
q("UPDATE oc_aichat_usage SET cost = cost - 3 WHERE store_id = 1 AND day = '" . date('Y-m-d') . "'");
$rc = $gp->checkBudget(1, 999998, ''); $ri = $gp->checkBudget(1, 0, '2001:db8:5:5:abcd::2');
check('cost: recordUsage counts per conversation and per /64', $rc === 'cost_limit' && $ri === 'cost_limit', $rc . '/' . $ri);
setting(0, 'g_price_in', null);
restoreSettings();
resetRates();
// per-store share of the global budget
setting(0, 'g_budget_month', 50);
setting(1, 's_budget_month', 0);
$g = new \Aichat\Guard($registry, settings());
$today = q("SELECT cost FROM oc_aichat_usage WHERE store_id = 1 AND day = '" . date('Y-m-d') . "'")->row;
$share = 50 * \Aichat\Guard::STORE_DAY_SHARE;
$add = max(0, $share - ($today ? (float)$today['cost'] : 0) + 0.01);
q("INSERT INTO oc_aichat_usage SET store_id = 1, day = '" . date('Y-m-d') . "', chats = 0, messages = 0, input_tokens = 0, output_tokens = 0, cost = " . $add . " ON DUPLICATE KEY UPDATE cost = cost + " . $add);
$r1 = $g->checkBudget(1);
$r0 = (new \Aichat\Guard($registry, settings()))->checkBudget(0);
q("UPDATE oc_aichat_usage SET cost = cost - " . $add . " WHERE store_id = 1 AND day = '" . date('Y-m-d') . "'");
check('budget: store without own budget stops at ' . (\Aichat\Guard::STORE_DAY_SHARE * 100) . '% of the global budget per day', $r1 === 'budget', $r1);
check('budget: the other stores keep answering', $r0 === '', $r0);
restoreSettings();

echo "--- A-4: concurrency slots\n";
$g = new \Aichat\Guard($registry, settings());
$holders = array();
for ($i = 0; $i < \Aichat\Guard::MAX_CONCURRENT; $i++) {
	$holders[$i] = new \Aichat\Guard($registry2 = (function () use ($dbHost, $dbUser, $dbPass, $dbName) {
		$r = new TestRegistry();
		$r->set('db', new TestDb($dbHost, $dbUser, $dbPass, $dbName));
		return $r;
	})(), settings());
	$holders[$i]->acquireSlot(7);
}
check('slots: all ' . \Aichat\Guard::MAX_CONCURRENT . ' taken -> no slot', !$g->acquireSlot(7));
$holders[0]->releaseSlots();
check('slots: one released -> slot again', $g->acquireSlot(7));
$g->releaseSlots();
foreach ($holders as $h) {
	$h->releaseSlots();
}

echo "--- A-6: link filter\n";
$f = new \Aichat\LinkFilter(array('shop.bg'));
foreach (array(
	'https://evil.com\\@shop.bg/x'   => '',
	'[a](https://evil.com\\@shop.bg/x)' => 'a',
	'[a](/\\evil.com/x)'             => 'a',
	'[a](\\\\evil.com/x)'            => 'a',
	'[a](javascript&#58;alert(1))'   => 'a',
	'[a](JaVaScRiPt:alert(1))'       => 'a',
	'[a](javascript&colon;x)'        => 'a',
	'see http:evil.com now'          => 'see  now',
	'visit evil.ai'                  => 'visit ',
	'[ok](https://shop.bg/p?x=1)'    => '[ok](https://shop.bg/p?x=1)',
	'[rel](/index.php?route=a)'      => '[rel](/index.php?route=a)',
	'https://shop.bg%2F@evil.com'    => '',
) as $in => $want) {
	$got = $f->filter($in);
	check('A-6 filter ' . $in, $got === $want, var_export($got, true));
}
$f2 = new \Aichat\LinkFilter(array('shop.bg'));
$out = '';
foreach (array('pay at https://evil.c', 'om\\@shop.bg/x ', 'now') as $ch) {
	$out .= $f2->push($ch);
}
$out .= $f2->flush();
check('A-6 backslash URL split across stream chunks removed', strpos($out, 'evil') === false, $out);
$d = \Aichat\LinkFilter::defang('see https://evil.com/a.b and www.bad.bg, evil.ai and https://shop.bg/ok', array('shop.bg'));
check('L4 defang: foreign links unclickable, own host kept', strpos($d, 'hxxps://evil[.]com/a[.]b') !== false && strpos($d, 'bad[.]bg') !== false && strpos($d, 'evil[.]ai') !== false && strpos($d, 'https://shop.bg/ok') !== false, $d);

echo "--- A-13: GD memory guard\n";
check('A-13 MAX_PIXELS lowered (<= 6 MP)', \Aichat\Uploads::MAX_PIXELS <= 6000000);
ini_set('memory_limit', '64M');
check('A-13 16 MP refused under a 64M memory_limit', !\Aichat\Uploads::memoryFor(4000, 4000));
check('A-13 1568 px photo fine under 64M', \Aichat\Uploads::memoryFor(1568, 1176));
ini_set('memory_limit', '-1');

echo "--- A-14: no addresses in the log, storage exposure helper\n";
$log->lines = array();
$res = \Aichat\Mailer::deliver($registry, 'not-an-address@', 'x', 'y', array('store_id' => 0));
check('A-14 mail failure logged without the address', !$res['ok'] && $log->lines && strpos(implode("\n", $log->lines), 'not-an-address') === false, implode("\n", $log->lines));
check('A-14 maskAddresses', \Aichat\Mailer::maskAddresses('to jane.doe@example.com failed') === 'to [address] failed');
check('A-3 maskEmail', \Aichat\Mailer::maskEmail('jane.doe@example.com') === 'j***@e***.com');
$exp = \Aichat\Uploads::storageExposure($store0);
check('A-14 storageExposure: rig storage inside the web root and readable over HTTP', $exp['inside_webroot'] === true && $exp['http_readable'] === true, json_encode($exp));
check('A-14 storageExposure: probe file removed', !glob(DIR_STORAGE . 'aichat/probe_*'));

echo "--- A-16: Telegram staff ids\n";
check('A-16 staffIds parses list / string', \Aichat\Handover::staffIds(array('111', 222, 'x')) === array('111', '222') && \Aichat\Handover::staffIds('111, 222;333') === array('111', '222', '333'));

echo "--- A-18: uninstall removes the storage directory\n";
$tmp = sys_get_temp_dir() . '/aichat_uninst_' . getmypid();
@mkdir($tmp . '/aichat/uploads/202601', 0700, true);
foreach (array('/aichat/.htaccess', '/aichat/index.html', '/aichat/uploads/.htaccess', '/aichat/uploads/index.html', '/aichat/uploads/202601/' . str_repeat('a', 32) . '.jpg') as $p) {
	file_put_contents($tmp . $p, 'x');
}
$cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('define("DIR_STORAGE", "' . $tmp . '/"); require "' . $lib . 'uploads.php"; var_export(\Aichat\Uploads::removeStorageDir());');
$outRm = trim((string)shell_exec($cmd));
check('A-18 removeStorageDir leaves nothing behind', $outRm === 'true' && !is_dir($tmp . '/aichat'), $outRm);
@rmdir($tmp);

// ================================================================ part 2: HTTP

$GLOBALS['HEADERS'] = array();

function http($method, $url, $body = null, array $headers = array(), &$code = null) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	$h = array_merge($GLOBALS['HEADERS'], $headers);
	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) || is_array($body) && isset($body['file']) ? $body : json_encode($body));
		if (!is_string($body) && !(is_array($body) && isset($body['file']))) {
			$has = false;
			foreach ($h as $x) {
				$has = $has || stripos($x, 'Content-Type:') === 0;
			}
			if (!$has) {
				$h[] = 'Content-Type: application/json';
			}
		}
	}
	if ($h) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
	}
	$out = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return (string)$out;
}

function ep($base, $route) {
	return $base . 'index.php?route=extension/module/aichat/' . $route;
}

function bootH($base, $token = '') {
	return json_decode(http('GET', ep($base, 'boot') . '&url=' . rawurlencode($base), null, $token !== '' ? array('X-Aichat-Token: ' . $token) : array()), true);
}

function sendM($base, $token, $message, array $extra = array(), array $headers = array()) {
	return json_decode(http('POST', ep($base, 'send'), array('token' => $token, 'message' => $message, 'url' => $base, 'stream' => 0) + $extra, $headers), true);
}

function probeT($base, $token, $name, array $input, array $headers = array()) {
	return sendM($base, $token, 'tool:' . $name . ' ' . json_encode($input ? $input : new stdClass()), array(), $headers);
}

function postJ($base, $route, array $body, array $headers = array()) {
	return json_decode(http('POST', ep($base, $route), $body, $headers), true);
}

function convOf($token) {
	return q("SELECT * FROM oc_aichat_conversation WHERE public_id = '" . substr($token, 0, 32) . "'")->row;
}

function mailText() {
	global $mailFile;
	return is_file($mailFile) ? (string)file_get_contents($mailFile) : '';
}

function errCode($r) {
	return is_array($r) && isset($r['error']['code']) ? $r['error']['code'] : '';
}

resetRates();

echo "--- A-1 over HTTP\n";
setting(0, 'g_trust_cf_ip', 1);
$t = bootH($store0)['token'];
sendM($store0, $t, 'hello', array(), array('CF-Connecting-IP: 203.0.113.9'));
check('A-1 spoofed CF-Connecting-IP from a non-Cloudflare peer not stored', convOf($t)['ip'] === '127.0.0.1', convOf($t)['ip']);
$locked = 0;
$generic = 0;
for ($n = 0; $n < 3; $n++) {
	$tt = bootH($store0)['token'];
	for ($i = 0; $i < 5; $i++) {
		$r = probeT($store0, $tt, 'find_order', array('order_id' => 8000 + $n * 5 + $i, 'phone' => '0899000000'), array('CF-Connecting-IP: 203.0.113.' . (10 + $n * 5 + $i)));
		if (isset($r['reply']) && strpos($r['reply'], 'Too many') !== false || errCode($r) === 'rate_limited') {
			$locked++;
		} elseif (isset($r['reply']) && strpos($r['reply'], 'No order matches') !== false) {
			$generic++;
		}
	}
}
check('A-1 order-guess limit holds while spoofing the header (15 tries: at most 10 real checks, the rest locked)', $generic <= 10 && $locked >= 1 && $generic + $locked === 15, $generic . ' checked / ' . $locked . ' locked');
restoreSettings();
resetRates();
q("DELETE FROM oc_aichat_rate");

echo "--- A-3 transcript\n";
@unlink($mailFile);
$t = bootH($store0)['token'];
sendM($store0, $t, 'Security alert: confirm at https://evil-phish.example/login now');
$r = postJ($store0, 'transcript', array('token' => $t, 'email' => 'victim@third-party.example'));
check('A-3 free address refused, nothing mailed', errCode($r) === 'transcript_unavailable' && strpos(mailText(), 'third-party') === false, json_encode($r));
$r = postJ($store0, 'transcript', array('token' => $t, 'check' => 1));
check('A-3 check: not available for an unverified guest (explains why)', isset($r['available']) && $r['available'] === false && $r['message'] !== '', json_encode($r));
probeT($store0, $t, 'find_order', array('order_id' => '9001', 'phone' => '0888123456'));
$r = postJ($store0, 'transcript', array('token' => $t, 'check' => 1));
check('A-3 check after order verification: masked order e-mail', isset($r['masked']) && $r['masked'] === 'i***@e***.com', json_encode($r));
$r = postJ($store0, 'transcript', array('token' => $t, 'email' => 'victim@third-party.example'));
$mail = mailText();
check('A-3 sent to the order e-mail only, visitor links filtered', isset($r['ok']) && strpos($mail, 'To: ivan9001@example.com') !== false && strpos($mail, 'third-party') === false && strpos($mail, 'evil-phish') === false, json_encode($r) . "\n" . $mail);
$t2 = bootH($store0)['token'];
sendM($store0, $t2, 'hi');
probeT($store0, $t2, 'find_order', array('order_id' => '9001', 'phone' => '0888123456'));
counter('t:s:0:' . date('Y-m-d'), 86400, 50);
$r = postJ($store0, 'transcript', array('token' => $t2));
check('A-3 store-wide daily cap', errCode($r) === 'rate_limited', json_encode($r));
resetRates();

echo "--- A-5 Turnstile fallback\n";
setting(0, 'g_turnstile_site', '1x00000000000000000000AA');
setting(0, 'g_turnstile_secret', '2x0000000000000000000000000000000AA');
$t = bootH($store0)['token'];
$r = sendM($store0, $t, 'hello');
check('A-5 challenge without a token', errCode($r) === 'challenge', json_encode($r));
$r = sendM($store0, $t, 'hello', array('turnstile' => 'unavailable'));
check('A-5 "unavailable" accepted while the store budget lasts', isset($r['reply']), json_encode($r));
counter('t:u:s:0', 3600, \Aichat\Guard::UNAVAILABLE_STORE_HOUR);
$r = sendM($store0, $t, 'hello again', array('turnstile' => 'unavailable'));
check('A-5 store-wide "unavailable" budget used up -> challenge stands', errCode($r) === 'challenge', json_encode($r));
resetRates();
// a conversation that got in on an outage is challenged again while the store is under order attack
$t = bootH($store0)['token'];
$r = sendM($store0, $t, 'x', array('turnstile' => 'unavailable'));
q("UPDATE oc_aichat_conversation SET human_verified = 2 WHERE public_id = '" . substr($t, 0, 32) . "'");
$r = sendM($store0, $t, 'x2');
check('outage-verified conversation not re-challenged normally', isset($r['reply']), json_encode($r));
counter('of:s:0', 3600, \Aichat\Guard::ORDER_FAIL_STORE_HOUR);
$r = sendM($store0, $t, 'x3');
check('M3 under order attack: outage-verified conversation must pass Turnstile', errCode($r) === 'challenge', json_encode($r));
restoreSettings();
resetRates();

echo "--- M3 failed verifications are counted per store\n";
$t = bootH($store0)['token'];
probeT($store0, $t, 'find_order', array('order_id' => '9001', 'phone' => '0111111111'));
probeT($store0, $t, 'find_order', array('order_id' => '9002', 'phone' => '0111111111'));
$g = new \Aichat\Guard($registry, settings());
check('M3 two failures recorded in the store counter', $g->orderFailures(0) === 2, $g->orderFailures(0));
resetRates();

echo "--- M1 cost cap over HTTP\n";
$t = bootH($store0)['token'];
sendM($store0, $t, 'hi');
$cid = (int)convOf($t)['conversation_id'];
counter('cost:c:' . $cid . ':' . date('Y-m-d'), 86400, (int)(\Aichat\Guard::CONV_COST_DAY * 1000000));
$r = sendM($store0, $t, 'again');
check('M1 conversation over its daily cost -> cost_limit (localised, with contacts)', errCode($r) === 'cost_limit' && strpos($r['error']['message'], 'shop0@example.com') !== false, json_encode($r));
resetRates();

echo "--- A-4 busy slots\n";
$hold = array();
$prefix = 'aichat_' . substr(md5(DB_DATABASE . '|' . DB_PREFIX), 0, 10) . '_0_';
for ($i = 1; $i <= \Aichat\Guard::MAX_CONCURRENT; $i++) {
	$hold[$i] = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
	$hold[$i]->query("SELECT GET_LOCK('" . $prefix . $i . "', 0)");
}
$t = bootH($store0)['token'];
$r = sendM($store0, $t, 'hello');
check('A-4 all model slots of the store busy -> friendly "busy"', errCode($r) === 'busy' && $r['error']['message'] !== '', json_encode($r));
$r1 = sendM($store1, bootH($store1)['token'], 'hello');
check('A-4 other store unaffected', isset($r1['reply']), json_encode($r1));
foreach ($hold as $m) {
	$m->close();
}
$r = sendM($store0, $t, 'hello');
check('A-4 slot free again -> answered', isset($r['reply']), json_encode($r));
// client gone: no further paid rounds (fake model: 3 rounds for a search question)
$fakeLog = dirname($oc) . '/fake.log';
$marker = 'abortprobe' . mt_rand(100000, 999999);
$t = bootH($store0)['token'];
$countLines = function () use ($fakeLog, $marker) {
	return is_file($fakeLog) ? count(preg_grep('/' . $marker . '/', file($fakeLog))) : 0;
};
$before = $countLines();
$ch = curl_init(ep($store0, 'send'));
$got = 0;
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(array('token' => $t, 'message' => $marker, 'url' => $store0, 'stream' => 1)),
	CURLOPT_HTTPHEADER => array('Content-Type: application/json'), CURLOPT_TIMEOUT => 30,
	CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$got) {
		$got += strlen($data);
		return 0; // hang up as soon as the stream starts
	}));
curl_exec($ch);
curl_close($ch);
sleep(3);
$calls = $countLines() - $before;
check('A-4 visitor hung up: the tool loop stops (fewer than the 3 model rounds)', $calls >= 0 && $calls < 3, $calls);
resetRates();

echo "--- A-7 cross-site POST\n";
$t = bootH($store0)['token'];
$r = json_decode(http('POST', ep($store0, 'cart'), '{"token":"' . $t . '","product_id":40,"quantity":1,"x":"="}', array('Content-Type: text/plain')), true);
check('A-7 text/plain body (HTML form) refused', errCode($r) === 'forbidden', json_encode($r));
$r = postJ($store0, 'cart', array('token' => $t, 'product_id' => 40), array('Origin: https://evil.example'));
check('A-7 foreign Origin refused', errCode($r) === 'forbidden', json_encode($r));
$r = postJ($store0, 'cart', array('token' => $t, 'product_id' => 40), array('Referer: https://evil.example/x.html'));
check('A-7 foreign Referer refused', errCode($r) === 'forbidden', json_encode($r));
$r = postJ($store0, 'cart', array('token' => $t, 'product_id' => 40), array('Origin: http://127.0.0.1:8080'));
check('A-7 own Origin accepted', !empty($r['ok']), json_encode($r));
$r = postJ($store0, 'forget', array('token' => $t), array('Origin: null'));
check('A-7 Origin null refused (forget)', errCode($r) === 'forbidden', json_encode($r));
$r = json_decode(http('POST', ep($store0, 'send'), '{"token":"' . $t . '","message":"x","stream":0}', array('Content-Type: text/plain')), true);
check('A-7 send with text/plain refused', errCode($r) === 'forbidden', json_encode($r));
$png = sys_get_temp_dir() . '/aichat_sec_' . getmypid() . '.png';
$im = imagecreatetruecolor(20, 20);
imagepng($im, $png);
$r = json_decode(http('POST', ep($store0, 'upload'), array('token' => $t, 'file' => new CURLFile($png, 'image/png', 'p.png')), array('Origin: https://evil.example')), true);
check('A-7 upload from a foreign Origin refused', errCode($r) === 'forbidden', json_encode($r));

echo "--- A-8 uploads and new conversations\n";
resetRates();
$chatsBefore = (int)q("SELECT COALESCE(SUM(chats),0) AS n FROM oc_aichat_usage WHERE store_id = 0 AND day = '" . date('Y-m-d') . "'")->row['n'];
$tu = bootH($store0)['token'];
$u = json_decode(http('POST', ep($store0, 'upload'), array('token' => $tu, 'file' => new CURLFile($png, 'image/png', 'p.png'))), true);
$chatsMid = (int)q("SELECT COALESCE(SUM(chats),0) AS n FROM oc_aichat_usage WHERE store_id = 0 AND day = '" . date('Y-m-d') . "'")->row['n'];
check('A-8 upload creates the row but does not count a chat', isset($u['id']) && $chatsMid === $chatsBefore, json_encode($u));
sendM($store0, $tu, 'what is this', array('images' => array($u['id'])));
$chatsAfter = (int)q("SELECT COALESCE(SUM(chats),0) AS n FROM oc_aichat_usage WHERE store_id = 0 AND day = '" . date('Y-m-d') . "'")->row['n'];
check('A-8 counted with its first message', $chatsAfter === $chatsBefore + 1, $chatsBefore . ' -> ' . $chatsAfter);
$codes = array();
for ($i = 0; $i < 11; $i++) {
	$r = json_decode(http('POST', ep($store0, 'upload'), array('token' => bootH($store0)['token'], 'file' => new CURLFile($png, 'image/png', 'p.png'))), true);
	$codes[] = isset($r['id']) ? 'ok' : errCode($r);
}
check('A-8 conversations created by bare uploads limited per IP per day', in_array('rate_limited', $codes, true) && count(array_keys($codes, 'ok')) <= 10, implode(',', $codes));
resetRates();

echo "--- A-9 request mail caps\n";
@unlink($mailFile);
$suppressed = 0;
$sent = 0;
for ($c = 0; $c < 2; $c++) {
	$tc = bootH($store0)['token'];
	for ($i = 0; $i < 4; $i++) {
		probeT($store0, $tc, 'leave_contact', array('email' => 'v' . $c . $i . '@third-party.example', 'note' => 'call https://evil.example/x'));
	}
	foreach (q("SELECT mailed, data FROM oc_aichat_request WHERE conversation_id = " . (int)convOf($tc)['conversation_id'])->rows as $row) {
		if ((int)$row['mailed'] === 1) {
			$sent++;
		} elseif (strpos($row['data'], 'suppressed') !== false) {
			$suppressed++;
		}
	}
}
check('A-9 per-IP daily cap: ' . \Aichat\Requests::MAIL_IP_DAY . ' mailed, the rest recorded as suppressed', $sent === \Aichat\Requests::MAIL_IP_DAY && $suppressed === 8 - \Aichat\Requests::MAIL_IP_DAY, $sent . '/' . $suppressed);
$mail = mailText();
check('L4 store mail marks visitor text unverified and defangs links', strpos($mail, 'unverified') !== false && strpos($mail, 'Visitor (unverified)') !== false && strpos($mail, 'https://evil.example') === false && strpos($mail, 'hxxps://evil[.]example') !== false, substr($mail, 0, 1500));
resetRates();
@unlink($mailFile);
counter('rm:s:0:' . date('Y-m-d'), 86400, \Aichat\Requests::MAIL_STORE_DAY);
$tc = bootH($store0)['token'];
probeT($store0, $tc, 'leave_contact', array('email' => 'a@example.com'));
probeT($store0, $tc, 'leave_contact', array('email' => 'b@example.com'));
$mail = mailText();
check('A-9 store cap: no request mail, exactly one notice mail', substr_count($mail, 'please contact the customer') === 0 && substr_count($mail, 'daily limit of request e-mails reached') === 1, $mail);
resetRates();

echo "--- A-10 forget\n";
$tf = bootH($store0)['token'];
probeT($store0, $tf, 'leave_contact', array('email' => 'forgetme@example.com', 'phone' => '0888 999 777', 'note' => 'private'));
probeT($store0, $tf, 'find_order', array('order_id' => '9001', 'phone' => '0888123456'));
$uv = json_decode(http('POST', ep($store0, 'upload'), array('token' => $tf, 'file' => new CURLFile($png, 'image/png', 'p.png'))), true);
probeT($store0, $tf, 'return_request', array('order_id' => '9001', 'product' => 'iphone', 'reason' => 'damaged', 'details' => 'my private words'));
$cid = (int)convOf($tf)['conversation_id'];
$ids = array();
foreach (q("SELECT request_id, type FROM oc_aichat_request WHERE conversation_id = " . $cid)->rows as $row) {
	$ids[$row['request_id']] = $row['type'];
}
postJ($store0, 'forget', array('token' => $tf));
$left = $ids ? q("SELECT * FROM oc_aichat_request WHERE request_id IN (" . implode(',', array_keys($ids)) . ")")->rows : array();
$okContact = true;
$okOrder = false;
foreach ($left as $row) {
	if (!in_array($row['type'], array('return', 'order'), true)) {
		$okContact = false;
	} else {
		$okOrder = (int)$row['order_id'] === 9001 && $row['email'] === '' && $row['phone'] === '' && strpos($row['data'], 'private') === false && (int)$row['conversation_id'] === 0;
	}
}
check('A-10 contact rows deleted', in_array('contact', $ids, true) && $okContact, json_encode($ids) . json_encode($left));
check('A-10 return row keeps only order id / type / status / date', in_array('return', $ids, true) && $okOrder, json_encode($left));

echo "--- A-11 token out of URLs\n";
resetRates();
$t = bootH($store0)['token'];
sendM($store0, $t, 'remember me');
$b = bootH($store0, $t);
check('A-11 boot with the X-Aichat-Token header resumes the history', $b['token'] === $t && count($b['history']) >= 1, json_encode(array_keys($b)));
$r = json_decode(http('GET', ep($store0, 'poll') . '&token=' . rawurlencode($t)), true);
check('A-11 poll with the token in the URL refused', errCode($r) === 'invalid_token', json_encode($r));
$r = json_decode(http('GET', ep($store0, 'poll'), null, array('X-Aichat-Token: ' . $t)), true);
check('A-11 poll with the header works', isset($r['mode']), json_encode($r));
$u = json_decode(http('POST', ep($store0, 'upload'), array('token' => $t, 'file' => new CURLFile($png, 'image/png', 'p.png'))), true);
check('A-11 image URL carries no token', isset($u['thumb']) && strpos($u['thumb'], $t) === false && strpos($u['thumb'], 'token=') === false && strpos($u['thumb'], '&s=') !== false, json_encode($u));
http('GET', $u['thumb'], null, array(), $code);
check('A-11 signed image URL served', $code === 200, $code);
http('GET', preg_replace('/&s=[^&]+/', '&s=AAAAAAAAAAAAAAAAAAAAAA', $u['thumb']), null, array(), $code);
check('A-11 forged signature -> 404', $code === 404, $code);
http('GET', preg_replace('/&i=\d+/', '&i=' . ($u['id'] - 1), $u['thumb']), null, array(), $code);
check('A-11 signature bound to the image id', $code === 404, $code);
http('GET', str_replace($store0, $store1, $u['thumb']), null, array(), $code);
check('A-11 store 0 image not served by store 1', $code === 404, $code);
$g = new \Aichat\Guard($registry, settings());
$old = time() - 10;
http('GET', ep($store0, 'image') . '&i=' . $u['id'] . '&e=' . $old . '&t=1&s=' . $g->imageSig($u['id'], $old, true), null, array(), $code);
check('A-11 expired image URL -> 404', $code === 404, $code);
http('GET', ep($store0, 'image') . '&token=' . rawurlencode($t) . '&id=' . $u['id'] . '&thumb=1', null, array(), $code);
check('A-11 old token+id image URL no longer served', $code === 404, $code);

echo "--- A-12 poll / boot counters\n";
resetRates();
$before = (int)q("SELECT COUNT(*) AS n FROM oc_aichat_rate")->row['n'];
for ($i = 0; $i < 10; $i++) {
	http('GET', ep($store0, 'poll'), null, array('X-Aichat-Token: ' . bootH($store0)['token']));
}
$after = (int)q("SELECT COUNT(*) AS n FROM oc_aichat_rate")->row['n'];
check('A-12 polls of tokens without a conversation write no counters (only the per-IP boot key)', $after - $before <= 1, $after - $before);
if (time() % 60 > 55) {
	sleep(6); // not across a minute window boundary
}
counter('b:i:127.0.0.1', 60, 120);
http('GET', ep($store0, 'boot'), null, array(), $code);
check('A-12 boot per-IP limit', $code === 429, $code);
resetRates();

echo "--- A-13 big image\n";
$bomb = sys_get_temp_dir() . '/aichat_bomb_' . getmypid() . '.png';
$im = imagecreatetruecolor(4000, 4000);
imagepng($im, $bomb, 9);
imagedestroy($im);
$r = json_decode(http('POST', ep($store0, 'upload'), array('token' => bootH($store0)['token'], 'file' => new CURLFile($bomb, 'image/png', 'b.png'))), true);
check('A-13 16 MP PNG (47 KB) refused before decoding', errCode($r) === 'image_too_large', json_encode($r));
@unlink($bomb);
resetRates();

echo "--- A-15 / A-16 Telegram\n";
// let the store generate its secret (if it has none yet), then read it
http('POST', ep($store0, 'telegram'), '{}', array('Content-Type: application/json'));
$row = q("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_g_tg_secret'")->row;
$secret = $row ? (string)$row['value'] : '';
$hadToken = settings()->getNamedSecret('tg_token');
settings()->setSecret('tg_token', '123456:TEST-token-abcdefghijklmnop');
setting(0, 'g_tg_chat_id', '-1009990001');
$tw = bootH($store0)['token'];
sendM($store0, $tw, 'hello');
q("UPDATE oc_aichat_conversation SET mode = 'waiting', waiting_since = NOW(), tg_chat_id = '-1009990001', tg_topic_id = 4242, staff_name = '' WHERE public_id = '" . substr($tw, 0, 32) . "'");
$hook = function (array $update, array $headers, $path = null) use ($store0) {
	$code = 0;
	$out = http('POST', ep($store0, 'telegram') . ($path !== null ? '&s=' . rawurlencode($path) : ''), json_encode($update), array_merge(array('Content-Type: application/json'), $headers), $code);
	return array($code, json_decode($out, true));
};
$msg = function ($fromId, $text) {
	return array('update_id' => mt_rand(1, 2000000000), 'message' => array('chat' => array('id' => -1009990001), 'message_thread_id' => 4242, 'text' => $text, 'from' => array('id' => $fromId, 'first_name' => 'Staff' . $fromId)));
};
list($code) = $hook($msg(111, 'ping'), array('X-Telegram-Bot-Api-Secret-Token: ' . $secret));
check('A-15 header secret alone authenticates (no secret in the URL)', $code === 200, $code);
list($code) = $hook($msg(111, 'ping'), array(), $secret);
check('A-15 URL secret without the header refused', $code === 403, $code);
list($code) = $hook($msg(111, 'ping'), array('X-Telegram-Bot-Api-Secret-Token: ' . $secret), 'wrong');
check('A-15 wrong URL secret (old webhook format) refused', $code === 403, $code);
$tg = new \Aichat\Telegram(settings());
check('A-15 webhookUrl has no secret', strpos($tg->webhookUrl('https://shop.bg/'), '&s=') === false);
q("UPDATE oc_aichat_conversation SET mode = 'waiting', staff_name = '' WHERE public_id = '" . substr($tw, 0, 32) . "'");
setting(0, 'g_tg_staff_ids', array('111'));
$staffBefore = (int)q("SELECT COUNT(*) AS n FROM oc_aichat_message WHERE conversation_id = " . (int)convOf($tw)['conversation_id'] . " AND role = 'staff'")->row['n'];
list($code, $res) = $hook($msg(222, 'I am not staff'), array('X-Telegram-Bot-Api-Secret-Token: ' . $secret));
$staffRows = -$staffBefore + (int)q("SELECT COUNT(*) AS n FROM oc_aichat_message WHERE conversation_id = " . (int)convOf($tw)['conversation_id'] . " AND role = 'staff'")->row['n'];
check('A-16 user not on the staff list ignored', isset($res['result']) && $res['result'] === 'forbidden' && $staffRows === 0 && convOf($tw)['mode'] === 'waiting', json_encode($res));
list($code, $res) = $hook(array('update_id' => mt_rand(1, 2000000000), 'callback_query' => array('id' => 'cb1', 'data' => 'take:' . (int)convOf($tw)['conversation_id'], 'from' => array('id' => 222, 'first_name' => 'X'), 'message' => array('chat' => array('id' => -1009990001), 'message_thread_id' => 4242))), array('X-Telegram-Bot-Api-Secret-Token: ' . $secret));
check('A-16 Take button of a non-staff user ignored', isset($res['result']) && $res['result'] === 'forbidden' && convOf($tw)['mode'] === 'waiting', json_encode($res));
list($code, $res) = $hook($msg(111, 'Hello, I am here'), array('X-Telegram-Bot-Api-Secret-Token: ' . $secret));
check('A-16 listed staff member answers', isset($res['result']) && $res['result'] === 'staff' && convOf($tw)['mode'] === 'human', json_encode($res));
$logFile = $oc . '/system/storage/logs/error.log';
$tail = is_file($logFile) ? (string)file_get_contents($logFile) : '';
check('A-16 the taker\'s Telegram user id is logged', strpos($tail, 'taken by Telegram user id 111') !== false);
// L4: visitor text forwarded to Telegram is marked and defanged
http('POST', 'http://127.0.0.1:8098/tg-reset', '');
sendM($store0, $tw, 'pay at https://evil.example/pay please');
$calls = json_decode(http('GET', 'http://127.0.0.1:8098/tg-log'), true);
$texts = '';
foreach (isset($calls['calls']) ? $calls['calls'] : array() as $c) {
	if ($c['method'] === 'sendMessage') {
		$texts .= $c['params']['text'] . "\n";
	}
}
check('L4 visitor text to Telegram marked unverified and defanged', strpos($texts, 'Visitor (unverified)') !== false && strpos($texts, 'hxxps://evil[.]example/pay') !== false && strpos($texts, 'https://evil.example') === false, $texts);
q("UPDATE oc_aichat_conversation SET mode = 'bot', waiting_since = NULL WHERE public_id = '" . substr($tw, 0, 32) . "'");
settings()->setSecret('tg_token', $hadToken);
restoreSettings();

@unlink($png);
restoreSettings();
resetRates();

echo "\n" . $passes . " passed, " . $fails . " failed\n";
exit($fails ? 1 : 0);
