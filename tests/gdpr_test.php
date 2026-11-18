<?php
/**
 * Round 6 (GDPR, 1.3.0) regression tests: redaction / scrub fixtures, the
 * \Aichat\Privacy data-subject functions on seeded data across two stores,
 * the retention steps with back-dated rows, Telegram topic deletion and
 * masking against the fake Telegram, the privacy_required flow, the inbound
 * scrub of `send`, data minimisation in the order tools, the consent flag
 * of the footer.
 *
 *   SMOKE_DB=localhost:oc:oc:oc:oc_ SMOKE_OC=<rig>/oc SMOKE_FAKE_LOG=<rig>/fake.log SMOKE_TG=http://127.0.0.1:8098/tg \
 *     php tests/gdpr_test.php http://127.0.0.1:8080/ http://127.0.0.1:8081/
 *
 * Needs the rig of tests/setup_oc.sh (stores 0/1, fake API, fake Telegram).
 * Seeded rows use ids 93xx and are removed; settings it changes are restored.
 * Run on its own (it resets the shared rate counters). Exit code 1 on failure.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$s0 = isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8080/';
$s1 = isset($argv[2]) ? $argv[2] : 'http://127.0.0.1:8081/';
$oc = rtrim((string)getenv('SMOKE_OC'), '/');
$fakeLog = (string)getenv('SMOKE_FAKE_LOG');
$tgBase = getenv('SMOKE_TG') ? rtrim(getenv('SMOKE_TG'), '/') : 'http://127.0.0.1:8098/tg';
$tgRoot = substr($tgBase, 0, -3);
$fails = 0;

if (!getenv('SMOKE_DB') || $oc === '' || $fakeLog === '') {
	fwrite(STDERR, "SMOKE_DB, SMOKE_OC and SMOKE_FAKE_LOG are required\n");
	exit(1);
}

list($dbHost, $dbUser, $dbPass, $dbName, $pfx) = explode(':', getenv('SMOKE_DB'));

// ---------------------------------------------------------------- in-process library (same storage as the rig)
define('DB_PREFIX', $pfx);
define('DIR_STORAGE', $oc . '/system/storage/');
define('DIR_SYSTEM', $oc . '/system/');
define('AICHAT_TELEGRAM_BASE', $tgBase);
define('HTTP_SERVER', $s0); // store 0's URL, as in the admin
define('AICHAT_MAIL_FILE', sys_get_temp_dir() . '/aichat_gdpr_mail.log');
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
	private $data = array('config_store_id' => 0, 'config_language_id' => 1);
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

$odb = new DB('mysqli', $dbHost, $dbUser, $dbPass, $dbName, 3306);
$registry = new TestRegistry();
$registry->set('db', $odb);
$registry->set('config', new TestConfig());
$registry->set('log', new TestLog());

$my = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
$my->set_charset('utf8mb4');
$my->query("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");

function q($sql) {
	global $my, $pfx;
	$r = $my->query(str_replace('oc_', $pfx, $sql));
	if ($r === false) {
		fwrite(STDERR, 'SQL error: ' . $my->error . "\n" . $sql . "\n");
		exit(1);
	}
	return $r;
}
function one($sql) {
	$row = q($sql)->fetch_row();
	return $row ? $row[0] : null;
}
function esc($s) {
	global $my;
	return $my->real_escape_string((string)$s);
}
function check($name, $ok, $detail = '') {
	global $fails;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . substr((string)$detail, 0, 1500)) . "\n";
	$fails += $ok ? 0 : 1;
}

/** Module setting; returns the previous raw row value (null = none) so it can be restored. */
$SAVED = array();
function setting($storeId, $key, $value) {
	global $SAVED;
	$full = 'module_aichat_' . $key;
	$id = $storeId . '|' . $key;
	if (!array_key_exists($id, $SAVED)) {
		$row = q("SELECT value, serialized FROM oc_setting WHERE store_id = " . (int)$storeId . " AND code = 'module_aichat' AND `key` = '" . esc($full) . "' ORDER BY setting_id DESC LIMIT 1")->fetch_assoc();
		$SAVED[$id] = $row ? $row : null;
	}
	q("DELETE FROM oc_setting WHERE store_id = " . (int)$storeId . " AND code = 'module_aichat' AND `key` = '" . esc($full) . "'");
	if ($value !== null) {
		q("INSERT INTO oc_setting SET store_id = " . (int)$storeId . ", code = 'module_aichat', `key` = '" . esc($full) . "', value = '" . esc(is_array($value) ? json_encode($value) : (string)$value) . "', serialized = " . (is_array($value) ? 1 : 0));
	}
}
function restoreSettings() {
	global $SAVED;
	foreach ($SAVED as $id => $row) {
		list($storeId, $key) = explode('|', $id, 2);
		$full = 'module_aichat_' . $key;
		q("DELETE FROM oc_setting WHERE store_id = " . (int)$storeId . " AND code = 'module_aichat' AND `key` = '" . esc($full) . "'");
		if ($row !== null) {
			q("INSERT INTO oc_setting SET store_id = " . (int)$storeId . ", code = 'module_aichat', `key` = '" . esc($full) . "', value = '" . esc($row['value']) . "', serialized = " . (int)$row['serialized']);
		}
	}
	$SAVED = array();
}
function resetRates() {
	q("DELETE FROM oc_aichat_rate");
}

$HEADERS = array();
function http($method, $url, $body = null) {
	global $HEADERS;
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60));
	$headers = $HEADERS;
	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
		$headers[] = is_string($body) ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
	}
	if ($headers) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}
	$out = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array((string)$out, $code);
}
function boot($base, $token = '', $url = '') {
	global $HEADERS;
	$saved = $HEADERS;
	if ($token !== '') {
		$HEADERS[] = 'X-Aichat-Token: ' . $token;
	}
	list($out) = http('GET', $base . 'index.php?route=extension/module/aichat/boot&url=' . rawurlencode($url));
	$HEADERS = $saved;
	return json_decode($out, true);
}
function send($base, $token, $message, array $extra = array(), $stream = false) {
	resetRates();
	list($out) = http('POST', $base . 'index.php?route=extension/module/aichat/send', array('token' => $token, 'message' => $message, 'url' => '', 'stream' => $stream ? 1 : 0) + $extra);
	if (!$stream) {
		$j = json_decode($out, true);
		return is_array($j) ? $j : array('_raw' => $out);
	}
	$events = array();
	foreach (preg_split("/\n\n/", $out) as $chunk) {
		if (preg_match('/^event: (\w+)\ndata: (.*)$/s', trim($chunk), $m)) {
			$events[] = array($m[1], json_decode($m[2], true));
		}
	}
	return array('events' => $events, 'raw' => $out);
}
function post($base, $route, array $body) {
	resetRates();
	list($out) = http('POST', $base . 'index.php?route=extension/module/aichat/' . $route, $body);
	return json_decode($out, true);
}
/** The tool result the fake model echoed back ("RESULT: {json}"). */
function toolResult($reply) {
	return preg_match('/RESULT(?:\(error\))?: (.*)$/s', (string)$reply, $m) ? json_decode($m[1], true) : null;
}
function convByToken($token) {
	return q("SELECT * FROM oc_aichat_conversation WHERE public_id = '" . esc(substr((string)$token, 0, 32)) . "'")->fetch_assoc();
}
function tgLog() {
	global $tgRoot;
	list($out) = http('GET', $tgRoot . '/tg-log');
	$s = json_decode($out, true);
	return isset($s['calls']) ? $s['calls'] : array();
}
function tgCalls($method) {
	return array_values(array_filter(tgLog(), function ($c) use ($method) {
		return $c['method'] === $method;
	}));
}
function tgReset() {
	global $tgRoot;
	http('POST', $tgRoot . '/tg-reset', '');
}
/** Request bodies the fake model API received since $offset (byte offset in the log). */
function modelRequestsSince($offset) {
	global $fakeLog;
	clearstatcache();
	$fh = fopen($fakeLog, 'r');
	fseek($fh, $offset);
	$out = array();
	while (($line = fgets($fh)) !== false) {
		$pos = strpos($line, ' /v1/messages ');
		if ($pos !== false) {
			$out[] = json_decode(substr($line, $pos + 14), true);
		}
	}
	fclose($fh);
	return $out;
}
function logSize() {
	global $fakeLog;
	clearstatcache();
	return is_file($fakeLog) ? filesize($fakeLog) : 0;
}

