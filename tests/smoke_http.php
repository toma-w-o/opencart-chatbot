<?php
/**
 * End-to-end smoke test against a running OpenCart 3.0.3.6 (demo data) with the
 * module installed and the fake API (tests/fake_anthropic.php) on AICHAT_API_BASE.
 *
 *   php tests/smoke_http.php http://127.0.0.1:8080/ http://127.0.0.1:8081/
 *
 * Store 0 = demo store; store 1 = second store with products 28, 43, 48 only
 * (43 MacBook and 48 iPod Classic removed from store 0), seo_url keyword
 * "ipod-classic-s1" for product 48 in store 1. Both stores enabled in the admin.
 * Rate counters are reset through MySQL between groups (env SMOKE_DB=host:user:pass:db:prefix).
 * Exit code 1 on any failure.
 */

$store0 = isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8080/';
$store1 = isset($argv[2]) ? $argv[2] : 'http://127.0.0.1:8081/';
$fails = 0;

function resetRates() {
	$dsn = getenv('SMOKE_DB');
	if (!$dsn) {
		return;
	}
	list($host, $user, $pass, $db, $prefix) = explode(':', $dsn);
	$m = new mysqli($host, $user, $pass, $db);
	$m->query('DELETE FROM `' . $prefix . 'aichat_rate`');
	$m->close();
}

resetRates();

function check($name, $ok, $detail = '') {
	global $fails;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	if (!$ok) {
		$fails++;
	}
}

function http($method, $url, $body = null) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	}
	$out = curl_exec($ch);
	$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);
	return array($out, $type);
}

function boot($base, $token = '', $url = '') {
	list($out) = http('GET', $base . 'index.php?route=extension/module/aichat/boot&url=' . rawurlencode($url) . ($token ? '&token=' . rawurlencode($token) : ''));
	return json_decode($out, true);
}

function send($base, $token, $message, $url, $stream) {
	list($out, $type) = http('POST', $base . 'index.php?route=extension/module/aichat/send', array('token' => $token, 'message' => $message, 'url' => $url, 'stream' => $stream ? 1 : 0));
	if (!$stream) {
		return json_decode($out, true);
	}
	$events = array();
	foreach (preg_split("/\n\n/", $out) as $chunk) {
		if (preg_match('/^event: (\w+)\ndata: (.*)$/s', trim($chunk), $m)) {
			$events[] = array($m[1], json_decode($m[2], true));
		}
	}
	return array('type' => $type, 'events' => $events, 'raw' => $out);
}

/** Runs one tool through the model loop (fake API echoes the tool result). */
function tool($base, $name, array $input, $url = '') {
	$b = boot($base);
	$r = send($base, $b['token'], 'tool:' . $name . ' ' . json_encode($input), $url, false);
	if (!isset($r['reply']) || !preg_match('/^RESULT(\(error\))?: (.*)$/s', $r['reply'], $m)) {
		return array('_raw' => $r);
	}
	$data = json_decode($m[2], true);
	if ($m[1]) {
		$data['_error'] = true;
	}
	return $data;
}

function ids($result) {
	$ids = array();
	foreach (isset($result['products']) ? $result['products'] : array() as $p) {
		$ids[] = $p['product_id'];
	}
	return $ids;
}

// ---------------------------------------------------------------- boot
$b0 = boot($store0);
$b1 = boot($store1);
check('boot store 0 enabled', !empty($b0['enabled']) && $b0['bot_name'] === 'Bot0', json_encode($b0));
check('boot store 1 has its own settings', !empty($b1['enabled']) && $b1['bot_name'] === 'Bot1' && $b1['emoji'] === 1);
check('boot strings for widget', isset($b0['strings']['ai_label'], $b0['strings']['placeholder'], $b0['strings']['send'], $b0['strings']['too_long'], $b0['strings']['greeting'], $b0['strings']['messages']));
check('token is per store', isset($b0['token']) && preg_match('/^[a-f0-9]{32}\.[\w-]{22}$/', $b0['token']));

// ---------------------------------------------------------------- tools, store bound
$r = tool($store0, 'search_products', array('query' => 'ipod'));
$ids = ids($r);
check('search store 0: ipods without iPod Classic (48)', in_array(32, $ids) && !in_array(48, $ids), json_encode($r));

$r = tool($store1, 'search_products', array('query' => 'ipod'));
check('search store 1: only iPod Classic', ids($r) === array(48), json_encode($r));

$r = tool($store0, 'search_products', array('query' => 'macbook pro'));
check('multi-word search: MacBook Pro first', ids($r) && ids($r)[0] === 45, json_encode($r));

$r = tool($store0, 'search_products', array('query' => 'Product 15'));
check('search by model code (exact model first)', ids($r) && ids($r)[0] === 42, json_encode($r));

$r = tool($store0, 'search_products', array('query' => 'iphone', 'category_id' => 24));
check('search with category filter (Phones & PDAs)', ids($r) === array(40), json_encode($r));

