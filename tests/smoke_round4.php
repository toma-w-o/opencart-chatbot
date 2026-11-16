<?php
/**
 * Round 4 smoke tests (included from smoke_http.php after round 3; uses the
 * helpers of smoke_http / round 2 / round 3).
 *
 * Needs SMOKE_DB, SMOKE_OC, SMOKE_MAIL, SMOKE_WORK (rig dir: mail_real.flag
 * switches the servers from the mail file to OpenCart's real Mail class) and
 * SMOKE_SMTP "<port store 0>:<port store 1>" (tests/fake_smtp.php, log WORK/smtp.log).
 */

$work = getenv('SMOKE_WORK');
list($smtp0, $smtp1) = explode(':', getenv('SMOKE_SMTP') ? getenv('SMOKE_SMTP') : '2525:2526');
$smtpLog = $work . '/smtp.log';
$s0 = $store0;
$s1 = $store1;
setting(0, 's_max_msgs_minute', 0);
setting(1, 's_max_msgs_minute', 0);
resetRates();

function cfgRow($sid, $key, $value) {
	q("DELETE FROM oc_setting WHERE store_id = " . (int)$sid . " AND code = 'config' AND `key` = '" . db()->real_escape_string($key) . "'");
	if ($value !== null) {
		q("INSERT INTO oc_setting SET store_id = " . (int)$sid . ", code = 'config', `key` = '" . db()->real_escape_string($key) . "', value = '" . db()->real_escape_string($value) . "', serialized = 0");
	}
}
function cfgValue($sid, $key) {
	$r = q("SELECT value FROM oc_setting WHERE store_id = " . (int)$sid . " AND code = 'config' AND `key` = '" . db()->real_escape_string($key) . "'")->fetch_assoc();
	return $r ? $r['value'] : null;
}
function smtpText() {
	global $smtpLog;
	clearstatcache();
	return is_file($smtpLog) ? (string)file_get_contents($smtpLog) : '';
}
function reqRow($type) {
	return q("SELECT * FROM oc_aichat_request WHERE type = '" . $type . "' ORDER BY request_id DESC LIMIT 1")->fetch_assoc();
}

// ================================================================ boot: attention + avatar fallback
setting(0, 's_attention', 1);
setting(0, 's_attention_interval', 7);
setting(0, 's_avatar', 'no-such-avatar');
$b = boot($s0);
check('boot.appearance.attention {enabled, interval}', isset($b['appearance']['attention']) && $b['appearance']['attention'] === array('enabled' => true, 'interval' => 7), json_encode(isset($b['appearance']) ? $b['appearance'] : $b));
check('boot: unknown avatar id -> default', $b['avatar'] === 'default', $b['avatar']);
setting(0, 's_attention', 0);
setting(0, 's_attention_interval', null);
$b = boot($s0);
check('boot: attention off, default interval 5', $b['appearance']['attention'] === array('enabled' => false, 'interval' => 5));
setting(0, 's_attention', null);
setting(0, 's_avatar', 'default');

// ================================================================ store name (prompt, mail sender, Telegram topic)
$name1 = cfgValue(1, 'config_name');
cfgRow(1, 'config_name', '');
$b = boot($s1);
send($s1, $b['token'], 'tool:list_categories {}', $s1, false);
$req = lastModelRequest();
check('store name: empty config_name -> oc_store.name in the prompt', strpos($req, 'AI shop assistant of the online store \\"Second Store\\"') !== false, substr($req, strpos($req, 'AI shop assistant'), 80));
q("UPDATE oc_store SET name = '' WHERE store_id = 1");
send($s1, $b['token'], 'tool:list_categories {}', $s1, false);
$req = lastModelRequest();
check('store name: then the host, never empty', strpos($req, 'online store \\"127.0.0.1\\"') !== false && strpos($req, 'online store \\"\\"') === false, substr($req, strpos($req, 'AI shop assistant'), 80));
q("UPDATE oc_store SET name = 'Second Store' WHERE store_id = 1");
cfgRow(1, 'config_name', $name1);
setting(0, 's_store_name', 'Магазин Едно');
$b = boot($s0);
send($s0, $b['token'], 'tool:list_categories {}', $s0, false);
check('store name: s_store_name first', strpos(lastModelRequest(), 'online store \\"' . trim(json_encode('Магазин Едно'), '"') . '\\"') !== false || strpos(lastModelRequest(), 'online store \\"Магазин Едно\\"') !== false);
setting(0, 's_notify_email', 'shop0@example.com');
@unlink($mailFile);
list($v) = probe($s0, $b['token'], 'leave_contact', array('email' => 'name@example.com', 'note' => 'name test'));
check('store name in the mail sender', strpos(mailText(), 'Sender: Магазин Едно · AI Chat') !== false, mailText());

