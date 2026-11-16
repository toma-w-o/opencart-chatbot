<?php
/**
 * Mail path through OpenCart's real Mail / Mail\Smtp classes (no
 * AICHAT_MAIL_FILE shortcut) against tests/fake_smtp.php, with the settings
 * in the database: store 0 -> port P0, store 1 overrides its own port.
 *
 *   php tests/mail_smtp_test.php <oc dir> <db host> <user> <pass> <db> <prefix> <smtp log> <port store 0>
 *
 * Leaves store 0 on smtp:P0 and store 1 on smtp:P0+1 (the admin browser test uses them).
 */
list(, $oc, $host, $user, $pass, $name, $prefix, $smtpLog, $port0) = $argv + array_fill(0, 9, '');
$port0 = (int)$port0;
$port1 = $port0 + 1;
error_reporting(E_ALL & ~E_DEPRECATED);
define('DB_PREFIX', $prefix);
define('DIR_STORAGE', rtrim($oc, '/') . '/system/storage/');
define('DIR_SYSTEM', rtrim($oc, '/') . '/system/');
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
class TestConfig {
	private $data = array();
	public function get($k) {
		return isset($this->data[$k]) ? $this->data[$k] : null;
	}
	public function set($k, $v) {
		$this->data[$k] = $v;
	}
}
class TestLog {
	public $lines = array();
	public function write($m) {
		$this->lines[] = $m;
	}
}

$db = new DB('mysqli', $host, $user, $pass, $name, 3306);
$registry = new TestRegistry();
$registry->set('db', $db);
$config = new TestConfig();
$config->set('config_store_id', 0); // like the admin / a webhook: the request runs under store 0
$config->set('config_mail_engine', 'mail');
$registry->set('config', $config);
$log = new TestLog();
$registry->set('log', $log);
$fails = 0;
function check($label, $ok, $detail = '') {
	global $fails;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	$fails += $ok ? 0 : 1;
}
function cfg($db, $storeId, $key, $value) {
	$db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '" . (int)$storeId . "' AND `code` = 'config' AND `key` = '" . $db->escape($key) . "'");
	if ($value !== null) {
		$db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '" . (int)$storeId . "', `code` = 'config', `key` = '" . $db->escape($key) . "', `value` = '" . $db->escape($value) . "', `serialized` = '0'");
	}
}
function smtpLog($file) {
	clearstatcache();
	return is_file($file) ? (string)file_get_contents($file) : '';
}
function lastMessage($file) {
	$all = smtpLog($file);
	$parts = explode('=== ', $all);
	return (string)end($parts);
}

// OpenCart stores admin form input HTML-escaped: the password "p&ss<1>" is in the database as below
foreach (array(0 => $port0, 1 => $port1) as $sid => $port) {
	cfg($db, $sid, 'config_mail_engine', 'smtp');
	cfg($db, $sid, 'config_mail_smtp_hostname', '127.0.0.1');
	cfg($db, $sid, 'config_mail_smtp_port', (string)$port);
	cfg($db, $sid, 'config_mail_smtp_username', 'mailer' . $sid);
	cfg($db, $sid, 'config_mail_smtp_password', 'p&amp;ss&lt;1&gt;');
	cfg($db, $sid, 'config_mail_smtp_timeout', '5');
}
cfg($db, 1, 'config_email', 'second@example.com');

$settings = new \Aichat\Settings($registry);
@unlink($smtpLog);

$r = \Aichat\Mailer::deliver($registry, 'owner@example.com', 'AI Chat: тест – заявка #1', "Здравейте,\nтест.\n", array('store_id' => 0, 'reply_to' => 'customer@example.com', 'sender' => 'Магазин · AI Chat'));
$m = lastMessage($smtpLog);
check('smtp store 0: delivered through Mail\\Smtp', $r['ok'] === true && $r['engine'] === 'smtp' && strpos($m, (string)$port0 . ' ') === 0, json_encode($r) . "\n" . $m);
check('smtp: envelope + headers as OpenCart builds them', strpos($m, 'RCPT TO: <owner@example.com>') !== false && strpos($m, 'MAIL FROM: <admin@example.com>') !== false && strpos($m, 'Subject: =?UTF-8?B?' . base64_encode('AI Chat: тест – заявка #1') . '?=') !== false && strpos($m, 'From: =?UTF-8?B?' . base64_encode('Магазин · AI Chat') . '?= <admin@example.com>') !== false && strpos($m, 'Reply-To: =?UTF-8?B?' . base64_encode('customer@example.com') . '?= <customer@example.com>') !== false, $m);
check('smtp: AUTH with the entity-decoded password accepted', strpos($m, 'AUTH: mailer0') !== false && $r['ok']);
check('smtp: UTF-8 body', strpos($m, "Здравейте,\nтест.") !== false);

$r = \Aichat\Mailer::deliver($registry, 'owner@example.com', 'Store one mail', "x\n", array('store_id' => 1));
$m = lastMessage($smtpLog);
check('store 1 mail uses store 1\'s own SMTP settings while running under store 0', $r['ok'] && strpos($m, (string)$port1 . ' ') === 0 && strpos($m, 'AUTH: mailer1') !== false && strpos($m, 'MAIL FROM: <second@example.com>') !== false, $m);

cfg($db, 0, 'config_mail_smtp_port', '1');
$log->lines = array();
$r = \Aichat\Mailer::deliver($registry, 'owner@example.com', 'dead port', "x\n", array('store_id' => 0));
check('smtp server down: not ok, error text, logged', $r['ok'] === false && stripos($r['error'], 'refused') !== false && $log->lines && strpos($log->lines[0], 'AI Chat mail not sent (smtp)') === 0, json_encode($r) . json_encode($log->lines));
cfg($db, 0, 'config_mail_smtp_port', (string)$port0);