$r = tool($store0, 'search_products', array('query' => 'iphone', 'category_id' => 18));
check('search with category filter excludes other categories', ids($r) === array(), json_encode($r));

$r = tool($store0, 'search_products', array('query' => 'zzzz nothing'));
check('search without match', isset($r['products']) && $r['products'] === array());

$p = isset($r) ? tool($store0, 'get_product', array('product_id' => 42)) : array();
check('get_product: fields + options, no quantity/cost', isset($p['name'], $p['price'], $p['in_stock'], $p['options']) && !isset($p['quantity']) && strpos(json_encode($p), '"quantity"') === false, json_encode($p));

$p = tool($store0, 'get_product', array('product_id' => 48));
check('get_product of other store is not found', !empty($p['_error']), json_encode($p));

$p = tool($store1, 'get_product', array('product_id' => 48));
check('get_product store 1 ok', isset($p['name']) && $p['name'] === 'iPod Classic', json_encode($p));

$c = tool($store1, 'list_categories', array('parent_id' => 0));
$names = array();
foreach ($c['categories'] as $cat) {
	$names[] = $cat['name'];
}
check('list_categories store 1 limited to its categories', $names && !in_array('Cameras', $names) && in_array('Desktops', $names), json_encode($c));

$i = tool($store0, 'get_information', array('keyword' => 'delivery'));
check('get_information by keyword', !empty($i['found']) && stripos($i['title'], 'Delivery') !== false, json_encode($i));

$i = tool($store1, 'get_information', array('information_id' => 6));
check('get_information of other store hidden', empty($i['found']), json_encode($i));

$bad = tool($store0, 'get_product', array('product_id' => 'x'));
check('invalid tool input -> is_error', !empty($bad['_error']));

resetRates();

// ---------------------------------------------------------------- page context (via the user message meta in history)
$b = boot($store1);
$r = send($store1, $b['token'], 'tool:list_categories {}', $store1 . 'ipod-classic-s1', false);
$h = boot($store1, $b['token']);
check('history returned on boot with token', count($h['history']) === 2 && $h['history'][0]['content'] === 'tool:list_categories {}', json_encode($h['history']));

// ---------------------------------------------------------------- streaming + link filter + cards
$b = boot($store0);
$s = send($store0, $b['token'], 'show me apple cinema', $store0 . 'index.php?route=product/category&path=25_28', true);
$types = array();
$text = '';
$cards = array();
foreach ($s['events'] as $e) {
	$types[] = $e[0];
	if ($e[0] === 'delta') {
		$text .= $e[1]['text'];
	}
	if ($e[0] === 'products') {
		$cards = $e[1]['items'];
	}
}
check('stream content type', strpos($s['type'], 'text/event-stream') === 0, $s['type']);
check('stream events delta/products/done', in_array('delta', $types) && in_array('products', $types) && end($types) === 'done', implode(',', $types));
check('stream: foreign links removed, own link kept', strpos($text, 'emag') === false && strpos($text, 'ozone') === false && strpos($text, 'amazon') === false && strpos($text, '[our page](' . $store0) !== false, $text);
check('card fields', $cards && isset($cards[0]['product_id'], $cards[0]['name'], $cards[0]['href'], $cards[0]['image'], $cards[0]['price']) && array_key_exists('special', $cards[0]), json_encode($cards));

// non-streaming
$r = send($store0, $b['token'], 'show me iphone', $store0, false);
check('non-stream reply/products/mode', isset($r['reply'], $r['products'], $r['mode']) && $r['mode'] === 'bot' && strpos($r['reply'], 'emag') === false, json_encode($r));

// ---------------------------------------------------------------- errors
$r = send($store0, 'bogus', 'hi', $store0, false);
check('invalid token -> localised error', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token' && $r['error']['message'] !== '', json_encode($r));

$r = send($store1, $b0['token'], 'hi', $store1, false);
check('token of another store rejected', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token');

$r = send($store0, $b['token'], str_repeat('a', 1001), $store0, false);
check('too long', isset($r['error']['code']) && $r['error']['code'] === 'too_long', json_encode($r));

$s = send($store0, 'bogus', 'hi', $store0, true);
check('stream error event', $s['events'] && $s['events'][0][0] === 'error' && $s['events'][0][1]['code'] === 'invalid_token', $s['raw']);

resetRates();
$b = boot($store0);
$codes = array();
for ($n = 0; $n < 8; $n++) {
	$r = send($store0, $b['token'], 'tool:list_categories {}', $store0, false);
	$codes[] = isset($r['error']) ? $r['error']['code'] : 'ok';
}
check('rate limit per minute (6)', array_slice($codes, 0, 6) === array_fill(0, 6, 'ok') && $codes[6] === 'rate_limited', implode(',', $codes));

exit($fails ? 1 : 0);
