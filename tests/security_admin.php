<?php
/**
 * Security regression tests for the admin side (audit B, round 1.2.0).
 *
 *   SMOKE_DB=localhost:oc:oc:oc:oc_ SMOKE_OC=<rig>/oc php tests/security_admin.php http://127.0.0.1:8080/
 *
 * Creates a temporary access-only admin user (deleted again), restores every
 * setting / row / file it touches. Never confirms the purge. Exit 1 on failure.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$s0 = isset($argv[1]) ? rtrim($argv[1], '/') . '/' : 'http://127.0.0.1:8080/';
$oc = rtrim((string)getenv('SMOKE_OC'), '/');
$fails = 0;

if (!getenv('SMOKE_DB') || $oc === '') {
	fwrite(STDERR, "SMOKE_DB and SMOKE_OC are required\n");
	exit(1);
}

list($dbHost, $dbUser, $dbPass, $dbName, $pfx) = explode(':', getenv('SMOKE_DB'));
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
$db->set_charset('utf8mb4');

function q($sql) {
	global $db, $pfx;
	$r = $db->query(str_replace('oc_', $pfx, $sql));
	if ($r === false) {
		fwrite(STDERR, 'SQL error: ' . $db->error . "\n" . $sql . "\n");
		exit(1);
	}
	return $r;
}

function one($sql) {
	$row = q($sql)->fetch_row();
	return $row ? $row[0] : null;
}

function check($name, $ok, $detail = '') {
	global $fails;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . substr($detail, 0, 800)) . "\n";
	$fails += $ok ? 0 : 1;
}

/** @return array(body, headers) */
function req($url, $jar, $post = null, array $headers = array()) {
	$ch = curl_init($url);
	$hdr = '';
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers,
		CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdr) {
			$hdr .= $line;
			return strlen($line);
		}));
	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post instanceof FormPost ? $post->encode() : (is_array($post) ? http_build_query($post) : $post));
	}
	$body = (string)curl_exec($ch);
	curl_close($ch);
	return array($body, $hdr);
}

function login($user, $pass) {
	global $s0;
	$jar = tempnam(sys_get_temp_dir(), 'aichat_adm');
	req($s0 . 'admin/index.php?route=common/login', $jar);
	list(, $hdr) = req($s0 . 'admin/index.php?route=common/login', $jar, array('username' => $user, 'password' => $pass));
	return array($jar, preg_match('/user_token=([A-Za-z0-9]+)/', $hdr, $m) ? $m[1] : '');
}

function page($session, $route, $post = null) {
	global $s0;
	return req($s0 . 'admin/index.php?route=' . $route . '&user_token=' . $session[1], $session[0], $post);
}

/** Every successful control of a form, as the browser would post it. */
function formFields($html, $id) {
	$doc = new DOMDocument();
	@$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
	$form = $doc->getElementById($id);
	$out = array();
	if (!$form) {
		return $out;
	}
	foreach ($form->getElementsByTagName('input') as $in) {
		$name = $in->getAttribute('name');
		$type = strtolower($in->getAttribute('type'));
		if ($name === '' || in_array($type, array('submit', 'button', 'file'), true) || (in_array($type, array('checkbox', 'radio'), true) && !$in->hasAttribute('checked'))) {
			continue;
		}
		$out[] = array($name, $in->getAttribute('value'));
	}
	foreach ($form->getElementsByTagName('textarea') as $ta) {
		if ($ta->getAttribute('name') !== '') {
			$out[] = array($ta->getAttribute('name'), $ta->textContent);
		}
	}
	foreach ($form->getElementsByTagName('select') as $sel) {
		$name = $sel->getAttribute('name');
		if ($name === '') {
			continue;
		}
		$first = null;
		$any = false;
		foreach ($sel->getElementsByTagName('option') as $opt) {
			$first = $first === null ? $opt->getAttribute('value') : $first;
			if ($opt->hasAttribute('selected')) {
				$out[] = array($name, $opt->getAttribute('value'));
				$any = true;
			}
		}
		if (!$any && !$sel->hasAttribute('multiple') && $first !== null) {
			$out[] = array($name, $first);
		}
	}
	// last value wins for scalar names (hidden 0 + checked checkbox 1), [] names repeat
	$flat = array();
	foreach ($out as $pair) {
		if (substr($pair[0], -2) === '[]') {
			$flat[] = $pair;
		} else {
			$flat[$pair[0]] = $pair;
		}
	}
	$post = array();
	foreach ($flat as $pair) {
		$post[] = $pair;
	}
	return new FormPost($post);
}

