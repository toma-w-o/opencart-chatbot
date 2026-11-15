<?php
/**
 * Round 2 smoke tests (included from smoke_http.php; uses its helpers).
 *
 * Needs SMOKE_DB (fixtures + settings), SMOKE_OC (OpenCart dir: courier
 * credential file) and SMOKE_MAIL (AICHAT_MAIL_FILE of the servers), the
 * fake services (tests/fake_services.php) on AICHAT_TURNSTILE_URL /
 * AICHAT_SPEEDY_BASE.
 */

/** Tool probe inside an existing conversation. Returns array(result data, full response). */
function probe($base, $token, $name, array $input, $say = '', $stream = false, array $extra = array()) {
	$text = ($say !== '' ? 'SAY[' . $say . '] ' : '') . 'tool:' . $name . ' ' . json_encode($input ? $input : new stdClass(), JSON_UNESCAPED_UNICODE);
	$r = send($base, $token, $text, $base, $stream, $extra);

	if ($stream) {
		$reply = '';
		$r['cards'] = array();
		$r['products'] = array();
		foreach ($r['events'] as $e) {
			if ($e[0] === 'delta') {
				$reply .= $e[1]['text'];
			} elseif ($e[0] === 'cards') {
				$r['cards'] = array_merge($r['cards'], $e[1]['items']);
			} elseif ($e[0] === 'products') {
				$r['products'] = array_merge($r['products'], $e[1]['items']);
			} elseif ($e[0] === 'error') {
				$r['error'] = $e[1];
			}
		}
		$r['reply'] = $reply;
	}

	$data = null;
	if (isset($r['reply']) && preg_match('/RESULT(\(error\))?: (.*)$/s', $r['reply'], $m)) {
		$data = json_decode($m[2], true);
		if ($m[1]) {
			$data['_error'] = true;
		}
	}

	return array($data, $r);
}

function cardsOf($r, $type) {
	$out = array();
	foreach (isset($r['cards']) ? $r['cards'] : array() as $c) {
		if ($c['type'] === $type) {
			$out[] = $c;
		}
	}
	return $out;
}

function conv($token) {
	$publicId = substr($token, 0, 32);
	return q("SELECT * FROM oc_aichat_conversation WHERE public_id = '" . $publicId . "'")->fetch_assoc();
}

$oc = getenv('SMOKE_OC');
$mailFile = getenv('SMOKE_MAIL');

// ================================================================ fixtures
$ids = '9001, 9002, 9003, 9004';
foreach (array('order', 'order_product', 'order_total', 'order_history', 'order_option') as $t) {
	q("DELETE FROM oc_$t WHERE order_id IN ($ids)");
}
q("DELETE FROM oc_customer WHERE customer_id = 9100");
q("DELETE FROM oc_customer_wishlist WHERE customer_id = 9100");
q("DROP TABLE IF EXISTS oc_order_view_keys");