use Aichat\Privacy;

// ================================================================ 1. redaction fixtures
echo "== redaction / scrub fixtures\n";

$egn = '7503150005'; // valid ЕГН (1975-03-15)
check('fixture ЕГН is valid', Privacy::egnValid($egn) && !Privacy::egnValid('7503150004'));

// [text, substrings that must be gone]
$mask = array(
	array('Пишете на ivan.petrov@mail.bg', array('ivan.petrov@mail.bg')),
	array('Email: Jane.Doe+shop@Example.co.uk!', array('Jane.Doe', 'Example.co.uk')),
	array('тел. 0888 123 456', array('0888 123 456')),
	array('тел: 0888123456.', array('0888123456')),
	array('call +359 88 812 3456 today', array('812 3456')),
	array('00359888123456', array('00359888123456')),
	array('Sofia landline 02 987 6543', array('987 6543')),
	array('Greek mobile 694 412 3456', array('694 412 3456')),
	array('Αθήνα 210 1234567', array('210 1234567')),
	array('+30 6944123456', array('6944123456')),
	array('card 4111 1111 1111 1111 exp 12/27', array('4111 1111 1111 1111')),
	array('card 4111-1111-1111-1111', array('4111-1111-1111-1111')),
	array('MC 5500005555555559', array('5500005555555559')),
	array('Amex 3782 822463 10005', array('3782 822463 10005')),
	array('IBAN BG80 BNBG 9661 1020 3456 78 for the refund', array('BNBG 9661')),
	array('iban: GR1601101250000000012300695', array('GR1601101250000000012300695')),
	array('ЕГН ' . $egn . ' за фактура', array($egn)),
	array('моето егн е 7503150004', array('7503150004')),        // keyword: any 10 digits
	array('ΑΜΚΑ 01018012345', array('01018012345')),
	array('ΑΦΜ: 123456789', array('123456789')),
	array('парола: Secr3t!', array('Secr3t!')),
	array('my password is hunter22', array('hunter22')),
	array('ул. Витоша 15, ет. 3, ап. 7, гр. София', array('Витоша 15', 'ет. 3', 'ап. 7')),
	array('бул. Цариградско шосе 115Г', array('Цариградско шосе 115Г')),
	array('ж.к. Младост 1, бл. 12, вх. А', array('Младост 1', 'бл. 12', 'вх. А')),
	array('Οδός Ερμού 25, Αθήνα', array('Ερμού 25')),
	array('Deliver to 5 Main Street, Springfield', array('5 Main')),
	array('Поръчка 9001 и 4111 1111 1111 1111', array('4111 1111 1111 1111')),
);
foreach ($mask as $i => $fx) {
	$out = Privacy::redactText($fx[0]);
	$left = array();
	foreach ($fx[1] as $gone) {
		if (strpos($out, $gone) !== false) {
			$left[] = $gone;
		}
	}
	check('redact #' . ($i + 1) . ': ' . $fx[0], !$left && strpos($out, '[…]') !== false, $out);
}

// ordinary shop text that must stay exactly as it is
$keep = array(
	'Цена 1 299,00 лв., с ДДС 1 558,80 лв.',
	'Price 49.90 €, was 59.90 €; 0,99 лв. per piece',
	'Доставка на 18.11.2026 между 10:30 и 14:00',
	'Date 2026-11-18, week 47, 2 бр., 3 x 500 ml',
	'Поръчка #9001, поръчка № 123456, order 100245',
	'Tracking 63798828004, 63798837619 and 1000034300847',
	'https://nextlevel.delivery/bg/track?awb=1000031131956',
	'https://www.speedy.bg/bg/track-shipment?shipmentNumber=63798828004,63798837619',
	'Модел SKU-12345, код 8001234, EAN 3800123456789, ISBN 978-954-12-3456-7',
	'Your tracking number is 1000034300847.',
	'Площ 50 кв.м, ЖК дисплей 15.6", вход USB-C, захранващ блок 12V 2A',
	'Street food set and a road bike, 2 x 1000 mAh',
	'Топка 65 см, до 300 кг, 2 години гаранция',
	'Отстъпка 20%, код за отстъпка не се дава',
	'Размер 42, ширина 120 см, височина 75 см',
	'Обадете се между 9 и 18 ч., работим 5 дни в седмицата',
);
foreach ($keep as $i => $text) {
	$out = Privacy::redactText($text);
	check('keep #' . ($i + 1) . ': ' . $text, $out === $text, $out);
}

// inbound scrub: cards (Luhn), IBAN, ЕГН/ЛНЧ, ΑΜΚΑ, passwords - never phones, e-mails, order numbers
$sc = Privacy::scrubSensitive('Поръчка 9001, телефон 0888 123 456, имейл ivan@example.com, карта 4111 1111 1111 1111');
check('scrub: card masked, order number / phone / e-mail kept', $sc['found'] === array('card') && strpos($sc['text'], '9001') !== false && strpos($sc['text'], '0888 123 456') !== false && strpos($sc['text'], 'ivan@example.com') !== false && strpos($sc['text'], '4111') === false, json_encode($sc, JSON_UNESCAPED_UNICODE));
$sc = Privacy::scrubSensitive('карта 4111 1111 1111 1112');
check('scrub: Luhn-invalid 16 digits are not a card', $sc['found'] === array() && strpos($sc['text'], '4111 1111 1111 1112') !== false, json_encode($sc));
$sc = Privacy::scrubSensitive('ЕГН ' . $egn . ', IBAN BG80BNBG96611020345678, парола: abc12345');
check('scrub: ЕГН, IBAN, password', count(array_intersect($sc['found'], array('egn', 'iban', 'password'))) === 3 && strpos($sc['text'], $egn) === false && strpos($sc['text'], 'BNBG') === false && strpos($sc['text'], 'abc12345') === false, json_encode($sc, JSON_UNESCAPED_UNICODE));
$sc = Privacy::scrubSensitive('ΑΜΚΑ 01018012345');
check('scrub: ΑΜΚΑ', $sc['found'] === array('amka'), json_encode($sc));
// Greek mobiles / BG mobiles that happen to pass the ЕГН checksum stay (phone-shaped)
$phones = array();
for ($n = 6901000000; $n < 6901200000 && count($phones) < 3; $n += 7) {
	if (Privacy::egnValid((string)$n)) {
		$phones[] = (string)$n;
	}
}
$sc = Privacy::scrubSensitive('Поръчка 1234, телефон ' . implode(', ', $phones) . ', 0888123456, +30 6944123456, 2310123456');
check('scrub: phone numbers that also pass the ЕГН check are kept (order verification)', count($phones) === 3 && $sc['found'] === array() && strpos($sc['text'], $phones[0]) !== false, json_encode(array($phones, $sc)));
$sc = Privacy::scrubSensitive('Tracking 63798828004, 1000034300847, EAN 3800123456789, ISBN 9789541234567');
check('scrub: tracking numbers and product codes untouched', $sc['found'] === array(), json_encode($sc));
check('streetOnly: street name without number / floor', Privacy::streetOnly('ул. Витоша 15, ет. 3') === 'ул. Витоша' && Privacy::streetOnly('бул. Цариградско шосе 115Г') === 'бул. Цариградско шосе' && Privacy::streetOnly('Οδός Ερμού 25') === 'Οδός Ερμού' && Privacy::streetOnly('123 Main Street') === 'Main Street');