// ================================================================ tracking: the real comment samples
foreach (array(101 => 'Нова Revolut', 102 => 'Подготвя се с NextLevel', 103 => 'Доставена', 104 => 'Изпратена с NextLevel') as $sid => $nm) {
	q("DELETE FROM oc_order_status WHERE order_status_id = $sid");
	q("INSERT INTO oc_order_status SET order_status_id = $sid, language_id = 1, name = '" . db()->real_escape_string($nm) . "'");
}
$ids = '9101, 9102, 9103';
foreach (array('order', 'order_product', 'order_total', 'order_history', 'order_option') as $t) {
	q("DELETE FROM oc_$t WHERE order_id IN ($ids)");
}
addOrder(9101, 0, 0, '0899 111 222');
addOrder(9102, 0, 0, '0899 333 444');
addOrder(9103, 0, 0, '0899 555 666');
q("UPDATE oc_order SET order_status_id = 103, shipping_method = 'Доставка до адрес' WHERE order_id = 9101");
q("UPDATE oc_order SET order_status_id = 104 WHERE order_id = 9102");
q("UPDATE oc_order SET order_status_id = 1 WHERE order_id = 9103");
$hist = function ($id, $status, $notify, $comment, $date) {
	q("INSERT INTO oc_order_history SET order_id = $id, order_status_id = $status, notify = $notify, comment = '" . db()->real_escape_string($comment) . "', date_added = '" . $date . "'");
};
$y = date('Y');
$hist(9101, 101, 1, 'Revolut Payment Gateway - Order Payment Completed. Transaction ID: 6ad8f3f6-667b-a3aa-8f92-16a243889f38', "$y-10-21 10:00:00");
$hist(9101, 102, 1, 'Your tracking number is 1000034300847. You can track the delivery here https://nextlevel.delivery/bg/track?awb=1000034300847', "$y-10-22 11:00:00");
$hist(9101, 102, 0, 'по товарителница 1000034300847 Спиди с товарителници 63792919762,63792921672,63792922698', "$y-10-22 12:00:00");
$hist(9101, 103, 0, '', "$y-11-11 09:00:00");
$hist(9102, 102, 1, 'Поръчка 9102 на стойност 120.50 лв., тел. 0899333444, ще ви се обадим.', "$y-10-23 09:00:00");
$hist(9102, 104, 1, 'Изпратена: https://nextlevel.delivery/track?awb=1000031131956', "$y-10-24 09:00:00");
$hist(9102, 104, 1, 'Втората пратка е с товарителница 7778889990.', "$y-10-24 10:00:00");
$hist(9103, 1, 1, '', "$y-10-25 09:00:00");