function addOrder($id, $store, $customer, $phone) {
	$url = $store ? 'http://127.0.0.1:8081/' : 'http://127.0.0.1:8080/';
	q("INSERT INTO oc_order SET order_id = $id, store_id = $store, store_url = '$url', store_name = 'S$store', customer_id = $customer, customer_group_id = 1,
		firstname = 'Ivan', lastname = 'Petrov', email = 'ivan$id@example.com', telephone = '" . db()->real_escape_string($phone) . "',
		payment_method = 'Cash On Delivery', payment_code = 'cod', shipping_firstname = 'Ivan', shipping_lastname = 'Petrov',
		shipping_address_1 = 'ul. Vitosha 1', shipping_city = 'Sofia', shipping_postcode = '1000', shipping_country = 'Bulgaria',
		shipping_method = 'Speedy to address', shipping_code = 'flat.flat', comment = 'SECRET customer comment', total = 120.5,
		order_status_id = 3, currency_id = 2, currency_code = 'USD', currency_value = 1, language_id = 1, ip = '10.9.9.9',
		date_added = NOW() - INTERVAL $id - 9000 HOUR, date_modified = NOW()");
	q("INSERT INTO oc_order_product SET order_id = $id, product_id = 40, name = 'iPhone', model = 'product 11', quantity = 2, price = 50, total = 100, tax = 0, reward = 0");
	q("INSERT INTO oc_order_total (order_id, code, title, value, sort_order) VALUES ($id, 'sub_total', 'Sub-Total', 100, 1), ($id, 'shipping', 'Speedy', 20.5, 3), ($id, 'total', 'Total', 120.5, 9)");
}
addOrder(9001, 0, 0, '+359 888 123 456');
addOrder(9002, 1, 0, '0888 123 456');
addOrder(9003, 0, 9100, '0877 000 111');
addOrder(9004, 1, 9100, '0877 000 111');

$h = function ($id, $notify, $comment, $hoursAgo) {
	q("INSERT INTO oc_order_history SET order_id = $id, order_status_id = 3, notify = $notify, comment = '" . db()->real_escape_string($comment) . "', date_added = NOW() - INTERVAL $hoursAgo HOUR");
};
$h(9001, 0, 'INTERNAL: supplier X, cost 12 USD, https://www.speedy.bg/bg/track-shipment?shipmentNumber=11122233344', 30);
$h(9001, 1, 'Поръчката е изпратена със Speedy: https://www.speedy.bg/bg/track-shipment?shipmentNumber=63798828004,63798837619', 20);
$h(9001, 1, 'Втора пратка: https://www.speedy.bg/bg/track-shipment?shipmentNumber=63798837619%2C63700000000 и https://nextlevel.example/track/12345678', 10);
$h(9003, 1, 'Товарителница Speedy 63798828004', 5);

// logged-in customer (password "secret", md5 form accepted by OpenCart 3)
q("INSERT INTO oc_customer SET customer_id = 9100, customer_group_id = 1, store_id = 0, language_id = 1, firstname = 'Smoke', lastname = 'Tester', email = 'smoke@example.com', telephone = '0877000111', password = '" . md5('secret') . "', salt = '', custom_field = '', ip = '', status = 1, safe = 0, token = '', code = '', date_added = NOW()");
q("INSERT INTO oc_customer_wishlist (customer_id, product_id, date_added) VALUES (9100, 40, NOW()), (9100, 48, NOW())");

// couriers: Speedy (API, fake) + a link-only courier
setting(0, 'g_couriers', array(
	array('code' => 'speedy', 'name' => 'Speedy', 'type' => 'speedy', 'hosts' => array('speedy.bg'), 'track_url' => 'https://www.speedy.bg/bg/track-shipment?shipmentNumber={number}'),
	array('code' => 'nextlevel', 'name' => 'NextLevel', 'type' => 'link', 'hosts' => array('nextlevel.example'), 'track_url' => 'https://nextlevel.example/t/{number}'),
));
setting(0, 's_courier', 'speedy');
setting(0, 's_order_link_button', 0);
@mkdir($oc . '/system/storage/aichat', 0700, true);
file_put_contents($oc . '/system/storage/aichat/courier_speedy.php', "<?php\nreturn " . var_export(array('username' => 'speedy-user', 'password' => 'speedy-pass'), true) . ";\n");
q("DELETE FROM oc_setting WHERE `key` LIKE 'module_aichat_g_turnstile%'");
// many tool probes per conversation: per-minute limits off for this part (restored at the end)
setting(0, 's_max_msgs_minute', 0);
setting(1, 's_max_msgs_minute', 0);
foreach (glob($oc . '/system/storage/cache/cache.aichat.speedy.*') ?: array() as $f) {
	@unlink($f);
}
resetRates();

$s0 = $store0;
$s1 = $store1;

// ================================================================ guest order verification
$b = boot($s0);
$t = $b['token'];
check('boot: logged_in false for guest', $b['logged_in'] === false);

list($r1) = probe($s0, $t, 'find_order', array('order_id' => '9001', 'phone' => '0888 999 999'));
check('find_order: wrong phone -> not found', $r1 && $r1['found'] === false, json_encode($r1));
list($r2) = probe($s0, $t, 'find_order', array('order_id' => '9002', 'phone' => '0888 123 456'));
check('find_order: order of the other store -> same generic reply', $r2 && $r2['found'] === false && $r2['message'] === $r1['message'], json_encode($r2));
list($r3) = probe($s0, $t, 'find_order', array('order_id' => '777777', 'phone' => '0888 123 456'));
check('find_order: unknown order -> same generic reply', $r3 && $r3['message'] === $r1['message']);
check('find_order: attempts counted', (int)conv($t)['order_attempts'] === 3, json_encode(conv($t)));

list($ok, $resp) = probe($s0, $t, 'find_order', array('order_id' => '#9001', 'phone' => '+359888123456'));
$json = json_encode($ok, JSON_UNESCAPED_UNICODE);
check('find_order: right phone (+359...) -> found', $ok && $ok['found'] === true && $ok['order_id'] === 9001 && (int)conv($t)['verified_order_id'] === 9001, $json);
check('order: products, totals, status, address', isset($ok['products'][0]['name'], $ok['totals'][2]['value'], $ok['status'], $ok['shipping_address']) && $ok['products'][0]['quantity'] === 2, $json);
check('order: notify=0 history and private fields hidden', strpos($json, 'INTERNAL') === false && strpos($json, '11122233344') === false && strpos($json, 'SECRET') === false && strpos($json, 'ivan9001@') === false && strpos($json, '10.9.9.9') === false && strpos($json, '888') === false, $json);
$numbers = array();
foreach (isset($ok['tracking']) ? $ok['tracking'] : array() as $tr) {
	$numbers[] = $tr['number'];
}
check('tracking numbers: comma list, 2 links, %2C, deduped, other courier', $numbers === array('63798828004', '63798837619', '63700000000', '12345678'), json_encode($numbers));
check('order + tracking cards', count(cardsOf($resp, 'order')) === 1 && count(cardsOf($resp, 'tracking')) === 4 && !isset(cardsOf($resp, 'order')[0]['button']), json_encode($resp['cards']));
$tc = cardsOf($resp, 'tracking');
check('tracking card hrefs from track_url', $tc && $tc[0]['href'] === 'https://www.speedy.bg/bg/track-shipment?shipmentNumber=63798828004' && $tc[3]['href'] === 'https://nextlevel.example/t/12345678', json_encode($tc));

foreach (array('0888 123 456', '888123456', '+359 (888) 12-34-56', '00359888123456') as $phone) {
	resetRates();
	$bb = boot($s0);
	list($v) = probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => $phone));
	check('find_order phone format "' . $phone . '"', $v && $v['found'] === true, json_encode($v));
}
resetRates();
$bb = boot($s1);
list($v) = probe($s1, $bb['token'], 'find_order', array('order_id' => '9002', 'phone' => '+359888123456'));
check('find_order in store 1 for its own order', $v && $v['found'] === true);
$bb = boot($s0);
list($v) = probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => '123456789012345'));
check('find_order: phone with same digits elsewhere does not match', $v && $v['found'] === false);