$r = \Aichat\Mailer::deliver($registry, 'reject@example.com', 'rejected', "x\n", array('store_id' => 0));
check('smtp RCPT rejected: OpenCart exception reported', $r['ok'] === false && strpos($r['error'], 'RCPT TO not accepted') !== false, json_encode($r));

cfg($db, 0, 'config_mail_smtp_password', 'bad-password');
$r = \Aichat\Mailer::deliver($registry, 'owner@example.com', 'bad auth', "x\n", array('store_id' => 0));
check('smtp wrong password reported', $r['ok'] === false && strpos($r['error'], 'Password not accepted') !== false, json_encode($r));
cfg($db, 0, 'config_mail_smtp_password', 'p&amp;ss&lt;1&gt;');

cfg($db, 0, 'config_mail_engine', 'carrierpigeon');
$r = \Aichat\Mailer::deliver($registry, 'owner@example.com', 'unknown engine', "x\n", array('store_id' => 0));
check('unknown engine: error instead of OpenCart\'s exit()', $r['ok'] === false && strpos($r['error'], 'unknown mail engine') !== false, json_encode($r));

cfg($db, 0, 'config_mail_engine', 'mail');
$sendmail = strtok((string)ini_get('sendmail_path') ?: '/usr/sbin/sendmail', ' ');
$r = \Aichat\Mailer::deliver($registry, 'owner@example.com', 'php mail', "x\n", array('store_id' => 0));
if (!is_executable($sendmail)) {
	check('engine "mail" without sendmail: reported (OpenCart ignores mail()\'s result)', $r['ok'] === false && strpos($r['error'], 'sendmail') !== false, json_encode($r));
} else {
	check('engine "mail" with sendmail present: handed to mail()', $r['engine'] === 'mail');
}
cfg($db, 0, 'config_mail_engine', 'smtp');

$r = \Aichat\Mailer::deliver($registry, 'not an address', 's', "x\n", array('store_id' => 0));
check('invalid recipient reported', $r['ok'] === false && strpos($r['error'], 'recipient') !== false);

// requests: outcome recorded on the row (mailed + data.mail_error)
$requests = new \Aichat\Requests($registry, $settings);
$settings->saveStore(1, array('s_notify_email' => 'orders@example.com'));
$id = $requests->add(array('store_id' => 1, 'conversation_id' => 0, 'type' => 'order', 'data' => array('kind' => 'cancel')));
$res = $requests->mailStore(1, 'AI Chat: order change request #' . $id, "test\n", '', array(), $id);
$row = $db->query("SELECT * FROM `" . DB_PREFIX . "aichat_request` WHERE `request_id` = '" . (int)$id . "'")->row;
$m = lastMessage($smtpLog);
check('request mail: s_notify_email of store 1, store 1 SMTP, mailed = 1', $res['ok'] && $res['to'] === 'orders@example.com' && (string)$row['mailed'] === '1' && strpos($m, (string)$port1 . ' ') === 0 && strpos($m, 'RCPT TO: <orders@example.com>') !== false, json_encode(array($res, $row)));
$settings->saveStore(1, array('s_notify_email' => ''));
$res = $requests->mailStore(1, 'fallback', "test\n", '', array(), $id);
check('request mail: empty s_notify_email -> the store\'s config_email', $res['ok'] && $res['to'] === 'second@example.com', json_encode($res));
cfg($db, 1, 'config_mail_smtp_port', '1');
$res = $requests->mailStore(1, 'fails', "test\n", '', array(), $id);
$row = $db->query("SELECT * FROM `" . DB_PREFIX . "aichat_request` WHERE `request_id` = '" . (int)$id . "'")->row;
$data = json_decode($row['data'], true);
check('request mail failure: mailed = 0, error in data, never silent', !$res['ok'] && (string)$row['mailed'] === '0' && !empty($data['mail_error']) && $data['mail_to'] === 'second@example.com' && $data['kind'] === 'cancel', json_encode($row));
cfg($db, 1, 'config_mail_smtp_port', (string)$port1);
$db->query("DELETE FROM `" . DB_PREFIX . "aichat_request` WHERE `request_id` = '" . (int)$id . "'");

// store name resolution: s_store_name -> config_name -> store.name -> host
$storeRow = $db->query("SELECT `name` FROM `" . DB_PREFIX . "store` WHERE `store_id` = 1")->row;
$cfgName = $db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = 1 AND `key` = 'config_name'")->row;
cfg($db, 1, 'config_name', '');
check('name: empty config_name -> store.name', \Aichat\StoreInfo::name($registry, 1, $settings) === $storeRow['name']);
$db->query("UPDATE `" . DB_PREFIX . "store` SET `name` = '' WHERE `store_id` = 1");
check('name: then the host without www', \Aichat\StoreInfo::name($registry, 1, $settings) === '127.0.0.1');
$settings->saveStore(1, array('s_store_name' => 'Fitball &amp; Co'));
check('name: s_store_name first (decoded)', \Aichat\StoreInfo::name($registry, 1, $settings) === 'Fitball & Co');
$settings->saveStore(1, array('s_store_name' => ''));
$db->query("UPDATE `" . DB_PREFIX . "store` SET `name` = '" . $db->escape($storeRow['name']) . "' WHERE `store_id` = 1");
cfg($db, 1, 'config_name', $cfgName ? $cfgName['value'] : 'Second Store');
check('name: own config_name', \Aichat\StoreInfo::name($registry, 1, $settings) === ($cfgName ? $cfgName['value'] : 'Second Store'));

exit($fails ? 1 : 0);