// couriers as seeded by schema 4 (the round 2 fixture used example hosts)
$couriersBefore = settingValue('g_couriers');
setting(0, 'g_couriers', array(
	array('code' => 'speedy', 'name' => 'Speedy', 'type' => 'speedy', 'hosts' => array('speedy.bg'), 'track_url' => 'https://www.speedy.bg/bg/track-shipment?shipmentNumber={number}', 'pattern' => '^6\d{10}$', 'aliases' => array('Спиди')),
	array('code' => 'nextlevel', 'name' => 'NextLevel', 'type' => 'link', 'hosts' => array('nextlevel.delivery'), 'track_url' => 'https://nextlevel.delivery/bg/track?awb={number}', 'pattern' => '^1000\d{9}$', 'aliases' => array()),
));
setting(0, 's_courier', 'speedy');
resetRates();

$bt = boot($s0);
list($o, $resp) = probe($s0, $bt['token'], 'find_order', array('order_id' => '9101', 'phone' => '0899111222'));
$json = json_encode($o, JSON_UNESCAPED_UNICODE);
$numbers = array();
foreach (isset($o['tracking']) ? $o['tracking'] : array() as $t) {
	$numbers[] = $t['number'] . '/' . $t['courier'];
}
check('real order: exactly one tracking number 1000034300847 (NextLevel)', $numbers === array('1000034300847/NextLevel'), $json);
check('real order: private Speedy numbers, transaction id and payment line not exposed', strpos(json_encode($resp), '6379291') === false && strpos(json_encode($resp), '6ad8f3f6') === false && stripos($json, 'transaction') === false && stripos($json, 'Revolut Payment') === false && strpos($json, 'Спиди') === false, $json);
check('real order: status Доставена, stage delivered', $o['status'] === 'Доставена' && $o['stage'] === 'delivered', $json);
$hs = array();
foreach ($o['history'] as $h) {
	$hs[] = $h['status'] . (isset($h['comment']) ? ': ' . $h['comment'] : '');
}
check('real order: notified history only, comment text with the link replaced', count($hs) === 2 && $hs[0] === 'Нова Revolut' && strpos($hs[1], 'Подготвя се с NextLevel: Your tracking number is 1000034300847. You can track the delivery here [link]') === 0, json_encode($hs, JSON_UNESCAPED_UNICODE));
check('real order: courier detected by link, tracking note for the model', $o['tracking'][0]['courier_detected_by'] === 'link' && !empty($o['tracking_note']) && $o['shipping_method'] === 'Доставка до адрес', $json);
$tc = cardsOf($resp, 'tracking');
check('real order: one NextLevel tracking card', count($tc) === 1 && $tc[0]['href'] === 'https://nextlevel.delivery/bg/track?awb=1000034300847', json_encode($tc));

$bt2 = boot($s0);
list($o2) = probe($s0, $bt2['token'], 'find_order', array('order_id' => '9102', 'phone' => '+359 899 333 444'));
$n2 = array();
foreach (isset($o2['tracking']) ? $o2['tracking'] : array() as $t) {
	$n2[] = $t['number'] . '/' . $t['courier'] . '/' . $t['courier_detected_by'];
}
check('second order: link number + plain number (courier from the status "Изпратена с NextLevel"); order id, phone, amount ignored', $n2 === array('1000031131956/NextLevel/link', '7778889990/NextLevel/status'), json_encode($o2, JSON_UNESCAPED_UNICODE));
check('second order: stage shipped', $o2['stage'] === 'shipped');

// ================================================================ order_change_request
@unlink($mailFile);
$orderReqs = (int)q("SELECT COUNT(*) n FROM oc_aichat_request WHERE type = 'order'")->fetch_assoc()['n'];
list($v, $resp) = probe($s0, $bt['token'], 'order_change_request', array('order_id' => '9101', 'kind' => 'cancel', 'details' => 'искам да откажа'));
$rc = cardsOf($resp, 'return');
check('change request: delivered order -> no request, returns form card', $v && $v['ok'] === false && $v['stage'] === 'delivered' && (int)q("SELECT COUNT(*) n FROM oc_aichat_request WHERE type = 'order'")->fetch_assoc()['n'] === $orderReqs && $rc && strpos($rc[0]['href'], 'route=account/return/add') !== false && strpos($rc[0]['href'], 'order_id=9101') !== false, json_encode(array($v, $resp['cards'])));