// ---------------------------------------------------------------- tracking
list($tr, $resp) = probe($s0, $t, 'track_shipment', array('number' => '63798828004'));
check('track_shipment: Speedy delivered (fake API)', $tr && $tr['status'] === 'delivered' && $tr['delivered'] === true && $tr['location'] === 'Пловдив' && preg_match('/^2026-11-11 \d\d:05$/', $tr['last_time']), json_encode($tr, JSON_UNESCAPED_UNICODE));
check('track_shipment: tracking card with status', ($c = cardsOf($resp, 'tracking')) && strpos($c[0]['subtitle'], '2026-11-11') !== false, json_encode($resp['cards'], JSON_UNESCAPED_UNICODE));
list($tr) = probe($s0, $t, 'track_shipment', array('number' => '63798837619'));
check('track_shipment: in transit', $tr && $tr['status'] === 'in_transit' && $tr['last_event'] === 'В транзит към Варна', json_encode($tr, JSON_UNESCAPED_UNICODE));
list($tr, $resp) = probe($s0, $t, 'track_shipment', array('number' => '63700000000'));
check('track_shipment: courier API failure -> link only, no error', $tr && $tr['found'] === true && $tr['status'] === 'not_available' && empty($tr['_error']) && cardsOf($resp, 'tracking')[0]['href'] === 'https://www.speedy.bg/bg/track-shipment?shipmentNumber=63700000000', json_encode($tr));
list($tr) = probe($s0, $t, 'track_shipment', array('number' => '12345678'));
check('track_shipment: link-only courier', $tr && $tr['status'] === 'not_available' && $tr['courier'] === 'NextLevel', json_encode($tr));
list($tr) = probe($s0, $t, 'track_shipment', array('number' => '11122233344'));
check('track_shipment: number from a notify=0 comment refused', $tr && $tr['found'] === false, json_encode($tr));
$bb = boot($s0);
list($tr) = probe($s0, $bb['token'], 'track_shipment', array('number' => '63798828004'));
check('track_shipment: unverified conversation refused', $tr && $tr['found'] === false, json_encode($tr));