// ================================================================ 2. find / export / erase / restrict on seeded data
echo "== find / export / erase / restrict\n";

$settings = new \Aichat\Settings($registry);
$guard = new \Aichat\Guard($registry, $settings);

function cleanupSeed() {
	// conversations the storefront part created around the seeded orders (earlier runs included)
	foreach (q("SELECT conversation_id FROM oc_aichat_conversation WHERE verified_order_id IN (9301, 9302)")->fetch_all() as $row) {
		q("DELETE FROM oc_aichat_message WHERE conversation_id = " . (int)$row[0]);
		q("DELETE FROM oc_aichat_request WHERE conversation_id = " . (int)$row[0]);
		q("DELETE FROM oc_aichat_conversation WHERE conversation_id = " . (int)$row[0]);
	}
	q("DELETE FROM oc_aichat_message WHERE conversation_id IN (SELECT conversation_id FROM oc_aichat_conversation WHERE note = 'gdpr-test')");
	q("DELETE FROM oc_aichat_upload WHERE conversation_id IN (SELECT conversation_id FROM oc_aichat_conversation WHERE note = 'gdpr-test')");
	q("DELETE FROM oc_aichat_request WHERE `data` LIKE '%gdpr-test%' OR conversation_id IN (SELECT conversation_id FROM oc_aichat_conversation WHERE note = 'gdpr-test')");
	q("DELETE FROM oc_aichat_conversation WHERE note = 'gdpr-test' OR public_id LIKE 'gdprtest%'");
	foreach (array('order', 'order_product', 'order_total', 'order_history') as $t) {
		q("DELETE FROM oc_$t WHERE order_id IN (9301, 9302)");
	}
	q("DELETE FROM oc_customer WHERE customer_id = 9300");
	q("DELETE FROM oc_aichat_search_miss WHERE `query` LIKE 'gdpr-test%'");
	q("DELETE FROM oc_aichat_dsr WHERE `query` LIKE '%gdpr-test%'");
	q("DELETE FROM oc_aichat_access_log WHERE user_id = 9300");
}
cleanupSeed();