list($v) = probe($s0, $bt2['token'], 'order_change_request', array('order_id' => '9102', 'kind' => 'address', 'details' => 'Нов адрес: ул. Шипка 5, Варна'));
$row = reqRow('order');
$data = $row ? json_decode($row['data'], true) : array();
$mail = mailText();
check('change request: shipped order accepted (a colleague decides)', $v && $v['ok'] === true && $v['stage'] === 'shipped' && $row && (int)$row['order_id'] === 9102 && $data['kind'] === 'address' && $data['verified'] === 'phone' && $data['stage'] === 'shipped', json_encode(array($v, $row)));
check('change request mail: verification statement, summary, customer words', strpos($mail, 'Subject: AI Chat: order change request #' . $row['request_id']) !== false && strpos($mail, 'Потвърдено: номерът на поръчката и телефонът съвпадат') !== false && strpos($mail, 'Нов адрес: ул. Шипка 5, Варна') !== false && strpos($mail, 'Order #9102') !== false && strpos($mail, 'iPhone') !== false && strpos($mail, 'To: shop0@example.com') !== false, $mail);
check('change request: mailed = 1 recorded', (string)$row['mailed'] === '1' && $data['mail_to'] === 'shop0@example.com', json_encode($row));
check('change request: the order itself is untouched', (int)q("SELECT order_status_id FROM oc_order WHERE order_id = 9102")->fetch_assoc()['order_status_id'] === 104);
check('change request: the model is told not to claim the change', strpos($v['message'], 'Do not say the order was changed') !== false);

$bt3 = boot($s0);
list($v) = probe($s0, $bt3['token'], 'order_change_request', array('order_id' => '9103', 'kind' => 'cancel', 'details' => 'x'));
$generic = $v['message'];
check('change request: unverified order refused (generic)', $v && $v['ok'] === false && strpos(json_encode($v), 'Ivan') === false);
list($v) = probe($s0, $bt3['token'], 'order_change_request', array('order_id' => '777777', 'kind' => 'cancel', 'details' => 'x'));
check('change request: unknown order -> the same reply', $v && $v['message'] === $generic);
probe($s0, $bt3['token'], 'find_order', array('order_id' => '9103', 'phone' => '0899555666'));
list($v) = probe($s0, $bt3['token'], 'order_change_request', array('order_id' => '9103', 'kind' => 'items', 'details' => 'add one more iPhone'));
check('change request: pending order -> stage open, accepted', $v && $v['ok'] === true && $v['stage'] === 'open', json_encode($v));

// logged-in own order (9003: Shipped, tracking in a notified comment)
$jar = tempnam(sys_get_temp_dir(), 'aichat_jar');
$GLOBALS['JAR'] = $jar;
http('GET', $s0 . 'index.php?route=account/login');
http('POST', $s0 . 'index.php?route=account/login', 'email=smoke%40example.com&password=secret');
$bl = boot($s0);
@unlink($mailFile);
list($v) = probe($s0, $bl['token'], 'order_change_request', array('order_id' => '9003', 'kind' => 'cancel', 'details' => 'changed my mind'));
$row = reqRow('order');
check('change request: logged-in own order, verified as logged-in customer', $v && $v['ok'] === true && json_decode($row['data'], true)['verified'] === 'logged_in' && strpos(mailText(), 'Потвърдено: логнат клиент') !== false, json_encode($v) . mailText());
list($v) = probe($s0, $bl['token'], 'order_change_request', array('order_id' => '9001', 'kind' => 'cancel', 'details' => 'x'));
check('change request: someone else\'s order refused for a logged-in customer', $v && $v['ok'] === false && $v['message'] === $generic);
$GLOBALS['JAR'] = null;
@unlink($jar);