// ---------------------------------------------------------------- link filter: tracking links only after verification
$say = 'Track: https://www.speedy.bg/bg/track-shipment?shipmentNumber=63798828004 or https://evil.example.com/x';
list(, $resp) = probe($s0, $t, 'list_categories', array(), $say, true);
check('stream: tracking link of the verified order kept, foreign removed', strpos($resp['reply'], 'shipmentNumber=63798828004') !== false && strpos($resp['reply'], 'evil.example') === false, $resp['reply']);
list(, $resp) = probe($s0, $t, 'list_categories', array(), 'See https://www.speedy.bg/bg/track-shipment?shipmentNumber=99999999999 now', false);
check('tracking link not from the order removed', strpos($resp['reply'], '99999999999') === false, $resp['reply']);
$bb = boot($s0);
list(, $resp) = probe($s0, $bb['token'], 'list_categories', array(), $say, true);
check('stream: tracking link removed without verified order', strpos($resp['reply'], 'speedy.bg') === false, $resp['reply']);
resetRates();
$bb = boot($s0);
list(, $resp) = probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => '0888123456'), $say, true);
check('stream: link allowed in the same reply that verified the order', strpos($resp['reply'], 'shipmentNumber=63798828004') !== false && strpos($resp['reply'], 'evil') === false, $resp['reply']);

// ---------------------------------------------------------------- attempt limit
resetRates();
$bb = boot($s0);
for ($i = 0; $i < 5; $i++) {
	probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => '0700 00' . $i));
}
list($v) = probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => '0888123456'));
check('attempt limit: 6th try locked even with the right phone', $v && $v['found'] === false && !empty($v['locked']), json_encode($v));
resetRates();
$codes = array();
for ($i = 0; $i < 11; $i++) {
	if ($i % 5 === 0) {
		$bb = boot($s0); // new conversations, same IP
	}
	list($v) = probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => '0700 11' . $i));
	$codes[] = !empty($v['locked']) ? 'L' : 'n';
}
check('attempt limit per IP (10/hour) across conversations', $codes[9] === 'n' && $codes[10] === 'L', implode('', $codes));
resetRates();