function seedConv($store, array $set = array()) {
	$publicId = 'gdprtest' . bin2hex(random_bytes(12));
	$cols = array_merge(array('store_id' => (int)$store, 'language_id' => 1, 'public_id' => $publicId, 'mode' => 'bot', 'note' => 'gdpr-test', 'date_added' => date('Y-m-d H:i:s'), 'date_modified' => date('Y-m-d H:i:s'), 'ip' => '10.1.2.3', 'user_agent' => 'UA test'), $set);
	$f = array();
	foreach ($cols as $k => $v) {
		$f[] = "`" . $k . "` = " . (is_int($v) ? $v : ($v === null ? 'NULL' : "'" . esc($v) . "'"));
	}
	q("INSERT INTO oc_aichat_conversation SET " . implode(', ', $f));
	return array((int)one("SELECT LAST_INSERT_ID()"), $publicId);
}
function seedMsg($cid, $role, $content, $meta = null, $ago = '') {
	q("INSERT INTO oc_aichat_message SET conversation_id = " . (int)$cid . ", role = '" . $role . "', content = '" . esc($content) . "', meta = " . ($meta === null ? 'NULL' : "'" . esc(json_encode($meta, JSON_UNESCAPED_UNICODE)) . "'") . ", date_added = NOW()" . ($ago !== '' ? " - INTERVAL " . $ago : ''));
	return (int)one("SELECT LAST_INSERT_ID()");
}
function seedReq($store, $cid, $type, $email, $phone, $orderId = 0) {
	q("INSERT INTO oc_aichat_request SET store_id = " . (int)$store . ", conversation_id = " . (int)$cid . ", type = '" . $type . "', order_id = " . (int)$orderId . ", email = '" . esc($email) . "', phone = '" . esc($phone) . "', data = '" . esc(json_encode(array('note' => 'gdpr-test'))) . "', status = 'new', date_added = NOW()");
	return (int)one("SELECT LAST_INSERT_ID()");
}
function seedOrder($id, $store, $customer, $phone, $email) {
	$url = $store ? 'http://127.0.0.1:8081/' : 'http://127.0.0.1:8080/';
	q("INSERT INTO oc_order SET order_id = $id, store_id = $store, store_url = '$url', store_name = 'S$store', customer_id = $customer, customer_group_id = 1,
		firstname = 'Мария', lastname = 'Иванова', email = '" . esc($email) . "', telephone = '" . esc($phone) . "',
		payment_method = 'Наложен платеж', payment_code = 'cod', shipping_firstname = 'Мария', shipping_lastname = 'Иванова',
		shipping_address_1 = 'ул. Витоша 15, ет. 3', shipping_address_2 = 'ап. 7', shipping_city = 'София', shipping_postcode = '1000', shipping_country = 'Bulgaria',
		shipping_method = 'Speedy до адрес', shipping_code = 'flat.flat', comment = '', total = 55.5,
		order_status_id = 2, currency_id = 2, currency_code = 'USD', currency_value = 1, language_id = 1, ip = '10.9.9.9',
		date_added = NOW() - INTERVAL 1 DAY, date_modified = NOW()");
	q("INSERT INTO oc_order_product SET order_id = $id, product_id = 40, name = 'iPhone', model = 'product 11', quantity = 1, price = 50, total = 50, tax = 0, reward = 0");
	q("INSERT INTO oc_order_total (order_id, code, title, value, sort_order) VALUES ($id, 'total', 'Total', 55.5, 9)");
	q("INSERT INTO oc_order_history SET order_id = $id, order_status_id = 2, notify = 1, comment = 'Изпратена. Куриерът ще звънне на 0888 765 432 или ще пише на maria.ivanova@example.com. Your tracking number is 1000034300847.', date_added = NOW() - INTERVAL 2 HOUR");
}

$phone = '0888 765 432';
$email = 'maria.ivanova@example.com';
seedOrder(9301, 0, 0, '+359 888 765 432', $email);
seedOrder(9302, 1, 9300, '0899 000 111', 'other.person@example.com');
q("INSERT INTO oc_customer SET customer_id = 9300, customer_group_id = 1, store_id = 1, language_id = 1, firstname = 'K', lastname = 'L', email = 'Logged.In@Example.com', telephone = '', password = '', salt = '', cart = '', wishlist = '', newsletter = 0, address_id = 0, custom_field = '', ip = '', status = 1, safe = 0, token = '', code = '', date_added = NOW()");

// A: store 0, the subject writes her e-mail and phone; an image and a contact request
list($cA, $pA) = seedConv(0);
seedMsg($cA, 'user', 'Здравейте, имейлът ми е Maria.Ivanova@example.com');
seedMsg($cA, 'assistant', 'Благодаря!');
seedMsg($cA, 'user', 'и телефон 0888-765-432');
$rA1 = seedReq(0, $cA, 'contact', $email, $phone);
$rA2 = seedReq(0, $cA, 'order', $email, $phone, 9301);
$updir = \Aichat\Uploads::dir() . date('Ym');
@mkdir($updir, 0700, true);
$upName = date('Ym') . '/' . bin2hex(random_bytes(16));
$jpeg = imagecreatetruecolor(4, 4);
imagejpeg($jpeg, \Aichat\Uploads::dir() . $upName . '.jpg');
imagejpeg($jpeg, \Aichat\Uploads::dir() . $upName . '_t.jpg');
q("INSERT INTO oc_aichat_upload SET conversation_id = $cA, file = '$upName', mime = 'image/jpeg', bytes = 100, date_added = NOW()");
$upA = (int)one("SELECT LAST_INSERT_ID()");
// B: store 1, logged-in customer 9300 (e-mail matched through the account)
list($cB, $pB) = seedConv(1, array('customer_id' => 9300));
seedMsg($cB, 'user', 'Where is my order?');
// C: store 0, order 9301 verified in this chat (phone + e-mail matched through the order)
list($cC, $pC) = seedConv(0, array('verified_order_id' => 9301));
seedMsg($cC, 'user', 'Поръчка 9301');
// D: unrelated (store 1, someone else's contacts)
list($cD, $pD) = seedConv(1);
seedMsg($cD, 'user', 'my e-mail is someone.else@example.com, tel 0877 111 222');
$rD = seedReq(1, 0, 'stock', 'someone.else@example.com', '');
// a request without a conversation, same subject (store 1)
$rX = seedReq(1, 0, 'contact', 'MARIA.IVANOVA@example.com', '');

$ids = function ($found, $key, $field) {
	$out = array();
	foreach ($found[$key] as $row) {
		$out[] = (int)$row[$field];
	}
	sort($out);
	return $out;
};
$sorted = function (array $a) {
	sort($a);
	return $a;
};

$f = Privacy::find($registry, array('email' => ' Maria.Ivanova@Example.com '));
check('find e-mail: messages (any case), requests, verified order -> A, C + their requests, the loose request', $ids($f, 'conversations', 'conversation_id') === $sorted(array($cA, $cC)) && $ids($f, 'requests', 'request_id') === $sorted(array($rA1, $rA2, $rX)) && $f['uploads'] === 1, json_encode($f));
check('find: result shape', isset($f['conversations'][0]['store_id'], $f['conversations'][0]['date_added'], $f['conversations'][0]['date_modified'], $f['conversations'][0]['message_count'], $f['conversations'][0]['restricted'], $f['requests'][0]['type'], $f['requests'][0]['date_added']), json_encode($f));
$f = Privacy::find($registry, array('phone' => '+359888765432'));
check('find phone (last 9 digits, separators in text): A (message "0888-765-432"), C (order phone)', $ids($f, 'conversations', 'conversation_id') === $sorted(array($cA, $cC)) && in_array($rA1, $ids($f, 'requests', 'request_id'), true), json_encode($f));
$f = Privacy::find($registry, array('order_id' => '#9301'));
check('find order number: the chat that verified it + its request', in_array($cC, $ids($f, 'conversations', 'conversation_id'), true) && in_array($rA2, $ids($f, 'requests', 'request_id'), true) && !in_array($cD, $ids($f, 'conversations', 'conversation_id'), true), json_encode($f));
$f = Privacy::find($registry, array('email' => 'logged.in@example.com'));
check('find e-mail of a customer account -> the logged-in conversation (store 1)', $ids($f, 'conversations', 'conversation_id') === array($cB), json_encode($f));
$f = Privacy::find($registry, array('customer_id' => 9300));
check('find customer_id -> B (+ conversations of the customer\'s orders)', in_array($cB, $ids($f, 'conversations', 'conversation_id'), true) && !in_array($cA, $ids($f, 'conversations', 'conversation_id'), true), json_encode($f));
$f = Privacy::find($registry, array('email' => 'nobody@nowhere.example', 'phone' => '12'));
check('find: no match, too short phone ignored', !$f['conversations'] && !$f['requests'] && $f['uploads'] === 0, json_encode($f));

// export
$zipPath = Privacy::export($registry, array($cA, $cC), array($rA1, $rX));
check('export: ZIP in DIR_STORAGE/aichat/exports/', $zipPath !== '' && is_file($zipPath) && strpos($zipPath, rtrim(DIR_STORAGE, '/') . '/aichat/exports/') === 0, $zipPath);
$zip = new ZipArchive();
$zip->open($zipPath);
$data = json_decode((string)$zip->getFromName('data.json'), true);
$hasImage = $zip->locateName('images/' . $upA . '.jpg') !== false;
$zip->close();
check('export: data.json with both conversations, their messages and the requests, plus the image', count($data['conversations']) === 2 && count($data['conversations'][0]['messages']) >= 1 && strpos(json_encode($data, JSON_UNESCAPED_UNICODE), 'Maria.Ivanova@example.com') !== false && count($data['requests']) === 2 && $hasImage, json_encode(array(count($data['conversations']), count($data['requests']), $hasImage)));
$tok = Privacy::exportUrlToken($zipPath);
check('export token: readExport() returns the file', $tok !== '' && Privacy::readExport($tok) === $zipPath, $tok);
$bad = substr($tok, 0, -2) . (substr($tok, -2) === 'AA' ? 'BB' : 'AA');
check('export token: forged signature / other file / garbage refused', Privacy::readExport($bad) === '' && Privacy::readExport(str_replace(basename($zipPath), 'aichat-export-20200101-000000-0000000000000000.zip', $tok)) === '' && Privacy::readExport('../../config.php') === '' && Privacy::readExport(array()) === '');
$parts = explode('.', $tok);
check('export token: expiry is part of the signature', Privacy::readExport($parts[0] . '.' . $parts[1] . '.' . ((int)$parts[2] + 3600) . '.' . $parts[3]) === '');
check('export file name is unguessable (64 random bits)', (bool)preg_match('/^aichat-export-\d{8}-\d{6}-[a-f0-9]{16}\.zip$/', basename($zipPath)) && is_file(dirname($zipPath) . '/.htaccess'));
$zipD = Privacy::export($registry, array($cD), array());
$zipOld = Privacy::export($registry, array($cD), array());
touch($zipOld, time() - 86400 - 60);
clearstatcache();
check('export older than 24 h: token invalid', Privacy::readExport(Privacy::exportUrlToken($zipOld)) === '');

// restrict
check('restrict C', Privacy::restrict($registry, $cC, true) && (int)one("SELECT restricted FROM oc_aichat_conversation WHERE conversation_id = $cC") === 1 && Privacy::restrict($registry, 999999999, true) === false);
$tokC = $guard->makeToken($pC, 0);
$b = boot($s0, $tokC);
check('restricted: boot starts a fresh conversation (new token, no history)', is_array($b) && $b['enabled'] && substr($b['token'], 0, 32) !== $pC && $b['history'] === array(), json_encode($b));
$r = send($s0, $tokC, 'hello');
check('restricted: send with its token refused (invalid_token)', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token' && (int)one("SELECT COUNT(*) FROM oc_aichat_message WHERE conversation_id = $cC") === 1, json_encode($r));
check('unrestrict C', Privacy::restrict($registry, $cC, false) && (int)one("SELECT restricted FROM oc_aichat_conversation WHERE conversation_id = $cC") === 0);
Privacy::restrict($registry, $cC, true);

// logs
$dsr = Privacy::log($registry, 9300, 'search', array('query' => array('email' => 'gdpr-test@example.com'), 'result' => array('conversations' => 2)));
$row = q("SELECT * FROM oc_aichat_dsr WHERE dsr_id = $dsr")->fetch_assoc();
check('log(): aichat_dsr row with query + result JSON', $dsr > 0 && $row['action'] === 'search' && (int)$row['user_id'] === 9300 && json_decode($row['query'], true)['email'] === 'gdpr-test@example.com' && json_decode($row['result'], true)['conversations'] === 2, json_encode($row));
check('log(): unknown action refused', Privacy::log($registry, 9300, 'drop', array('query' => 'gdpr-test')) === 0);
$al = Privacy::accessLog($registry, 9300, $cA, 'view');
check('accessLog(): aichat_access_log row; unknown action refused', $al > 0 && one("SELECT action FROM oc_aichat_access_log WHERE log_id = $al") === 'view' && Privacy::accessLog($registry, 9300, $cA, 'peek') === 0);

// erase A (+ the loose request): everything of A, nothing else
$upFile = \Aichat\Uploads::dir() . $upName . '.jpg';
$res = Privacy::erase($registry, array($cA), array($rX));
check('erase: counts', $res['conversations'] === 1 && $res['messages'] === 3 && $res['uploads'] === 1 && $res['requests_deleted'] === 2 && $res['requests_anonymised'] === 1, json_encode($res));
check('erase: conversation, messages, upload row + files gone', !one("SELECT COUNT(*) FROM oc_aichat_conversation WHERE conversation_id = $cA") && !one("SELECT COUNT(*) FROM oc_aichat_message WHERE conversation_id = $cA") && !one("SELECT COUNT(*) FROM oc_aichat_upload WHERE conversation_id = $cA") && !is_file($upFile) && !is_file(str_replace('.jpg', '_t.jpg', $upFile)));
$o = q("SELECT * FROM oc_aichat_request WHERE request_id = $rA2")->fetch_assoc();
check('erase: contact request deleted, order request anonymised (order id kept)', !one("SELECT COUNT(*) FROM oc_aichat_request WHERE request_id IN ($rA1, $rX)") && $o && $o['email'] === '' && $o['phone'] === '' && (int)$o['order_id'] === 9301 && (int)$o['conversation_id'] === 0, json_encode($o));
check('erase: export ZIP containing A deleted, the one with D only kept', !is_file($zipPath) && is_file($zipD) && $res['exports'] >= 1, json_encode($res));
check('erase: other conversations and requests untouched (B, C, D, D\'s request)', (int)one("SELECT COUNT(*) FROM oc_aichat_conversation WHERE conversation_id IN ($cB, $cC, $cD)") === 3 && (int)one("SELECT COUNT(*) FROM oc_aichat_message WHERE conversation_id = $cD") === 1 && (int)one("SELECT COUNT(*) FROM oc_aichat_request WHERE request_id = $rD") === 1);
@unlink($zipD);
@unlink($zipOld);
q("DELETE FROM oc_aichat_request WHERE request_id = $rA2");

// ================================================================ 3. retention (Maintenance::cleanup) with back-dated rows
echo "== retention\n";
setting(0, 'g_retention_months', 6);
setting(0, 'g_ip_days', 30);
setting(0, 'g_request_months', 12);
setting(0, 'g_miss_days', 90);
setting(0, 's_tg_delete_days', 7);
setting(1, 's_tg_delete_days', 0);
$tgToken = '123456:TEST-token-abcdefghijklmnop';
$tgFile = \Aichat\Settings::secretFile('tg_token');
$hadTg = is_file($tgFile) ? file_get_contents($tgFile) : null;
file_put_contents($tgFile, "<?php\nreturn " . var_export($tgToken, true) . ";\n");
setting(0, 'g_tg_chat_id', '-100123');

list($cOld, $pOld) = seedConv(0, array('date_added' => date('Y-m-d H:i:s', strtotime('-8 months')), 'date_modified' => date('Y-m-d H:i:s', strtotime('-7 months'))));
seedMsg($cOld, 'user', 'old chat, tel 0888 765 432', null, '7 MONTH');
$rOldConv = seedReq(0, $cOld, 'contact', 'x@example.com', '');
list($cFl, $pFl) = seedConv(0, array('flagged' => 1, 'customer_id' => 9300, 'verified_order_id' => 9301, 'first_url' => 'http://127.0.0.1:8080/?q=maria', 'date_added' => date('Y-m-d H:i:s', strtotime('-8 months')), 'date_modified' => date('Y-m-d H:i:s', strtotime('-7 months'))));
$m1 = seedMsg($cFl, 'user', 'hello', null, '7 MONTH');
$m2 = seedMsg($cFl, 'assistant', 'hi', null, '7 MONTH');
$m3 = seedMsg($cFl, 'user', 'Доставете на ул. Витоша 15, тел. 0888 765 432, maria@example.com', array('url' => 'x'), '7 MONTH');
$m4 = seedMsg($cFl, 'assistant', 'Wrong answer for Maria at maria@example.com, ул. Витоша 15', array('flag' => array('note' => 'bad, she wrote maria@example.com', 'by' => 1, 'at' => '2026-04-01 10:00:00'), 'cards' => array(array('type' => 'order', 'address' => 'ул. Витоша 15'))), '7 MONTH');
$m5 = seedMsg($cFl, 'user', 'thanks', null, '7 MONTH');
list($cIp, $pIp) = seedConv(0, array('date_added' => date('Y-m-d H:i:s', strtotime('-40 days'))));
list($cIpNew, $pIpNew) = seedConv(0, array('date_added' => date('Y-m-d H:i:s', strtotime('-10 days'))));
q("INSERT INTO oc_aichat_request SET store_id = 0, conversation_id = 0, type = 'contact', email = 'old@example.com', data = '{\"note\":\"gdpr-test\"}', status = 'done', date_added = NOW() - INTERVAL 13 MONTH");
$rOld = (int)one("SELECT LAST_INSERT_ID()");
q("INSERT INTO oc_aichat_request SET store_id = 0, conversation_id = 0, type = 'contact', email = 'new@example.com', data = '{\"note\":\"gdpr-test\"}', status = 'done', date_added = NOW() - INTERVAL 11 MONTH");
$rNew = (int)one("SELECT LAST_INSERT_ID()");
q("INSERT INTO oc_aichat_search_miss SET store_id = 0, language_id = 1, `query` = 'gdpr-test old', hits = 1, date_modified = NOW() - INTERVAL 100 DAY");
q("INSERT INTO oc_aichat_search_miss SET store_id = 0, language_id = 1, `query` = 'gdpr-test new', hits = 1, date_modified = NOW() - INTERVAL 10 DAY");
q("INSERT INTO oc_aichat_dsr SET user_id = 9300, action = 'search', `query` = '\"gdpr-test old\"', result = '{}', date_added = NOW() - INTERVAL 37 MONTH");
q("INSERT INTO oc_aichat_dsr SET user_id = 9300, action = 'search', `query` = '\"gdpr-test new\"', result = '{}', date_added = NOW() - INTERVAL 35 MONTH");
q("INSERT INTO oc_aichat_access_log SET user_id = 9300, conversation_id = 1, action = 'view', date_added = NOW() - INTERVAL 13 MONTH");
q("INSERT INTO oc_aichat_access_log SET user_id = 9300, conversation_id = 2, action = 'view', date_added = NOW() - INTERVAL 11 MONTH");
$zipStale = Privacy::export($registry, array($cD), array());
touch($zipStale, time() - 86400 - 60);
$zipFresh = Privacy::export($registry, array($cD), array());
// Telegram topics: store 0 deletes after 7 days, store 1 never
list($cT1) = seedConv(0, array('tg_chat_id' => '-100123', 'tg_topic_id' => 771, 'tg_done_at' => date('Y-m-d H:i:s', strtotime('-8 days'))));
list($cT2) = seedConv(0, array('tg_chat_id' => '-100123', 'tg_topic_id' => 772, 'tg_done_at' => date('Y-m-d H:i:s', strtotime('-3 days'))));
list($cT3) = seedConv(1, array('tg_chat_id' => '-100456', 'tg_topic_id' => 773, 'tg_done_at' => date('Y-m-d H:i:s', strtotime('-30 days'))));
list($cT4) = seedConv(0, array('tg_chat_id' => '-100123', 'tg_topic_id' => 774, 'mode' => 'human', 'tg_done_at' => date('Y-m-d H:i:s', strtotime('-9 days'))));
tgReset();

$maint = new \Aichat\Maintenance($registry, new \Aichat\Settings($registry));
$done = $maint->cleanup();

check('retention: old unflagged conversation deleted with messages + requests', !one("SELECT COUNT(*) FROM oc_aichat_conversation WHERE conversation_id = $cOld") && !one("SELECT COUNT(*) FROM oc_aichat_message WHERE conversation_id = $cOld") && !one("SELECT COUNT(*) FROM oc_aichat_request WHERE request_id = $rOldConv"), json_encode($done));
$fl = q("SELECT * FROM oc_aichat_conversation WHERE conversation_id = $cFl")->fetch_assoc();
$kept = array();
foreach (q("SELECT message_id, content, meta FROM oc_aichat_message WHERE conversation_id = $cFl ORDER BY message_id")->fetch_all(MYSQLI_ASSOC) as $row) {
	$kept[(int)$row['message_id']] = $row;
}
check('retention: flagged conversation kept anonymous (no customer, order, IP, URL, token)', $fl && strpos($fl['public_id'], 'anon') === 0 && (int)$fl['customer_id'] === 0 && (int)$fl['verified_order_id'] === 0 && $fl['ip'] === '' && $fl['user_agent'] === '' && $fl['first_url'] === '', json_encode($fl));
check('retention: only the flagged answer and the question before it stay', array_keys($kept) === array($m3, $m4), json_encode(array_keys($kept)));
$allKept = json_encode($kept, JSON_UNESCAPED_UNICODE);
check('retention: kept messages redacted (no e-mail, phone, house number; no order card), flag kept', isset($kept[$m4]) && strpos($allKept, 'maria@example.com') === false && strpos($allKept, '765 432') === false && strpos($allKept, 'Витоша 15') === false && strpos($allKept, 'cards') === false && strpos($kept[$m4]['meta'], '"flag"') !== false && strpos($kept[$m4]['content'], 'Wrong answer') !== false, $allKept);
$done2 = $maint->cleanup();
check('retention: anonymised conversation is not processed again', (int)one("SELECT COUNT(*) FROM oc_aichat_message WHERE conversation_id = $cFl") === 2 && $done2['anonymised'] === 0, json_encode($done2));
check('retention: IP + user agent blanked after g_ip_days, newer kept', one("SELECT CONCAT(ip, user_agent) FROM oc_aichat_conversation WHERE conversation_id = $cIp") === '' && one("SELECT ip FROM oc_aichat_conversation WHERE conversation_id = $cIpNew") === '10.1.2.3');
check('retention: requests after g_request_months', !one("SELECT COUNT(*) FROM oc_aichat_request WHERE request_id = $rOld") && one("SELECT COUNT(*) FROM oc_aichat_request WHERE request_id = $rNew"));
check('retention: search misses after g_miss_days', !one("SELECT COUNT(*) FROM oc_aichat_search_miss WHERE `query` = 'gdpr-test old'") && one("SELECT COUNT(*) FROM oc_aichat_search_miss WHERE `query` = 'gdpr-test new'"));
check('retention: DSR log 36 months, access log 12 months', !one("SELECT COUNT(*) FROM oc_aichat_dsr WHERE `query` = '\"gdpr-test old\"'") && one("SELECT COUNT(*) FROM oc_aichat_dsr WHERE `query` = '\"gdpr-test new\"'") && !one("SELECT COUNT(*) FROM oc_aichat_access_log WHERE user_id = 9300 AND conversation_id = 1") && one("SELECT COUNT(*) FROM oc_aichat_access_log WHERE user_id = 9300 AND conversation_id = 2"));
check('retention: export files after 24 h', !is_file($zipStale) && is_file($zipFresh));
@unlink($zipFresh);
$del = tgCalls('deleteForumTopic');
$threads = array();
foreach ($del as $call) {
	$threads[] = $call['params']['chat_id'] . '/' . $call['params']['message_thread_id'];
}
check('retention: Telegram topic deleted N days after Done (store 0: 7 days), not earlier, not while open, not where 0 = never', $threads === array('-100123/771') && (int)one("SELECT tg_topic_id FROM oc_aichat_conversation WHERE conversation_id = $cT1") === 0 && (int)one("SELECT tg_topic_id FROM oc_aichat_conversation WHERE conversation_id = $cT2") === 772 && (int)one("SELECT tg_topic_id FROM oc_aichat_conversation WHERE conversation_id = $cT3") === 773 && (int)one("SELECT tg_topic_id FROM oc_aichat_conversation WHERE conversation_id = $cT4") === 774, json_encode($threads));

// erase deletes the topic immediately
tgReset();
$res = Privacy::erase($registry, array($cT2), array());
$del = tgCalls('deleteForumTopic');
check('erase: Telegram topic deleted immediately', $res['topics'] === 1 && count($del) === 1 && $del[0]['params']['message_thread_id'] === '772', json_encode(array($res, $del)));

// weekly report: redacted text, restricted conversations left out
list($cR1) = seedConv(0, array('date_added' => date('Y-m-d H:i:s', strtotime('-20 days'))));
seedMsg($cR1, 'user', 'gdpr-test report question from maria@example.com 0888 765 432');
list($cR2) = seedConv(0, array('restricted' => 1, 'date_added' => date('Y-m-d H:i:s', strtotime('-20 days'))));
seedMsg($cR2, 'user', 'gdpr-test restricted secret question');
q("INSERT INTO oc_aichat_search_miss SET store_id = 0, language_id = 1, `query` = 'gdpr-test bike for ivan@example.com', hits = 3, date_modified = NOW() - INTERVAL 20 DAY");
$report = $maint->reportText(date('Y-m-d', strtotime('-21 days')), date('Y-m-d', strtotime('-19 days')));
check('weekly report: questions + misses redacted, restricted conversation excluded', strpos($report, 'gdpr-test report question from […]') !== false && strpos($report, 'maria@example.com') === false && strpos($report, '765 432') === false && strpos($report, 'restricted secret') === false && strpos($report, 'ivan@example.com') === false, $report);
check('Privacy::preview (dashboard "live now" without the personal-data permission)', Privacy::preview("Call me\n 0888 765 432,  maria@example.com", 40) === 'Call me […], […]' && Privacy::notRestricted('c') === " AND c.`restricted` = '0'");

// ================================================================ 4. storefront: privacy notice, privacy_required, scrub, minimisation
echo "== storefront\n";
setting(0, 's_privacy_text', null);
setting(0, 's_privacy_url', null);
setting(0, 's_privacy_accept', 0);
$b = boot($s0);
check('boot.privacy: default text with the retention months, the store\'s privacy page, no acceptance', isset($b['privacy']) && strpos($b['privacy']['text'], '6 months') !== false && strpos($b['privacy']['url'], 'information_id=3') !== false && $b['privacy']['require_accept'] === false, json_encode(isset($b['privacy']) ? $b['privacy'] : $b));
setting(0, 's_privacy_text', array(1 => 'Custom &quot;notice&quot;'));
setting(0, 's_privacy_url', array(1 => 'https://example.com/privacy'));
$b = boot($s0);
check('boot.privacy: owner text + URL per language', $b['privacy']['text'] === 'Custom "notice"' && $b['privacy']['url'] === 'https://example.com/privacy', json_encode($b['privacy']));
setting(0, 's_privacy_url', array(1 => 'javascript:alert(1)'));
$b = boot($s0);
check('boot.privacy: a non-http URL is replaced by the privacy page', strpos($b['privacy']['url'], 'information_id=3') !== false, json_encode($b['privacy']));
check('boot.strings: accept button text', isset($b['strings']['privacy_accept']) && $b['strings']['privacy_accept'] !== '', json_encode(array_keys($b['strings'])));

setting(0, 's_privacy_accept', 1);
$b = boot($s0);
$t = $b['token'];
check('privacy_accept on: boot.require_accept', $b['privacy']['require_accept'] === true);
$r = send($s0, $t, 'tool:list_manufacturers {}');
check('send without privacy_accepted -> privacy_required, nothing stored', isset($r['error']['code']) && $r['error']['code'] === 'privacy_required' && $r['error']['message'] !== '' && !convByToken($t), json_encode($r));
$r = send($s0, $t, 'tool:list_manufacturers {}', array('privacy_accepted' => 0), true);
check('stream: privacy_required as event: error', $r['events'] && $r['events'][0][0] === 'error' && $r['events'][0][1]['code'] === 'privacy_required', $r['raw']);
$r = send($s0, $t, 'tool:list_manufacturers {}', array('privacy_accepted' => 1));
$c = convByToken($t);
check('send with privacy_accepted: 1 -> answered, privacy_accepted_at stored', isset($r['reply']) && $c && $c['privacy_accepted_at'] !== null, json_encode($r));
$b = boot($s0, $t);
$r = send($s0, $t, 'tool:list_manufacturers {}');
check('accepted conversation: boot.require_accept false, later sends need no flag', $b['privacy']['require_accept'] === false && isset($r['reply']), json_encode(array($b['privacy'], $r)));
setting(0, 's_privacy_accept', 0);

// inbound scrub
$b = boot($s0);
$t = $b['token'];
$off = logSize();
$r = send($s0, $t, 'Моята карта е 4111 1111 1111 1111, IBAN BG80BNBG96611020345678 tool:list_manufacturers {}');
$c = convByToken($t);
$stored = one("SELECT content FROM oc_aichat_message WHERE conversation_id = " . (int)$c['conversation_id'] . " AND role = 'user' ORDER BY message_id DESC LIMIT 1");
$reqs = json_encode(modelRequestsSince($off), JSON_UNESCAPED_UNICODE);
check('scrub: notice (code masked, localised message, found, masked text)', isset($r['notice']['code']) && $r['notice']['code'] === 'masked' && $r['notice']['message'] === 'Please do not send card details, IBAN or personal ID numbers – we masked them.' && $sorted($r['notice']['found']) === array('card', 'iban') && strpos($r['notice']['text'], '4111') === false, json_encode($r, JSON_UNESCAPED_UNICODE));
check('scrub: stored message and model request carry the mask only', strpos($stored, '4111') === false && strpos($stored, '[…]') !== false && strpos($reqs, '4111 1111') === false && strpos($reqs, 'BNBG9661') === false && strpos($reqs, 'IBAN [') !== false, $stored);
$r = send($s0, $t, 'карта 4111 1111 1111 1111 tool:list_manufacturers {}', array(), true);
$names = array_map(function ($e) {
	return $e[0];
}, $r['events']);
check('scrub (stream): event notice before the answer', $names && $names[0] === 'notice' && $r['events'][0][1]['code'] === 'masked' && in_array('done', $names, true), json_encode($names));
$r = send($s0, $t, 'Поръчка 9001, телефон 0888 123 456, ivan@example.com tool:list_manufacturers {}');
$stored = one("SELECT content FROM oc_aichat_message WHERE conversation_id = " . (int)$c['conversation_id'] . " AND role = 'user' ORDER BY message_id DESC LIMIT 1");
check('scrub: order number, phone, e-mail untouched, no notice', !isset($r['notice']) && strpos($stored, 'Поръчка 9001, телефон 0888 123 456, ivan@example.com') === 0, json_encode($r, JSON_UNESCAPED_UNICODE));

// minimisation: the model gets street + city, the visitor's card the full address
$b = boot($s0);
$t = $b['token'];
send($s0, $t, 'tool:list_manufacturers {}');
$off = logSize();
$r = send($s0, $t, 'tool:find_order {"order_id":"9301","phone":"0888765432"}');
$res = toolResult(isset($r['reply']) ? $r['reply'] : '');
$toolBlocks = array();
foreach (modelRequestsSince($off) as $req) {
	foreach ($req['messages'] as $m) {
		if (is_array($m['content'])) {
			foreach ($m['content'] as $block) {
				if ($block['type'] === 'tool_result') {
					$decoded = json_decode((string)$block['content'], true);
					$toolBlocks[] = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : (string)$block['content'];
				}
			}
		}
	}
}
$toModel = implode("\n", $toolBlocks);
$card = null;
foreach (isset($r['cards']) ? $r['cards'] : array() as $cd) {
	if ($cd['type'] === 'order') {
		$card = $cd;
	}
}
check('minimisation: order found, address to the model = street name + city', is_array($res) && !empty($res['found']) && $res['shipping_address'] === 'ул. Витоша, София', json_encode($res, JSON_UNESCAPED_UNICODE));
check('minimisation: tool result sent to the model has no phone, e-mail, house number, flat or recipient name', $toModel !== '' && strpos($toModel, '765') === false && strpos($toModel, 'maria.ivanova') === false && strpos($toModel, 'Витоша 15') === false && strpos($toModel, 'ап. 7') === false && strpos($toModel, 'Иванова') === false && strpos($toModel, '1000034300847') !== false, $toModel);
check('minimisation: the visitor\'s order card carries the full address', $card && isset($card['address']) && strpos($card['address'], 'ул. Витоша 15, ет. 3') !== false && strpos($card['address'], 'ап. 7') !== false && strpos($card['address'], 'Мария Иванова') !== false, json_encode($card, JSON_UNESCAPED_UNICODE));
setting(0, 's_min_model', 0);
$r = send($s0, $t, 'tool:find_order {"order_id":"9301","phone":"0888765432"}');
$res = toolResult(isset($r['reply']) ? $r['reply'] : '');
check('s_min_model = 0: the full address goes to the model (owner\'s choice)', is_array($res) && strpos((string)$res['shipping_address'], 'Витоша 15') !== false, json_encode($res, JSON_UNESCAPED_UNICODE));
setting(0, 's_min_model', null);

// ================================================================ 5. Telegram: mask, Done -> tg_done_at, forget deletes the topic
echo "== Telegram\n";
$open = array();
foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $d) {
	$open[$d] = array('from' => '00:00', 'to' => '24:00');
}
setting(0, 's_work_hours', $open);
setting(0, 's_tg_mask', 1);
tgReset();
$b = boot($s0);
$t = $b['token'];
send($s0, $t, 'Адрес ул. Витоша 15, ет. 3, имейл maria.ivanova@example.com tool:list_manufacturers {}');
$r = send($s0, $t, 'tool:request_human {"reason":"call her on 0888 765 432"}');
$c = convByToken($t);
$msgs = tgCalls('sendMessage');
$topicText = $msgs ? $msgs[0]['params']['text'] : '';
check('s_tg_mask: topic transcript and reason masked', $c['mode'] === 'waiting' && $topicText !== '' && strpos($topicText, 'Витоша 15') === false && strpos($topicText, 'maria.ivanova@example.com') === false && strpos($topicText, '765 432') === false && strpos($topicText, '[…]') !== false, $topicText);
tgReset();
send($s0, $t, 'my number is 0888 765 432');
$msgs = tgCalls('sendMessage');
check('s_tg_mask: forwarded visitor message masked', $msgs && strpos($msgs[0]['params']['text'], '765 432') === false && strpos($msgs[0]['params']['text'], 'my number is […]') !== false, json_encode($msgs, JSON_UNESCAPED_UNICODE));
setting(0, 's_tg_mask', 0);
tgReset();
send($s0, $t, 'my number is 0888 765 432');
$msgs = tgCalls('sendMessage');
check('s_tg_mask off (default): staff see the visitor text as written', $msgs && strpos($msgs[0]['params']['text'], '0888 765 432') !== false, json_encode($msgs, JSON_UNESCAPED_UNICODE));

$tgSecret = (new \Aichat\Settings($registry))->telegramSecret();
$ch = curl_init($s0 . 'index.php?route=extension/module/aichat/telegram');
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $tgSecret),
	CURLOPT_POSTFIELDS => json_encode(array('update_id' => 990000 + random_int(1, 9999), 'callback_query' => array('id' => 'cbg', 'from' => array('id' => 55, 'is_bot' => false, 'first_name' => 'Maria'), 'data' => 'done:' . (int)$c['conversation_id'],
		'message' => array('message_id' => 5000, 'message_thread_id' => (int)$c['tg_topic_id'], 'chat' => array('id' => -100123, 'type' => 'supergroup', 'is_forum' => true)))))));