// ================================================================ handover wait: wait_until / wait_seconds / wait_text
$tgToken = '123456:TEST-token-abcdefghijklmnop';
file_put_contents($storage . '/secret_tg_token.php', "<?php\nreturn " . var_export($tgToken, true) . ";\n");
setting(0, 'g_tg_chat_id', '-100123');
$open = array();
foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $d) {
	$open[$d] = array('from' => '00:00', 'to' => '24:00');
}
$hoursBefore = q("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_s_work_hours'")->fetch_assoc();
setting(0, 's_work_hours', $open);
setting(0, 's_handover_timeout', 60);
tgReset();
resetRates();
$bw = boot($s0);
send($s0, $bw['token'], 'tool:list_categories {}', $s0, false);
list($v, $resp) = probe($s0, $bw['token'], 'request_human', array('reason' => 'wait test'));
$cw = conv($bw['token']);
check('request_human: wait_text from s_handover_timeout (60 s -> 1 minute)', $v && $v['status'] === 'waiting' && $v['wait_text'] === 'I am transferring you to a colleague. This may take up to 1 minute. Please wait.' && strpos($v['message'], $v['wait_text']) !== false, json_encode($v));
check('send (non-stream) carries wait_until / wait_seconds while waiting', $resp['mode'] === 'waiting' && isset($resp['wait_until'], $resp['wait_seconds']) && $resp['wait_until'] === strtotime($cw['waiting_since']) + 60 && $resp['wait_seconds'] > 50 && $resp['wait_seconds'] <= 60, json_encode(array_intersect_key($resp, array('mode' => 1, 'wait_until' => 1, 'wait_seconds' => 1))));
$topics = tgCalls('createForumTopic');
check('Telegram topic title with the resolved store name', $topics && $topics[0]['params']['name'] === 'Магазин Едно · chat ' . $cw['conversation_id'], json_encode($topics, JSON_UNESCAPED_UNICODE));
$p = pollc($s0, $bw['token']);
check('poll carries wait_until / wait_seconds / wait_text', $p['mode'] === 'waiting' && $p['wait_until'] === $resp['wait_until'] && $p['wait_seconds'] <= 60 && $p['wait_text'] === $v['wait_text'], json_encode($p));
$bb = boot($s0, $bw['token']);
check('boot carries wait_until / wait_seconds while waiting', $bb['mode'] === 'waiting' && $bb['wait_until'] === $resp['wait_until'] && isset($bb['wait_seconds']), json_encode(array_intersect_key($bb, array('mode' => 1, 'wait_until' => 1, 'wait_seconds' => 1))));
$st = send($s0, $bw['token'], 'still there?', $s0, true);
$done = null;
foreach ($st['events'] as $e) {
	if ($e[0] === 'done') {
		$done = $e[1];
	}
}
check('stream done event carries wait_until / wait_seconds while waiting', $done && $done['mode'] === 'waiting' && $done['wait_until'] === $resp['wait_until'] && isset($done['wait_seconds']), json_encode($done));
q("UPDATE oc_aichat_conversation SET mode = 'bot', waiting_since = NULL WHERE conversation_id = " . (int)$cw['conversation_id']);
$p = pollc($s0, $bw['token']);
check('no wait fields outside waiting', $p['mode'] === 'bot' && !isset($p['wait_until']) && !isset($p['wait_seconds']), json_encode($p));
setting(0, 's_handover_timeout', 45);
$bw2 = boot($s0);
send($s0, $bw2['token'], 'tool:list_categories {}', $s0, false);
list($v, $resp) = probe($s0, $bw2['token'], 'request_human', array('reason' => 'wait test 2'));
check('45 s -> "up to 45 seconds"', $v && strpos($v['wait_text'], 'up to 45 seconds') !== false && $resp['wait_seconds'] <= 45, json_encode($v));
q("UPDATE oc_aichat_conversation SET mode = 'bot', waiting_since = NULL WHERE conversation_id = " . (int)conv($bw2['token'])['conversation_id']);
setting(0, 's_handover_timeout', null);
setting(0, 'g_tg_chat_id', null);
setting(0, 's_work_hours', $hoursBefore ? json_decode($hoursBefore['value'], true) : null);
@unlink($storage . '/secret_tg_token.php');