// ---------------------------------------------------------------- view order link (order_view_keys)
setting(0, 's_order_link_button', 1);
$bb = boot($s0);
list($v, $resp) = probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => '0888123456'));
check('view order: table absent -> no button', $v['found'] === true && !isset(cardsOf($resp, 'order')[0]['button']) && cardsOf($resp, 'order')[0]['href'] === '', json_encode($resp['cards']));
q("CREATE TABLE oc_order_view_keys (order_id INT(11) NOT NULL, view_key VARCHAR(64) NOT NULL, PRIMARY KEY (order_id))");
q("INSERT INTO oc_order_view_keys VALUES (9001, 'existingkey123')");
list($v, $resp) = probe($s0, $bb['token'], 'find_order', array('order_id' => '9001', 'phone' => '0888123456'));
$oc1 = cardsOf($resp, 'order');
check('view order: existing key reused', $oc1 && $oc1[0]['href'] === 'http://127.0.0.1:8080/index.php?route=extension/module/order_view&order_id=9001&key=existingkey123' && !empty($oc1[0]['button']), json_encode($oc1));
check('view order: existing key not overwritten', q("SELECT view_key FROM oc_order_view_keys WHERE order_id = 9001")->fetch_assoc()['view_key'] === 'existingkey123');
$bb2 = boot($s0);
list($v, $resp) = probe($s0, $bb2['token'], 'find_order', array('order_id' => '9001', 'phone' => '1111111111'));
check('view order: no card / link before verification', $v['found'] === false && !cardsOf($resp, 'order'));

// ================================================================ logged-in customer
$jar = tempnam(sys_get_temp_dir(), 'aichat_jar');
$GLOBALS['JAR'] = $jar;
http('GET', $s0 . 'index.php?route=account/login');
http('POST', $s0 . 'index.php?route=account/login', 'email=smoke%40example.com&password=secret');
$bl = boot($s0);
check('boot: logged_in true after login', $bl['logged_in'] === true, json_encode(array_intersect_key($bl, array('logged_in' => 1))));
list($mo) = probe($s0, $bl['token'], 'my_orders', array('limit' => 5));
$mine = array();
foreach (isset($mo['orders']) ? $mo['orders'] : array() as $o) {
	$mine[] = $o['order_id'];
}
check('my_orders: own orders of this store only', $mine === array(9003), json_encode($mo));
list($mo) = probe($s0, $bl['token'], 'my_order', array('order_id' => '9001'));
check('my_order: someone else\'s order not found', $mo && $mo['found'] === false, json_encode($mo));
list($mo) = probe($s0, $bl['token'], 'my_order', array('order_id' => '9004'));
check('my_order: own order of the other store not found', $mo && $mo['found'] === false);
list($mo, $resp) = probe($s0, $bl['token'], 'my_order', array('order_id' => '9003'));
$keyRow = q("SELECT view_key FROM oc_order_view_keys WHERE order_id = 9003")->fetch_assoc();
check('my_order: own order with tracking (bare number + keyword)', $mo && $mo['found'] === true && $mo['tracking'][0]['number'] === '63798828004', json_encode($mo));
check('view order: key created when missing', $keyRow && strlen($keyRow['view_key']) >= 40 && strpos(cardsOf($resp, 'order')[0]['href'], 'key=' . $keyRow['view_key']) !== false, json_encode(array($keyRow, $resp['cards'])));
list($mo, $resp) = probe($s0, $bl['token'], 'my_order', array('order_id' => '9003'));
check('view order: created key stable on the next call', q("SELECT COUNT(*) n, MAX(view_key) k FROM oc_order_view_keys WHERE order_id = 9003")->fetch_assoc() === array('n' => '1', 'k' => $keyRow['view_key']));
list($tr) = probe($s0, $bl['token'], 'track_shipment', array('number' => '63798828004'));
check('track_shipment for own order (logged in)', $tr && $tr['found'] === true && $tr['status'] === 'delivered', json_encode($tr));
list($w) = probe($s0, $bl['token'], 'my_wishlist', array());
check('my_wishlist: store-bound products', $w && ids($w) === array(40), json_encode($w));
$GLOBALS['JAR'] = null;
@unlink($jar);
$bg = boot($s0);
list($mo) = probe($s0, $bg['token'], 'my_orders', array());
check('my_orders as guest -> not logged in', $mo && $mo['error'] === 'not_logged_in', json_encode($mo));
list($w) = probe($s0, $bg['token'], 'my_wishlist', array());
check('my_wishlist as guest -> not logged in', $w && $w['error'] === 'not_logged_in');
setting(0, 's_order_link_button', 0);
q("DROP TABLE IF EXISTS oc_order_view_keys");
resetRates();