/** Ordered name=value list that also allows $form['name'] = value. */
class FormPost implements ArrayAccess {
	public $pairs;
	public function __construct(array $pairs) {
		$this->pairs = $pairs;
	}
	public function offsetExists($k) {
		foreach ($this->pairs as $p) {
			if ($p[0] === $k) {
				return true;
			}
		}
		return false;
	}
	public function offsetGet($k) {
		foreach ($this->pairs as $p) {
			if ($p[0] === $k) {
				return $p[1];
			}
		}
		return null;
	}
	public function offsetSet($k, $v) {
		foreach ($this->pairs as $i => $p) {
			if ($p[0] === $k) {
				$this->pairs[$i][1] = $v;
				return;
			}
		}
		$this->pairs[] = array($k, $v);
	}
	public function offsetUnset($k) {
	}
	public function encode() {
		$parts = array();
		foreach ($this->pairs as $p) {
			$parts[] = rawurlencode($p[0]) . '=' . rawurlencode($p[1]);
		}
		return implode('&', $parts);
	}
}

// library access for the secret store (same DIR_STORAGE as the rig)
define('DIR_STORAGE', $oc . '/system/storage/');
define('DB_PREFIX', $pfx);
require $oc . '/system/library/db.php';
require $oc . '/system/library/db/mysqli.php';
spl_autoload_register(function ($class) use ($oc) {
	$file = $oc . '/system/library/' . str_replace('\\', '/', strtolower($class)) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
class SecTestRegistry {
	private $d = array();
	public function get($k) {
		return isset($this->d[$k]) ? $this->d[$k] : null;
	}
	public function set($k, $v) {
		$this->d[$k] = $v;
	}
}
$registry = new SecTestRegistry();
$registry->set('db', new DB('mysqli', $dbHost, $dbUser, $dbPass, $dbName, 3306));
$secretFile = \Aichat\Settings::secretFile('turnstile');
$secretBefore = is_file($secretFile) ? (string)file_get_contents($secretFile) : null;
$dbSecretBefore = one("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_g_turnstile_secret'");

// temporary access-only user
q("INSERT INTO oc_user_group SET name = 'aichat_sec_access', permission = '{\"access\":[\"extension/module/aichat\",\"common/dashboard\"],\"modify\":[]}'");
$group = (int)$db->insert_id;
q("INSERT INTO oc_user SET user_group_id = " . $group . ", username = 'aichat_sec_access', password = MD5('sec-pw-1'), salt = '', firstname = 'Sec', lastname = 'Test', email = 'sec@example.com', image = '', code = '', ip = '', status = 1, date_added = NOW()");
$userId = (int)$db->insert_id;

$admin = login('admin', 'admin');
$low = login('aichat_sec_access', 'sec-pw-1');
check('logins', $admin[1] !== '' && $low[1] !== '');

try {
	// ---------------------------------------------------------- L2: Turnstile secret write-only
	echo "== L2: Turnstile secret\n";
	$settings = new \Aichat\Settings($registry);
	$settings->setTurnstileSecret('SEC-TEST-TS-SECRET-123');
	list($html) = page($low, 'extension/module/aichat');
	check('L2: access-only user does not get the Turnstile secret in the settings page', strpos($html, 'SEC-TEST-TS-SECRET-123') === false && strpos($html, 'name="ts_secret"') !== false, substr($html, 0, 300));
	list($html) = page($admin, 'extension/module/aichat');
	check('L2: nor does the full admin (write-only field, "saved" shown)', strpos($html, 'SEC-TEST-TS-SECRET-123') === false && preg_match('/type="password" name="ts_secret"/', $html));
	check('L2: the catalog side still reads it through Settings::getGlobal(\'g_turnstile_secret\')', (new \Aichat\Settings($registry))->getGlobal('g_turnstile_secret') === 'SEC-TEST-TS-SECRET-123');
	check('L2: not part of getGlobal() (whole array)', strpos(json_encode((new \Aichat\Settings($registry))->getGlobal()), 'SEC-TEST-TS-SECRET') === false);

	// upgrade from schema 4: a secret still in the settings table moves into the secret file
	$settings->setTurnstileSecret('');
	q("DELETE FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_g_turnstile_secret'");
	q("INSERT INTO oc_setting SET store_id = 0, code = 'module_aichat', `key` = 'module_aichat_g_turnstile_secret', value = 'LEGACY-TS-SECRET-456', serialized = 0");
	q("UPDATE oc_setting SET value = '4' WHERE store_id = 0 AND `key` = 'module_aichat_g_schema'");
	page($admin, 'extension/module/aichat/dashboard'); // admin pages run Installer::upgrade()
	$moved = (new \Aichat\Settings($registry))->getNamedSecret('turnstile');
	check('L2: upgrade() moved the stored secret into the secret file and blanked the setting', $moved === 'LEGACY-TS-SECRET-456' && (string)one("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_g_turnstile_secret'") === '' && (int)one("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_g_schema'") === 5, 'file=' . $moved);

	// ---------------------------------------------------------- I2 / I7 / I8: transcript page
	echo "== I2 / I7 / I8: pages\n";
	$ua = 'SecUA <b>bold</b> & "q"';
	list($bootOut) = req($s0 . 'index.php?route=extension/module/aichat/boot&url=x', tempnam(sys_get_temp_dir(), 'c'), null, array('User-Agent: ' . $ua));
	$token = json_decode($bootOut, true)['token'];
	q("DELETE FROM oc_aichat_rate");
	req($s0 . 'index.php?route=extension/module/aichat/send', tempnam(sys_get_temp_dir(), 'c'), json_encode(array('token' => $token, 'message' => 'tool:list_manufacturers {}', 'url' => 'https://visitor-link.example/page?x=1', 'stream' => 0)), array('Content-Type: application/json', 'User-Agent: ' . $ua));
	$cid = (int)one("SELECT conversation_id FROM oc_aichat_conversation WHERE public_id = '" . substr($token, 0, 32) . "'");
	list($html, $hdr) = page($admin, 'extension/module/aichat/conversation&conversation_id=' . $cid);
	check('I2: Referrer-Policy: same-origin on the transcript page', stripos($hdr, 'Referrer-Policy: same-origin') !== false, $hdr);
	check('I2: visitor-supplied links open with rel="noopener noreferrer"', preg_match('~href="https&#x3A;&#x2F;&#x2F;visitor-link\.example[^"]*" target="_blank" rel="noopener noreferrer"~', $html) === 1 && strpos($html, 'rel="noopener">') === false);
	check('I7: user agent shown escaped once (not "&amp;lt;")', strpos($html, 'SecUA &lt;b&gt;bold&lt;/b&gt; &amp; &quot;q&quot;') !== false && strpos($html, '&amp;lt;b') === false);
	check('I8: privacy note about access-only users on the transcript page', strpos($html, 'aichat-small"><i class="fa fa-user-secret"></i>') !== false);
	foreach (array('extension/module/aichat', 'extension/module/aichat/conversations', 'extension/module/aichat/requests', 'extension/module/aichat/dashboard', 'extension/module/aichat/stats') as $route) {
		list(, $hdr) = page($admin, $route);
		check('I2: Referrer-Policy on ' . $route, stripos($hdr, 'Referrer-Policy: same-origin') !== false);
	}
	q("DELETE FROM oc_aichat_message WHERE conversation_id = " . $cid);
	q("DELETE FROM oc_aichat_conversation WHERE conversation_id = " . $cid);

	// ---------------------------------------------------------- g_tg_staff_ids field + recent takers
	echo "== Telegram staff ids\n";
	q("INSERT INTO oc_aichat_message SET conversation_id = 0, role = 'system', content = 'x joined', meta = '{\"handover\":\"take\",\"staff_name\":\"SecTaker P.\",\"staff_id\":\"424242\"}', date_added = NOW()");
	$takerMsg = (int)$db->insert_id;
	list($html) = page($admin, 'extension/module/aichat');
	q("DELETE FROM oc_aichat_message WHERE message_id = " . $takerMsg);
	check('g_tg_staff_ids: field in the Telegram tab', strpos($html, 'name="g[g_tg_staff_ids]"') !== false);
	check('g_tg_staff_ids: recent takers listed with their id', strpos($html, 'SecTaker P.') !== false && strpos($html, '424242') !== false);
	check('g_tg_staff_ids: default is an empty list', \Aichat\Settings::globalDefaults()['g_tg_staff_ids'] === array());
	check('I8: privacy note on the settings page', strpos($html, 'fa-user-secret') !== false);

	// ---------------------------------------------------------- the real settings form: save path of L2 / I5
	echo "== settings form save\n";
	list($html) = page($admin, 'extension/module/aichat');
	$form = formFields($html, 'form-aichat');
	// the whole form is saved: snapshot every module setting and put it back afterwards
	$snapshot = array();
	$r = q("SELECT store_id, `key`, value, serialized FROM oc_setting WHERE code = 'module_aichat'");
	while ($row = $r->fetch_assoc()) {
		$snapshot[] = $row;
	}
	foreach (array(0, 1) as $sid) {
		// other test files may run with 0 (= off) here, which the form rejects
		if ((int)$form['s[' . $sid . '][s_max_msgs_minute]'] < 1) {
			$form['s[' . $sid . '][s_max_msgs_minute]'] = '6';
		}
	}
	$cssBefore = one("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_s_custom_css'");
	$form['ts_secret'] = 'FORM-TS-SECRET-789';
	$form['s[0][s_custom_css]'] = '.aichat-root ~ *{display:none} .x{background:url(https://evil.example/p.png);color:red}';
	$form['g[g_tg_staff_ids]'] = "123456\n 7890123 ";
	list($saved, $hdr) = page($admin, 'extension/module/aichat', $form);
	$fresh = new \Aichat\Settings($registry);
	$storedCss = (string)one("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_s_custom_css'");
	preg_match_all('~class="(?:text-danger[^"]*|alert alert-danger[^"]*)"[^>]*>(.*?)<~s', $saved, $em);
	check('settings form saves (redirect after POST)', stripos($hdr, 'Location:') !== false, implode(' | ', array_map('trim', $em[1])));
	check('L2: secret typed in the form goes to the secret file, not the settings table', $fresh->getNamedSecret('turnstile') === 'FORM-TS-SECRET-789' && strpos((string)one("SELECT GROUP_CONCAT(value) FROM oc_setting WHERE code = 'module_aichat'"), 'FORM-TS-SECRET') === false);
	check('I5: custom CSS is sanitised when saved', strpos($storedCss, 'evil') === false && strpos($storedCss, '~') === false && strpos($storedCss, '.aichat-root .x{color:red}') !== false, $storedCss);
	check('g_tg_staff_ids saved as a list of numeric ids', $fresh->getGlobal('g_tg_staff_ids') === array('123456', '7890123'), json_encode($fresh->getGlobal('g_tg_staff_ids')));
	$form['ts_secret'] = '';
	$form['g[g_tg_staff_ids]'] = 'abc';
	list($bad) = page($admin, 'extension/module/aichat', $form);
	check('g_tg_staff_ids: non-numeric ids are rejected', strpos($bad, 'Only numeric Telegram user ids') !== false || strpos($bad, 'Само числови Telegram ID') !== false);
	q("DELETE FROM oc_setting WHERE code = 'module_aichat'");
	foreach ($snapshot as $row) {
		q("INSERT INTO oc_setting SET store_id = " . (int)$row['store_id'] . ", code = 'module_aichat', `key` = '" . $db->real_escape_string($row['key']) . "', value = '" . $db->real_escape_string($row['value']) . "', serialized = " . (int)$row['serialized']);
	}
	check('restored: module settings as before the form test', (int)one("SELECT COUNT(*) FROM oc_setting WHERE code = 'module_aichat'") === count($snapshot));

	// ---------------------------------------------------------- I6: purge needs modify + typed word
	echo "== I6: delete all data\n";
	$convs = (int)one("SELECT COUNT(*) FROM oc_aichat_conversation");
	page($low, 'extension/module/aichat/purge', array('confirm' => 'DELETE'));
	check('I6: access-only user cannot purge', (int)one("SELECT COUNT(*) FROM oc_aichat_conversation") === $convs);
	list($html) = page($admin, 'extension/module/aichat/purge', array('confirm' => 'delete'));
	check('I6: purge without the exact typed word deletes nothing', (int)one("SELECT COUNT(*) FROM oc_aichat_conversation") === $convs);
	list($html) = page($admin, 'extension/module/aichat');
	check('I6: purge form with typed confirmation on the settings page', strpos($html, 'id="aichat-purge"') !== false && strpos($html, 'name="confirm"') !== false);
	$ctrl = (string)file_get_contents($oc . '/admin/controller/extension/module/aichat.php');
	check('I6: OpenCart\'s Uninstall keeps the data (uninstall($registry, false))', strpos($ctrl, 'Installer::uninstall($this->registry, false)') !== false);
} finally {
	// restore
	$settings = new \Aichat\Settings($registry);
	$settings->setTurnstileSecret('');
	if ($secretBefore !== null) {
		file_put_contents($secretFile, $secretBefore);
	}
	q("DELETE FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_g_turnstile_secret'");
	q("INSERT INTO oc_setting SET store_id = 0, code = 'module_aichat', `key` = 'module_aichat_g_turnstile_secret', value = '" . $db->real_escape_string((string)$dbSecretBefore) . "', serialized = 0");
	q("DELETE FROM oc_user WHERE user_id = " . $userId);
	q("DELETE FROM oc_user_group WHERE user_group_id = " . $group);
}

echo $fails ? "\n$fails FAILED\n" : "\nall ok\n";
exit($fails ? 1 : 0);