// ================================================================ mail through OpenCart's real Mail class (smtp, fake SMTP server)
$mailKeys = array('config_mail_engine', 'config_mail_smtp_hostname', 'config_mail_smtp_port', 'config_mail_smtp_username', 'config_mail_smtp_password', 'config_mail_smtp_timeout', 'config_email');
$mailBefore = array();
foreach (array(0, 1) as $sid) {
	foreach ($mailKeys as $k) {
		$mailBefore[$sid][$k] = cfgValue($sid, $k);
	}
}
foreach (array(0 => $smtp0, 1 => $smtp1) as $sid => $port) {
	cfgRow($sid, 'config_mail_engine', 'smtp');
	cfgRow($sid, 'config_mail_smtp_hostname', '127.0.0.1');
	cfgRow($sid, 'config_mail_smtp_port', (string)$port);
	cfgRow($sid, 'config_mail_smtp_username', 'shop' . $sid);
	cfgRow($sid, 'config_mail_smtp_password', 'secret');
	cfgRow($sid, 'config_mail_smtp_timeout', '5');
}
cfgRow(1, 'config_email', 'second-store@example.com');
setting(1, 's_notify_email', null);
touch($work . '/mail_real.flag');
@unlink($smtpLog);
resetRates();

$bm = boot($s1);
send($s1, $bm['token'], 'tool:list_categories {}', $s1, false);
list($v) = probe($s1, $bm['token'], 'leave_contact', array('email' => 'real@example.com', 'note' => 'real smtp path'));
$row = reqRow('contact');
$smtp = smtpText();
check('real mail (smtp): store 1 request -> store 1 SMTP server, store\'s config_email as recipient', $v && $v['ok'] && (string)$row['mailed'] === '1' && strpos($smtp, '=== ' . $smtp1 . ' ') === 0 && strpos($smtp, 'RCPT TO: <second-store@example.com>') !== false && strpos($smtp, 'AUTH: shop1') !== false && strpos($smtp, 'real smtp path') !== false, json_encode($row) . "\n" . $smtp);
check('real mail: nothing went to the file shortcut', strpos(mailText(), 'real smtp path') === false);

cfgRow(0, 'config_mail_smtp_port', '1');
$errorLog = $oc . '/system/storage/logs/error.log';
$logBefore = is_file($errorLog) ? filesize($errorLog) : 0;
$bf = boot($s0);
send($s0, $bf['token'], 'tool:list_categories {}', $s0, false);
list($v) = probe($s0, $bf['token'], 'leave_contact', array('email' => 'fail@example.com', 'note' => 'smtp down'));
$row = reqRow('contact');
$data = json_decode($row['data'], true);
clearstatcache();
$logTail = is_file($errorLog) ? (string)file_get_contents($errorLog, false, null, $logBefore) : '';
check('real mail failure: request kept, mailed = 0, error text stored', $v && $v['ok'] === true && (string)$row['mailed'] === '0' && !empty($data['mail_error']) && stripos($data['mail_error'], 'refused') !== false, json_encode($row));
check('real mail failure: written to the OpenCart error log', strpos($logTail, 'AI Chat mail not sent (smtp)') !== false, $logTail);
$failedRequestId = (int)$row['request_id'];

@unlink($work . '/mail_real.flag');
foreach ($mailBefore as $sid => $vals) {
	foreach ($vals as $k => $val) {
		cfgRow($sid, $k, $val);
	}
}
setting(0, 'g_couriers', $couriersBefore !== null ? json_decode($couriersBefore, true) : null);
setting(0, 's_store_name', null);
// the failed request stays for the admin browser test (Requests page shows "e-mail not sent")
file_put_contents($work . '/round4_failed_request.txt', (string)$failedRequestId);
resetRates();