// ================================================================ specials, brands, link cards
$bb = boot($s0);
list($sp) = probe($s0, $bb['token'], 'get_specials', array('limit' => 5));
check('get_specials: discounted products only', $sp && ids($sp) && !array_filter($sp['products'], function ($p) {
	return $p['special'] === null;
}), json_encode($sp));
list($m) = probe($s0, $bb['token'], 'search_manufacturers', array('query' => 'apple'));
check('search_manufacturers', $m && $m['manufacturers'] && $m['manufacturers'][0]['name'] === 'Apple', json_encode($m));
list($m) = probe($s0, $bb['token'], 'list_manufacturers', array());
check('list_manufacturers', $m && count($m['manufacturers']) >= 5);

$items = array(array('type' => 'category', 'id' => 20), array('type' => 'manufacturer', 'id' => 8), array('type' => 'information', 'id' => 4), array('type' => 'special'), array('type' => 'contact'), array('type' => 'category', 'id' => 99999));
list($l, $resp) = probe($s0, $bb['token'], 'show_links', array('items' => $items), '', true);
$types = array();
foreach ($resp['cards'] as $c) {
	$types[] = $c['type'];
}
check('show_links: cards event in stream', $types === array('category', 'manufacturer', 'information', 'special', 'contact') && count($l['not_found']) === 1, json_encode($resp['cards']));
$byType = array();
foreach ($resp['cards'] as $c) {
	$byType[$c['type']] = $c;
}
check('link cards: server-built hrefs', strpos($byType['category']['href'], 'route=product/category&path=20') !== false && strpos($byType['special']['href'], 'route=product/special') !== false && strpos($byType['information']['href'], 'information_id=4') !== false && strpos($byType['contact']['href'], 'information/contact') !== false, json_encode($byType));
check('link cards: manufacturer image resized by OpenCart', isset($byType['manufacturer']['image']) && strpos($byType['manufacturer']['image'], 'image/cache/') !== false && strpos($byType['manufacturer']['image'], '100x100') !== false, json_encode($byType['manufacturer']));
check('contact card subtitle from store contacts', isset($byType['contact']['subtitle']) && strpos($byType['contact']['subtitle'], 'shop0@example.com') !== false, json_encode($byType['contact']));
list($l, $resp) = probe($s0, $bb['token'], 'show_links', array('items' => array(array('type' => 'special'))), '', false);
check('show_links: non-stream cards array', isset($resp['cards'][0]['type']) && $resp['cards'][0]['type'] === 'special', json_encode($resp));
$bs1 = boot($s1);
list($l) = probe($s1, $bs1['token'], 'show_links', array('items' => array(array('type' => 'category', 'id' => 25), array('type' => 'information', 'id' => 6))));
check('show_links: other store\'s category / page not linked', $l && !$l['shown'] && count($l['not_found']) === 2, json_encode($l));