$w = json_decode((string)curl_exec($ch), true);
curl_close($ch);
$c2 = convByToken($t);
check('Done: back to the bot, tg_done_at set (topic deleted N days later)', $c2['mode'] === 'bot' && $c2['tg_done_at'] !== null && (int)$c2['tg_topic_id'] > 0, json_encode(array($w, $c2['mode'], $c2['tg_done_at'])));
tgReset();
$r = post($s0, 'forget', array('token' => $t));
$del = tgCalls('deleteForumTopic');
check('forget: the Telegram topic is deleted immediately, conversation gone', isset($r['ok']) && count($del) === 1 && $del[0]['params']['message_thread_id'] === (string)$c['tg_topic_id'] && !convByToken($t), json_encode(array($r, $del)));

// ================================================================ 6. footer consent gate flag
echo "== footer\n";
list($page) = http('GET', $s0);
check('footer: wait_consent false by default', strpos($page, '"wait_consent":false') !== false && strpos($page, 'aichat/widget.js') !== false);
setting(0, 's_wait_consent', 1);
list($page) = http('GET', $s0);
check('footer: s_wait_consent = 1 -> wait_consent true in the widget config', strpos($page, 'window.AICHAT={"base":') !== false && strpos($page, '"wait_consent":true') !== false);

// ================================================================ 7. installer: schema 7 and the personal-data permission
echo "== installer\n";
$cols = array();
foreach (q("SHOW COLUMNS FROM oc_aichat_conversation")->fetch_all(MYSQLI_ASSOC) as $col) {
	$cols[] = $col['Field'];
}
check('schema 7: columns restricted, privacy_accepted_at, tg_done_at; tables aichat_dsr, aichat_access_log', !array_diff(array('restricted', 'privacy_accepted_at', 'tg_done_at'), $cols) && q("SHOW TABLES LIKE '" . $pfx . "aichat_dsr'")->num_rows && q("SHOW TABLES LIKE '" . $pfx . "aichat_access_log'")->num_rows && (int)(new \Aichat\Settings($registry))->getGlobal('g_schema') === 7);
$perm = json_decode((string)one("SELECT permission FROM oc_user_group WHERE name = 'Administrator'"), true);
check('Administrator group has extension/module/aichat_pd (access + modify)', in_array('extension/module/aichat_pd', $perm['access'], true) && in_array('extension/module/aichat_pd', $perm['modify'], true));
check('grantPdPermission idempotent (no duplicate entry)', \Aichat\Installer::grantPdPermission($registry) && count(array_keys(json_decode((string)one("SELECT permission FROM oc_user_group WHERE name = 'Administrator'"), true)['access'], 'extension/module/aichat_pd')) === 1);
check('settings defaults of round 6', \Aichat\Settings::globalDefaults()['g_retention_months'] === 6 && \Aichat\Settings::globalDefaults()['g_ip_days'] === 30 && \Aichat\Settings::globalDefaults()['g_request_months'] === 12 && \Aichat\Settings::globalDefaults()['g_miss_days'] === 90
	&& \Aichat\Settings::storeDefaults()['s_min_model'] === 1 && \Aichat\Settings::storeDefaults()['s_tg_mask'] === 0 && \Aichat\Settings::storeDefaults()['s_tg_delete_days'] === 7 && \Aichat\Settings::storeDefaults()['s_privacy_accept'] === 0 && \Aichat\Settings::storeDefaults()['s_wait_consent'] === 0);
$defUrl = Privacy::defaultPrivacyUrl($registry, 0, 1);
check('defaultPrivacyUrl: the store\'s information page with "privacy" in the title', $defUrl === $s0 . 'index.php?route=information/information&information_id=3', $defUrl);
check('defaultPrivacyUrl: none in store 1 (its only page is "About Us")', Privacy::defaultPrivacyUrl($registry, 1, 1) === '');

// ================================================================ cleanup
restoreSettings();
if ($hadTg === null) {
	@unlink($tgFile);
} else {
	file_put_contents($tgFile, $hadTg);
}
foreach (array($cFl, $cIp, $cIpNew, $cT1, $cT3, $cT4, $cR1, $cR2, $cB, $cC, $cD) as $id) {
	\Aichat\Maintenance::deleteConversation($registry, $id, false);
}
q("DELETE FROM oc_aichat_request WHERE request_id IN ($rNew, $rD)");
cleanupSeed();
resetRates();

echo $fails ? "\n" . $fails . " FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