// ================================================================ returning visitor
$h1 = boot($s0, $bb['token']);
check('boot returning: true with history', $h1['returning'] === true && $h1['token'] === $bb['token'] && $h1['welcome_back'] !== '', json_encode(array($h1['returning'], $h1['welcome_back'])));
$withCards = array_values(array_filter($h1['history'], function ($m) {
	return !empty($m['cards']);
}));
check('history items carry cards', $withCards && $withCards[0]['cards'][0]['type'] === 'category');
$fresh = boot($s0);
check('boot new visitor: returning false', $fresh['returning'] === false && $fresh['history'] === array());
check('boot appearance object', isset($fresh['appearance']['accent'], $fresh['appearance']['launcher']['shape'], $fresh['appearance']['position']['mobile']['side'], $fresh['appearance']['z_index']) && $fresh['appearance']['launcher']['size'] === 56 && $fresh['appearance']['launcher']['avatar_size'] === 45 && $fresh['appearance']['position']['desktop'] === array('side' => 'right', 'x' => 20, 'y' => 20) && $fresh['appearance']['launcher']['text'] === 'Bot0', json_encode($fresh['appearance']));
setting(0, 's_quick_replies', array('1' => "Delivery?\nReturns?\n\n"));
setting(0, 's_launcher_style', 'avatar_text');
setting(0, 's_welcome_back', array('1' => 'Hello again!'));
setting(0, 's_color_accent', 'red;}</style>');
$fresh = boot($s0, $bb['token']);
check('quick_replies by language', $fresh['quick_replies'] === array('Delivery?', 'Returns?'), json_encode($fresh['quick_replies']));
check('welcome_back from settings, avatar_text -> smaller avatar, bad colour -> default', $fresh['welcome_back'] === 'Hello again!' && $fresh['appearance']['launcher']['avatar_size'] === 36 && $fresh['appearance']['accent'] === '#4f46e5', json_encode($fresh['appearance']));
foreach (array('s_quick_replies', 's_launcher_style', 's_welcome_back', 's_color_accent') as $k) {
	setting(0, $k, null);
}
q("UPDATE oc_aichat_conversation SET date_modified = NOW() - INTERVAL 8 DAY WHERE public_id = '" . substr($bb['token'], 0, 32) . "'");
$old = boot($s0, $bb['token']);
check('resume window (7 days): old conversation not resumed', $old['returning'] === false && $old['token'] !== $bb['token'] && $old['history'] === array());
setting(0, 's_resume_days', 10);
$old = boot($s0, $bb['token']);
check('resume window from s_resume_days', $old['returning'] === true && $old['token'] === $bb['token']);
setting(0, 's_resume_days', null);
resetRates();

// ================================================================ Turnstile
setting(0, 'g_turnstile_site', 'site-key');
setting(0, 'g_turnstile_secret', 'test-secret');
$bt = boot($s0);
check('boot: turnstile_site', $bt['turnstile_site'] === 'site-key');
$r = send($s0, $bt['token'], 'tool:list_categories {}', $s0, false);
check('turnstile: no token -> challenge', isset($r['error']['code']) && $r['error']['code'] === 'challenge' && $r['error']['message'] !== '', json_encode($r));
$r = send($s0, $bt['token'], 'tool:list_categories {}', $s0, false, array('turnstile' => 'bad'));
check('turnstile: rejected token -> challenge again', isset($r['error']['code']) && $r['error']['code'] === 'challenge');
$s = send($s0, $bt['token'], 'tool:list_categories {}', $s0, true);
check('turnstile: stream challenge event', $s['events'] && $s['events'][0][0] === 'error' && $s['events'][0][1]['code'] === 'challenge', $s['raw']);
$r = send($s0, $bt['token'], 'tool:list_categories {}', $s0, false, array('turnstile' => 'good'));
check('turnstile: good token -> answered', isset($r['reply']) && strpos($r['reply'], 'RESULT') !== false && (int)conv($bt['token'])['human_verified'] === 1, json_encode($r));
$r = send($s0, $bt['token'], 'tool:list_categories {}', $s0, false);
check('turnstile: remembered on the conversation', isset($r['reply']) && strpos($r['reply'], 'RESULT') !== false, json_encode($r));
$bt2 = boot($s0);
$r = send($s0, $bt2['token'], 'tool:list_categories {}', $s0, false, array('turnstile' => 'outage'));
check('turnstile outage (HTTP 500) -> soft pass', isset($r['reply']) && (int)conv($bt2['token'])['human_verified'] === 2, json_encode($r));
$r = send($s0, $bt2['token'], 'tool:list_categories {}', $s0, false);
check('turnstile outage remembered (no new challenge)', isset($r['reply']), json_encode($r));
$bt3 = boot($s0);
$r = send($s0, $bt3['token'], 'tool:list_categories {}', $s0, false, array('turnstile' => 'cf-internal'));
check('turnstile internal-error -> soft pass', isset($r['reply']), json_encode($r));
$bt4 = boot($s0);
$r = send($s0, $bt4['token'], 'tool:list_categories {}', $s0, false, array('turnstile' => 'unavailable'));
check('turnstile unavailable in browser -> answered, not remembered', isset($r['reply']) && (int)conv($bt4['token'])['human_verified'] === 0, json_encode($r));
setting(0, 'g_turnstile_site', null);
setting(0, 'g_turnstile_secret', null);
$b5 = boot($s0);
check('turnstile off -> turnstile_site null', $b5['turnstile_site'] === null);
resetRates();

// ================================================================ CF-Connecting-IP
$GLOBALS['HEADERS'] = array('CF-Connecting-IP: 203.0.113.9');
$bi = boot($s0);
send($s0, $bi['token'], 'tool:list_categories {}', $s0, false);
check('g_trust_cf_ip off: REMOTE_ADDR used', conv($bi['token'])['ip'] === '127.0.0.1');
setting(0, 'g_trust_cf_ip', 1);
$bi = boot($s0);
send($s0, $bi['token'], 'tool:list_categories {}', $s0, false);
check('g_trust_cf_ip on: CF-Connecting-IP used', conv($bi['token'])['ip'] === '203.0.113.9');
setting(0, 'g_trust_cf_ip', null);
$GLOBALS['HEADERS'] = array();
resetRates();

// ================================================================ daily chat cap + budget warning (store 1)
$today = q("SELECT chats FROM oc_aichat_usage WHERE store_id = 1 AND day = CURDATE()")->fetch_assoc();
$existing = boot($s1);
send($s1, $existing['token'], 'tool:list_categories {}', $s1, false);
setting(1, 's_max_chats_day', (int)$today['chats'] + 1);
$bn = boot($s1);
$r = send($s1, $bn['token'], 'tool:list_categories {}', $s1, false);
check('daily chat cap: new chat refused', isset($r['error']['code']) && $r['error']['code'] === 'chat_day_limit', json_encode($r));
$r = send($s1, $existing['token'], 'tool:list_categories {}', $s1, false);
check('daily chat cap: running chat continues', isset($r['reply']), json_encode($r));
setting(1, 's_max_chats_day', 0);

@unlink($mailFile);
$month = q("SELECT SUM(cost) c FROM oc_aichat_usage WHERE store_id = 1 AND day >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch_assoc();
setting(1, 's_budget_month', round((float)$month['c'] / 0.8 + 0.0005, 6)); // just under 80%, the next calls cross it
setting(0, 'g_warn_email', 'owner@example.com');
setting(0, 'g_warn_sent', null);
$bw = boot($s1);
send($s1, $bw['token'], 'tool:list_categories {}', $s1, false);
send($s1, $bw['token'], 'tool:list_categories {}', $s1, false);
$mail = is_file($mailFile) ? file_get_contents($mailFile) : '';
check('budget 80% warning mailed once', substr_count($mail, 'Subject: AI Chat: 80%') === 1 && strpos($mail, 'To: owner@example.com') !== false && strpos($mail, 'Second Store') !== false, $mail);
$sent = q("SELECT value FROM oc_setting WHERE `key` = 'module_aichat_g_warn_sent'")->fetch_assoc();
check('warning remembered for this month', $sent && strpos($sent['value'], '"store_1":"' . date('Y-m') . '"') !== false, json_encode($sent));
setting(1, 's_budget_month', 6);
setting(0, 'g_warn_email', null);
resetRates();
setting(0, 's_max_msgs_minute', 6);
setting(1, 's_max_msgs_minute', 6);
