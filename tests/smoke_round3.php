<?php
/**
 * Round 3 smoke tests (included from smoke_http.php after round 2; uses its helpers).
 *
 * Needs SMOKE_DB, SMOKE_OC, SMOKE_MAIL and the fake services on
 * AICHAT_TELEGRAM_BASE = SMOKE_TG (default http://127.0.0.1:8098/tg).
 */

$tg = getenv('SMOKE_TG') ? getenv('SMOKE_TG') : 'http://127.0.0.1:8098/tg';
$tgRoot = preg_replace('#/tg$#', '', $tg);
$fakeLog = getenv('SMOKE_FAKE_LOG') ? getenv('SMOKE_FAKE_LOG') : dirname($oc) . '/fake.log';
$storage = $oc . '/system/storage/aichat';

function mailText() {
	$f = getenv('SMOKE_MAIL');
	return is_file($f) ? (string)file_get_contents($f) : '';
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

/** Last request body sent to the fake model API. */
function lastModelRequest() {
	global $fakeLog;
	$lines = file($fakeLog);
	for ($i = count($lines) - 1; $i >= 0; $i--) {
		if (strpos($lines[$i], ' /v1/messages ') !== false) {
			return substr($lines[$i], strpos($lines[$i], ' /v1/messages ') + 14);
		}
	}
	return '';
}

function post($base, $route, array $body) {
	list($out) = http('POST', $base . 'index.php?route=extension/module/aichat/' . $route, $body);
	return json_decode($out, true);
}

function upload($base, $token, $file, $name = 'photo.jpg') {
	$ch = curl_init($base . 'index.php?route=extension/module/aichat/upload');
	curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
		CURLOPT_POSTFIELDS => array('token' => $token, 'file' => new CURLFile($file, 'image/jpeg', $name))));
	if ($GLOBALS['JAR']) {
		curl_setopt($ch, CURLOPT_COOKIEJAR, $GLOBALS['JAR']);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $GLOBALS['JAR']);
	}
	$out = curl_exec($ch);
	curl_close($ch);
	return json_decode($out, true);
}

function fetch($url) {
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);
	return array($code, $type, $body);
}

function webhook($base, $secretPath, $secretHeader, array $update) {
	$ch = curl_init($base . 'index.php?route=extension/module/aichat/telegram&s=' . rawurlencode($secretPath));
	curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => json_encode($update),
		CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $secretHeader)));
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array($code, json_decode($body, true));
}

function pollc($base, $token, $after = 0) {
	list($out) = http('GET', $base . 'index.php?route=extension/module/aichat/poll&token=' . rawurlencode($token) . '&after=' . (int)$after);
	return json_decode($out, true);
}

function settingValue($key) {
	$r = q("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'module_aichat_" . $key . "'")->fetch_assoc();
	return $r ? $r['value'] : null;
}

function b64url($raw) {
	return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

$s0 = $store0;
$s1 = $store1;
setting(0, 's_max_msgs_minute', 0);
setting(1, 's_max_msgs_minute', 0);
resetRates();

// ================================================================ boot additions
setting(0, 's_proactive', 1);
setting(0, 's_proactive_delay', 15);
setting(0, 's_proactive_text', array('1' => 'Need help with this one?'));
$b = boot($s0, '', $s0 . 'index.php?route=product/product&product_id=40');
check('boot: features / sound', isset($b['features']) && $b['features'] === array('images' => true, 'rating' => true, 'cart' => true) && $b['sound'] === true, json_encode(array($b['features'], $b['sound'])));
check('boot: proactive on a product page', $b['proactive'] === array('enabled' => true, 'delay' => 15, 'text' => 'Need help with this one?'), json_encode($b['proactive']));
$b = boot($s0, '', $s0);
check('boot: no proactive invite on the home page', $b['proactive']['enabled'] === false);
check('boot: round 3 strings', isset($b['strings']['attach'], $b['strings']['add_cart'], $b['strings']['transcript'], $b['strings']['forget'], $b['strings']['connecting'], $b['strings']['img_caption'], $b['strings']['helpful']));
setting(0, 's_proactive', null);
setting(0, 's_proactive_text', null);
setting(0, 's_feat_cart', 0);
$b = boot($s0);
check('boot: feature switch (cart off)', $b['features']['cart'] === false);
setting(0, 's_feat_cart', null);

// ================================================================ images
$tmp = sys_get_temp_dir() . '/aichat_smoke_' . getmypid();
@mkdir($tmp);
$img = imagecreatetruecolor(320, 200);
imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
imagepng($img, $tmp . '/ok.png');
imagedestroy($img);
file_put_contents($tmp . '/evil.jpg', "<?php echo 'pwned'; ?>\n");
file_put_contents($tmp . '/poly.jpg', "\xFF\xD8\xFF\xE0<?php system('id'); ?>" . str_repeat('A', 100));
file_put_contents($tmp . '/big.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 5 * 1024 * 1024 + 10));
file_put_contents($tmp . '/a.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));

$ba = boot($s0);
$ta = $ba['token'];
$u = upload($s0, $ta, $tmp . '/ok.png', 'ok.png');
check('upload: valid PNG -> {id, thumb}', isset($u['id'], $u['thumb']) && is_int($u['id']) && strpos($u['thumb'], 'route=extension/module/aichat/image') !== false, json_encode($u));
$row = isset($u['id']) ? q("SELECT * FROM oc_aichat_upload WHERE upload_id = " . (int)$u['id'])->fetch_assoc() : null;
$stored = $row ? $storage . '/uploads/' . $row['file'] . '.jpg' : '';
check('upload: re-encoded JPEG stored under DIR_STORAGE, random name', $row && preg_match('#^\d{6}/[a-f0-9]{32}$#', $row['file']) && is_file($stored) && substr(file_get_contents($stored), 0, 3) === "\xFF\xD8\xFF" && $row['mime'] === 'image/jpeg' && (int)$row['conversation_id'] === (int)conv($ta)['conversation_id'], json_encode($row));
check('upload dir has deny-all .htaccess', is_file($storage . '/uploads/.htaccess'));
list($code, $type, $body) = fetch($u['thumb']);
check('image route: thumb to the owner', $code === 200 && $type === 'image/jpeg' && substr($body, 0, 3) === "\xFF\xD8\xFF" && getimagesizefromstring($body)[0] <= 240, $code . ' ' . $type);

$r = upload($s0, $ta, $tmp . '/evil.jpg', 'evil.jpg');
check('upload: PHP file renamed .jpg rejected', isset($r['error']['code']) && $r['error']['code'] === 'image_type', json_encode($r));
$r = upload($s0, $ta, $tmp . '/poly.jpg', 'poly.jpg');
check('upload: JPEG magic + PHP payload rejected (not decodable)', isset($r['error']['code']) && $r['error']['code'] === 'image_bad', json_encode($r));
$r = upload($s0, $ta, $tmp . '/a.gif', 'a.gif');
check('upload: GIF rejected', isset($r['error']['code']) && $r['error']['code'] === 'image_type', json_encode($r));
$r = upload($s0, $ta, $tmp . '/big.jpg', 'big.jpg');
check('upload: over 5 MB rejected', isset($r['error']['code']) && $r['error']['code'] === 'image_too_large', json_encode($r));
$r = upload($s0, 'bogus', $tmp . '/ok.png');
check('upload: invalid token rejected', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token');
check('upload: nothing stored for rejected files', (int)q("SELECT COUNT(*) n FROM oc_aichat_upload WHERE conversation_id = " . (int)conv($ta)['conversation_id'])->fetch_assoc()['n'] === 1);

$bb = boot($s0);
$tb = $bb['token'];
send($s0, $tb, 'tool:list_categories {}', $s0, false);
list($code) = fetch($s0 . 'index.php?route=extension/module/aichat/image&token=' . rawurlencode($tb) . '&id=' . $u['id'] . '&thumb=1');
check('image route: another conversation\'s image id -> 404', $code === 404, $code);
list($code) = fetch($s0 . 'index.php?route=extension/module/aichat/image&token=bogus&id=' . $u['id']);
check('image route: no token -> 404', $code === 404, $code);
$r = send($s0, $tb, 'what is this', $s0, false, array('images' => array($u['id'])));
check('send: another conversation\'s image id refused', isset($r['error']['code']) && $r['error']['code'] === 'image_not_found', json_encode($r));

$r = send($s0, $ta, 'Photo', $s0, false, array('images' => array($u['id'])));
$req = lastModelRequest();
check('send with image: vision request (base64 image block before the text)', isset($r['reply']) && strpos($r['reply'], 'SAW 1 IMAGES') === 0 && strpos($req, '{"type":"image","source":{"type":"base64","media_type":"image/jpeg","data":"/9j/') !== false && strpos($req, '[Attached images: #' . $u['id'] . ']') !== false, json_encode($r) . ' ' . substr($req, 0, 300));
check('send: "Photo" with images = no caption', strpos($r['reply'], 'Photo') === false, $r['reply']);
$r = send($s0, $ta, 'x', $s0, false, array('images' => array($u['id'], $u['id'] + 1000, 1, 2)));
check('send: more than 3 images refused', isset($r['error']['code']) && $r['error']['code'] === 'image_limit', json_encode($r));
$h = boot($s0, $ta);
$withImg = array_values(array_filter($h['history'], function ($m) {
	return !empty($m['images']);
}));
check('history: images [{id, thumb}] + message_id', $withImg && $withImg[0]['images'][0]['id'] === $u['id'] && strpos($withImg[0]['images'][0]['thumb'], 'thumb=1') !== false && isset($h['history'][0]['message_id']), json_encode($withImg));
$assistant = array_values(array_filter($h['history'], function ($m) {
	return $m['role'] === 'assistant';
}));
check('history: assistant items carry rating', $assistant && array_key_exists('rating', $assistant[0]) && $assistant[0]['rating'] === 0, json_encode($assistant[0]));

// ================================================================ rating
$mid = $assistant[0]['message_id'];
$r = post($s0, 'rate', array('token' => $ta, 'message_id' => $mid, 'value' => -1));
check('rate: own assistant message', isset($r['ok']) && $r['ok'] === true && (int)q("SELECT rating FROM oc_aichat_message WHERE message_id = " . (int)$mid)->fetch_assoc()['rating'] === -1, json_encode($r));
$r = post($s0, 'rate', array('token' => $tb, 'message_id' => $mid, 'value' => 1));
check('rate: other conversation\'s message refused', isset($r['error']['code']) && $r['error']['code'] === 'not_found');
$r = post($s0, 'rate', array('token' => $ta, 'message_id' => $withImg[0]['message_id'], 'value' => 1));
check('rate: a user message refused', isset($r['error']['code']) && $r['error']['code'] === 'not_found');
$h = boot($s0, $ta);
foreach ($h['history'] as $m) {
	if ($m['message_id'] === $mid) {
		check('history shows the rating', $m['rating'] === -1);
	}
}

// ================================================================ cart
$jar = tempnam(sys_get_temp_dir(), 'aichat_cart');
$GLOBALS['JAR'] = $jar;
http('GET', $s0 . 'index.php?route=common/home');
$bc = boot($s0);
send($s0, $bc['token'], 'tool:list_categories {}', $s0, false);
list($sp, $resp) = probe($s0, $bc['token'], 'show_products', array('product_ids' => array(40, 30, 49)));
$byId = array();
foreach ($resp['products'] as $p) {
	$byId[$p['product_id']] = $p;
}
check('cards: can_buy / has_options', isset($byId[40], $byId[30], $byId[49]) && $byId[40]['can_buy'] === true && $byId[40]['has_options'] === false && $byId[30]['has_options'] === true && $byId[49]['can_buy'] === false, json_encode($resp['products']));
$r = post($s0, 'cart', array('token' => $bc['token'], 'product_id' => 40, 'quantity' => 2));
check('cart: product without options added', isset($r['ok']) && $r['ok'] === true && $r['cart_count'] === 2 && $r['cart_total'] !== '' && strpos($r['message'], 'iPhone') !== false, json_encode($r));
$r = post($s0, 'cart', array('token' => $bc['token'], 'product_id' => 30, 'quantity' => 1));
check('cart: required option missing -> need_options', isset($r['need_options'][0]) && $r['need_options'][0]['product_option_id'] === 226 && $r['need_options'][0]['required'] === true && $r['need_options'][0]['type'] === 'select' && isset($r['need_options'][0]['values'][0]['product_option_value_id'], $r['need_options'][0]['values'][0]['name']) && !isset($r['ok']), json_encode($r));
$value = isset($r['need_options'][0]['values'][0]['product_option_value_id']) ? $r['need_options'][0]['values'][0]['product_option_value_id'] : 0;
$r = post($s0, 'cart', array('token' => $bc['token'], 'product_id' => 30, 'quantity' => 1, 'option' => array('226' => 999999)));
check('cart: invalid option value -> need_options again', isset($r['need_options']), json_encode($r));
$r = post($s0, 'cart', array('token' => $bc['token'], 'product_id' => 30, 'quantity' => 1, 'option' => array('226' => $value)));
check('cart: with the required option -> added', isset($r['ok']) && $r['cart_count'] === 3, json_encode($r));
list($cartPage) = http('GET', $s0 . 'index.php?route=checkout/cart');
check('cart: in OpenCart\'s own cart (cart page)', strpos($cartPage, 'Canon EOS 5D') !== false && strpos($cartPage, 'iPhone') !== false);
$r = post($s0, 'cart', array('token' => $bc['token'], 'product_id' => 48, 'quantity' => 1));
check('cart: product of the other store refused', isset($r['error']['code']) && $r['error']['code'] === 'cart_product', json_encode($r));
$r = post($s0, 'cart', array('token' => $bc['token'], 'product_id' => 49, 'quantity' => 1));
check('cart: out of stock refused (stock checkout off)', isset($r['error']['code']) && $r['error']['code'] === 'cart_stock', json_encode($r));
$r = post($s0, 'cart', array('token' => $bc['token'], 'product_id' => 30, 'quantity' => 500, 'option' => array('226' => $value)));
check('cart: more than the stock refused without telling the stock', isset($r['error']['code']) && $r['error']['code'] === 'cart_stock' && !preg_match('/\d/', $r['error']['message']), json_encode($r));
$r = post($s0, 'cart', array('token' => 'bogus', 'product_id' => 40));
check('cart: invalid token refused', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token');
$GLOBALS['JAR'] = null;
@unlink($jar);

// ================================================================ transcript
@unlink($mailFile);
$r = post($s0, 'transcript', array('token' => $ta, 'email' => 'not-an-email'));
check('transcript: invalid e-mail', isset($r['error']['code']) && $r['error']['code'] === 'invalid_email');
$r = post($s0, 'transcript', array('token' => $ta, 'email' => 'visitor@example.com'));
$mail = mailText();
check('transcript: mailed to the visitor', isset($r['ok']) && strpos($mail, 'To: visitor@example.com') !== false && strpos($mail, 'SAW 1 IMAGES') !== false && strpos($mail, '[1 image(s)]') !== false, json_encode($r) . "\n" . $mail);
post($s0, 'transcript', array('token' => $ta, 'email' => 'visitor@example.com'));
post($s0, 'transcript', array('token' => $ta, 'email' => 'visitor@example.com'));
$r = post($s0, 'transcript', array('token' => $ta, 'email' => 'visitor@example.com'));
check('transcript: rate-limited (3 per conversation per day)', isset($r['error']['code']) && $r['error']['code'] === 'rate_limited', json_encode($r));
$bn = boot($s0);
$r = post($s0, 'transcript', array('token' => $bn['token'], 'email' => 'visitor@example.com'));
check('transcript: no conversation -> not_found', isset($r['error']['code']) && $r['error']['code'] === 'not_found');

// ================================================================ tools: stock alert, alternatives, comparison, misses, kb
@unlink($mailFile);
$bt = boot($s0);
$tt = $bt['token'];
q("DELETE FROM oc_aichat_request WHERE type = 'stock' AND email = 'notify@example.com'");
list($v) = probe($s0, $tt, 'stock_alert', array('product_id' => 49, 'email' => 'notify@example.com'));
$reqRow = q("SELECT * FROM oc_aichat_request WHERE type = 'stock' ORDER BY request_id DESC LIMIT 1")->fetch_assoc();
check('stock_alert: registered + store mailed', $v && $v['ok'] === true && $reqRow && (int)$reqRow['product_id'] === 49 && $reqRow['email'] === 'notify@example.com' && strpos(mailText(), 'Subject: AI Chat: stock alert') !== false && strpos(mailText(), 'To: shop0@example.com') !== false, json_encode($v) . mailText());
list($v) = probe($s0, $tt, 'stock_alert', array('product_id' => 40, 'email' => 'notify@example.com'));
check('stock_alert: product in stock -> not needed', $v && $v['ok'] === false && !empty($v['in_stock']), json_encode($v));
list($v) = probe($s0, $tt, 'stock_alert', array('product_id' => 48, 'email' => 'notify@example.com'));
check('stock_alert: other store\'s product not found', $v && $v['ok'] === false && empty($v['in_stock']), json_encode($v));
list($v) = probe($s0, $tt, 'stock_alert', array('product_id' => 49, 'email' => 'x@'));
check('stock_alert: invalid e-mail', $v && $v['ok'] === false);
list($v) = probe($s0, $tt, 'stock_alert', array('product_id' => 49, 'email' => 'notify@example.com'));
check('stock_alert: same e-mail + product registered once', $v && $v['ok'] === true && (int)q("SELECT COUNT(*) n FROM oc_aichat_request WHERE type = 'stock' AND email = 'notify@example.com'")->fetch_assoc()['n'] === 1);

list($v) = probe($s0, $tt, 'get_product', array('product_id' => 49));
check('get_product: out of stock -> alternatives note', $v && $v['in_stock'] === 'no' && strpos($v['note'], 'similar_to 49') !== false, json_encode($v));
q("UPDATE oc_product SET quantity = 0 WHERE product_id = 30");
list($v) = probe($s0, $tt, 'search_products', array('similar_to' => 30, 'in_stock_only' => true));
q("UPDATE oc_product SET quantity = 7 WHERE product_id = 30");
$alt = ids($v);
check('search_products similar_to + in_stock_only (most specific category first)', $alt && !in_array(30, $alt) && $alt[0] === 31 && !array_filter($v['products'], function ($p) {
	return $p['in_stock'] !== 'yes';
}), json_encode($v));
list($v) = probe($s0, $tt, 'get_product', array('product_ids' => array(40, 33, 48)));
check('get_product product_ids (comparison): own store only, attributes + description', $v && count($v['products']) === 2 && $v['not_found'] === array(48) && isset($v['products'][0]['description']), json_encode($v));

q("DELETE FROM oc_aichat_search_miss WHERE query = 'zzqx flubber'");
probe($s0, $tt, 'search_products', array('query' => 'zzqx  Flubber'));
probe($s0, $tt, 'search_products', array('query' => 'ZZQX flubber'));
$miss = q("SELECT * FROM oc_aichat_search_miss WHERE query = 'zzqx flubber'")->fetch_assoc();
check('search miss logged (normalised, hits counted)', $miss && (int)$miss['hits'] === 2 && (int)$miss['store_id'] === 0 && (int)$miss['language_id'] === 1, json_encode($miss));
q("DELETE FROM oc_aichat_search_miss WHERE query = 'iphone'");
probe($s0, $tt, 'search_products', array('query' => 'iphone'));
probe($s0, $tt, 'search_products', array('query' => 'iphone', 'category_id' => 18));
check('search hit (and category-scoped miss) not logged', !q("SELECT 1 FROM oc_aichat_search_miss WHERE query = 'iphone'")->num_rows);

q("DELETE FROM oc_aichat_kb WHERE question LIKE 'SMOKE%'");
q("INSERT INTO oc_aichat_kb SET store_id = 0, language_id = 1, question = 'SMOKE Do you ship to Cyprus?', answer = 'KB-CYPRUS-YES: yes, 5-7 days.', status = 1, date_added = NOW()");
q("INSERT INTO oc_aichat_kb SET store_id = -1, language_id = 1, question = 'SMOKE all stores question', answer = 'KB-ALL-STORES', status = 1, date_added = NOW()");
q("INSERT INTO oc_aichat_kb SET store_id = 0, language_id = 1, question = 'SMOKE disabled', answer = 'KB-DISABLED', status = 0, date_added = NOW()");
q("INSERT INTO oc_aichat_kb SET store_id = 1, language_id = 1, question = 'SMOKE store one', answer = 'KB-STORE-ONE', status = 1, date_added = NOW()");
send($s0, $tt, 'tool:list_categories {}', $s0, false);
$req = lastModelRequest();
check('kb: approved answers in the (cached) system prompt', strpos($req, 'KB-CYPRUS-YES') !== false && strpos($req, 'KB-ALL-STORES') !== false && strpos($req, 'KB-DISABLED') === false && strpos($req, 'KB-STORE-ONE') === false, substr($req, 0, 200));
$reqJson = json_decode($req, true);
check('kb: inside the stable block (before the cache breakpoint)', isset($reqJson['system'][0]['cache_control']) && strpos($reqJson['system'][0]['text'], 'KB-CYPRUS-YES') !== false && strpos($reqJson['system'][1]['text'], 'KB-CYPRUS-YES') === false);
list($v) = probe($s0, $tt, 'search_kb', array('query' => 'cyprus'));
check('search_kb', $v && $v['answers'] && strpos($v['answers'][0]['answer'], 'KB-CYPRUS-YES') === 0, json_encode($v));
$b1 = boot($s1);
send($s1, $b1['token'], 'tool:list_categories {}', $s1, false);
$req = lastModelRequest();
check('kb: store 1 gets all-stores + own rows only', strpos($req, 'KB-ALL-STORES') !== false && strpos($req, 'KB-STORE-ONE') !== false && strpos($req, 'KB-CYPRUS-YES') === false);
q("DELETE FROM oc_aichat_kb WHERE question LIKE 'SMOKE%'");

// ================================================================ return request: own vs foreign order
@unlink($mailFile);
resetRates();
$bv = boot($s0);
$tv = $bv['token'];
$uv = upload($s0, $tv, $tmp . '/ok.png');
list($v) = probe($s0, $tv, 'return_request', array('order_id' => '9001', 'product' => 'iPhone', 'reason' => 'damaged', 'details' => 'screen broken'));
$generic = $v ? $v['message'] : '';
check('return_request: order not verified in this chat -> refused', $v && $v['ok'] === false && strpos(json_encode($v), 'iPhone') === false && strpos(json_encode($v), 'Ivan') === false, json_encode($v));
list($v) = probe($s0, $tv, 'return_request', array('order_id' => '777777', 'product' => 'x', 'reason' => 'damaged', 'details' => 'x'));
check('return_request: unknown order -> the same reply', $v && $v['message'] === $generic);
list($v) = probe($s0, $tv, 'return_request', array('order_id' => '9002', 'product' => 'iPhone', 'reason' => 'damaged', 'details' => 'x'));
check('return_request: other store\'s order -> the same reply', $v && $v['message'] === $generic);
list($f) = probe($s0, $tv, 'find_order', array('order_id' => '9001', 'phone' => '0888123456'));
list($v) = probe($s0, $tv, 'return_request', array('order_id' => '9001', 'product' => 'iphone', 'reason' => 'damaged', 'details' => 'screen broken', 'image_ids' => array($uv['id'], $u['id'])));
$reqRow = q("SELECT * FROM oc_aichat_request WHERE type = 'return' ORDER BY request_id DESC LIMIT 1")->fetch_assoc();
$data = $reqRow ? json_decode($reqRow['data'], true) : array();
check('return_request: verified order -> registered', $f['found'] === true && $v && $v['ok'] === true && (int)$reqRow['order_id'] === 9001 && $data['reason'] === 'damaged' && $data['product'] === 'iPhone', json_encode(array($v, $reqRow)));
check('return_request: only own image ids kept', $data['images'] === array($uv['id']), json_encode($data));
check('return_request: store mailed with the photo attached', strpos(mailText(), 'Subject: AI Chat: return request') !== false && substr_count(mailText(), 'Attachment: ') === 1 && strpos(mailText(), 'Reply-To: ivan9001@example.com') !== false, mailText());

// ================================================================ preview / test chat
$secret = settingValue('g_secret');
q("INSERT INTO oc_aichat_preview SET store_id = 0, url = '" . db()->real_escape_string($s0 . 'index.php?route=product/product&product_id=40') . "', data = '" . db()->real_escape_string(json_encode(array('s_instructions' => 'DRAFT-RULE-XYZ'))) . "', date_added = NOW()");
$pid = (int)db()->insert_id;
$ptoken = 'p' . $pid . '.' . b64url(substr(hash_hmac('sha256', 'aichat|preview|' . $pid, $secret, true), 0, 16));
$usageBefore = q("SELECT chats, messages, cost FROM oc_aichat_usage WHERE store_id = 0 AND day = CURDATE()")->fetch_assoc();
$r = send($s0, '', 'tool:list_categories {}', '', false, array('preview' => $ptoken));
$req = lastModelRequest();
$usageAfter = q("SELECT chats, messages, cost FROM oc_aichat_usage WHERE store_id = 0 AND day = CURDATE()")->fetch_assoc();
$pc = q("SELECT * FROM oc_aichat_conversation WHERE is_test = 1 ORDER BY conversation_id DESC LIMIT 1")->fetch_assoc();
check('preview: answered, draft instructions replace the saved ones', isset($r['reply']) && strpos($req, 'DRAFT-RULE-XYZ') !== false && strpos($req, 'Store 0 rule') === false && strpos($req, 'iPhone') !== false, json_encode($r));
check('preview: conversation is_test = 1, page url from the preview', $pc && strpos($pc['last_url'], 'product_id=40') !== false);
check('preview: not counted in chats/messages, cost recorded', $usageAfter['chats'] === $usageBefore['chats'] && $usageAfter['messages'] === $usageBefore['messages'] && (float)$usageAfter['cost'] > (float)$usageBefore['cost'] && (float)$pc['cost'] > 0, json_encode(array($usageBefore, $usageAfter)));
$r = send($s0, '', 'tool:list_categories {}', '', false, array('preview' => $ptoken));
check('preview: same token continues the same test conversation', (int)q("SELECT COUNT(*) n FROM oc_aichat_conversation WHERE is_test = 1 AND conversation_id = " . (int)$pc['conversation_id'])->fetch_assoc()['n'] === 1 && (int)q("SELECT COUNT(*) n FROM oc_aichat_message WHERE role = 'user' AND conversation_id = " . (int)$pc['conversation_id'])->fetch_assoc()['n'] === 2);
$r = send($s1, '', 'hi', '', false, array('preview' => $ptoken));
check('preview: token of store 0 refused by store 1', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token', json_encode($r));
$r = send($s0, '', 'hi', '', false, array('preview' => 'p' . $pid . '.AAAAAAAAAAAAAAAAAAAAAA'));
check('preview: forged signature refused', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token');
q("UPDATE oc_aichat_preview SET date_added = NOW() - INTERVAL 2 DAY WHERE preview_id = " . $pid);
$r = send($s0, '', 'hi', '', false, array('preview' => $ptoken));
check('preview: expired preview refused', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token');

// ================================================================ handover through the fake Telegram
$tgToken = '123456:TEST-token-abcdefghijklmnop';
file_put_contents($storage . '/secret_tg_token.php', "<?php\nreturn " . var_export($tgToken, true) . ";\n");
setting(0, 'g_tg_chat_id', '-100123');
setting(1, 's_tg_chat_id', '-100456');
setting(0, 's_handover_timeout', 60);
setting(0, 'g_tg_canned', array(array('code' => 'hours', 'text' => 'We work 9-18 on weekdays.')));
$open = array();
foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $d) {
	$open[$d] = array('from' => '00:00', 'to' => '24:00');
}
setting(0, 's_work_hours', $open);
$tgSecret = settingValue('g_tg_secret');
check('g_tg_secret generated (webhook secret)', is_string($tgSecret) && preg_match('/^[a-f0-9]{48}$/', $tgSecret), (string)$tgSecret);
tgReset();
resetRates();

$bh = boot($s0);
$th = $bh['token'];
send($s0, $th, 'tool:list_categories {}', $s0, false);
list($v, $resp) = probe($s0, $th, 'request_human', array('reason' => 'wants to talk about a big order'));
$c = conv($th);
$cid = (int)$c['conversation_id'];
$topics = tgCalls('createForumTopic');
$msgs = tgCalls('sendMessage');
check('request_human: waiting + topic "<store> · chat <id>"', $v && $v['status'] === 'waiting' && $resp['mode'] === 'waiting' && $c['mode'] === 'waiting' && (int)$c['tg_topic_id'] === 100 && $c['tg_chat_id'] === '-100123' && $topics && $topics[0]['params']['name'] === 'Your Store · chat ' . $cid, json_encode(array($v, $resp['mode'], $topics)));
check('request_human: transcript + Take/Done buttons in the topic', $msgs && $msgs[0]['params']['message_thread_id'] === '100' && strpos($msgs[0]['params']['text'], 'big order') !== false && strpos($msgs[0]['params']['text'], 'tool:list_categories') !== false && strpos($msgs[0]['params']['reply_markup'], '"take:' . $cid . '"') !== false && strpos($msgs[0]['params']['reply_markup'], '"done:' . $cid . '"') !== false, json_encode($msgs));

$r = send($s0, $th, 'Hello, anybody there?', $s0, false);
$msgs = tgCalls('sendMessage');
$last = end($msgs);
check('waiting: visitor message stored, forwarded, no model call', $r['mode'] === 'waiting' && $r['reply'] === '' && strpos($last['params']['text'], 'Hello, anybody there?') !== false, json_encode($r));
$p = pollc($s0, $th);
check('poll: mode waiting', $p['mode'] === 'waiting' && is_array($p['messages']), json_encode($p));
$after = 0;
foreach ($p['messages'] as $m) {
	$after = max($after, $m['message_id']);
}

$cb = function ($data, $chat = -100123, $thread = 100, $updateId = null) {
	static $n = 7000;
	$n++;
	return array('update_id' => $updateId ? $updateId : $n, 'callback_query' => array('id' => 'cb' . $n, 'from' => array('id' => 55, 'is_bot' => false, 'first_name' => 'Maria', 'last_name' => 'Petrova'), 'data' => $data,
		'message' => array('message_id' => 5000, 'message_thread_id' => $thread, 'chat' => array('id' => $chat, 'type' => 'supergroup', 'is_forum' => true))));
};
$tm = function ($text, $chat = -100123, $thread = 100, $updateId = null) {
	static $n = 8000;
	$n++;
	return array('update_id' => $updateId ? $updateId : $n, 'message' => array('message_id' => $n, 'message_thread_id' => $thread, 'is_topic_message' => true, 'from' => array('id' => 55, 'is_bot' => false, 'first_name' => 'Maria', 'last_name' => 'Petrova'),
		'chat' => array('id' => $chat, 'type' => 'supergroup', 'is_forum' => true), 'date' => time(), 'text' => $text));
};

list($code) = webhook($s0, 'wrong', $tgSecret, $cb('take:' . $cid));
check('webhook: wrong path secret -> 403', $code === 403, $code);
list($code) = webhook($s0, $tgSecret, 'wrong', $cb('take:' . $cid));
check('webhook: wrong secret-token header -> 403', $code === 403, $code);
list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $cb('take:' . $cid, -100999));
check('webhook: other group ignored', $code === 200 && $w['result'] === 'ignored' && conv($th)['mode'] === 'waiting', json_encode($w));
list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $tm('I am in', -100999));
check('webhook: message from another group ignored', $w['result'] === 'ignored' && !pollc($s0, $th, $after)['messages']);
list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $cb('take:' . $cid, -100456, 100));
check('webhook: the other store\'s group cannot take this chat', $w['result'] === 'ignored' && conv($th)['mode'] === 'waiting', json_encode($w));

list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $cb('take:' . $cid, -100123, 100, 99001));
$c = conv($th);
check('Take -> mode human, staff name', $w['result'] === 'take' && $c['mode'] === 'human' && $c['staff_name'] === 'Maria P' && tgCalls('answerCallbackQuery'), json_encode(array($w, $c['mode'], $c['staff_name'])));
list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $cb('take:' . $cid, -100123, 100, 99001));
check('webhook: duplicate update_id processed once', $w['result'] === 'duplicate', json_encode($w));
$p = pollc($s0, $th, $after);
check('poll: "<name> joined" system message', $p['mode'] === 'human' && $p['messages'] && $p['messages'][0]['role'] === 'system' && $p['messages'][0]['content'] === 'Maria P joined the chat', json_encode($p));
$after = $p['messages'][0]['message_id'];

webhook($s0, $tgSecret, $tgSecret, $tm('Здравейте! Тук съм.'));
$p = pollc($s0, $th, $after);
check('staff message reaches poll', $p['messages'] && $p['messages'][0]['role'] === 'staff' && $p['messages'][0]['content'] === 'Здравейте! Тук съм.' && $p['messages'][0]['staff_name'] === 'Maria P', json_encode($p, JSON_UNESCAPED_UNICODE));
$after = end($p['messages'])['message_id'];
list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $tm('/hours'));
$p = pollc($s0, $th, $after);
check('canned /code reply reaches the visitor', $w['result'] === 'canned' && $p['messages'] && $p['messages'][0]['content'] === 'We work 9-18 on weekdays.', json_encode(array($w, $p)));
$after = end($p['messages'])['message_id'];
list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $tm('/nosuch'));
check('unknown command: hint in the topic, nothing to the visitor', $w['result'] === 'unknown_command' && !pollc($s0, $th, $after)['messages']);

$r = send($s0, $th, 'Great, I need 50 pieces', $s0, true);
$events = array();
foreach ($r['events'] as $e) {
	$events[] = $e[0];
}
$msgs = tgCalls('sendMessage');
$last = end($msgs);
check('human: visitor message reaches Telegram (stream: done with mode human)', $events === array('done') && $r['events'][0][1]['mode'] === 'human' && strpos($last['params']['text'], 'Great, I need 50 pieces') !== false && $last['params']['message_thread_id'] === '100', json_encode($r['events']));
$up = upload($s0, $th, $tmp . '/ok.png');
send($s0, $th, 'Photo', $s0, false, array('images' => array($up['id'])));
$photos = tgCalls('sendPhoto');
check('human: visitor photo forwarded with sendPhoto', $photos && $photos[0]['photo_bytes'] > 500, json_encode($photos));

// translation with the light model (cost recorded)
setting(0, 'g_tg_translate', 1);
setting(0, 'g_tg_staff_lang', 'bg');
$costBefore = (float)conv($th)['cost'];
send($s0, $th, 'Can you ship tomorrow?', $s0, false);
$msgs = tgCalls('sendMessage');
$last = end($msgs);
webhook($s0, $tgSecret, $tgSecret, $tm('Да, утре.'));
$p = pollc($s0, $th, $after);
$staff = array_values(array_filter($p['messages'], function ($m) {
	return $m['role'] === 'staff';
}));
$light = lastModelRequest();
check('translation: visitor -> staff language, staff -> visitor language', strpos($last['params']['text'], '[Bulgarian] Can you ship tomorrow?') !== false && $staff && strpos($staff[0]['content'], '[English') === 0, json_encode(array($last['params']['text'], $staff), JSON_UNESCAPED_UNICODE));
check('translation: light model, cost recorded', strpos($light, '"model":"claude-haiku-5-5"') !== false && (float)conv($th)['cost'] > $costBefore, (string)conv($th)['cost']);
setting(0, 'g_tg_translate', null);
$after = end($p['messages'])['message_id'];

list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $cb('done:' . $cid));
$p = pollc($s0, $th, $after);
check('Done -> mode bot, notice to the visitor', $w['result'] === 'done' && $p['mode'] === 'bot' && $p['messages'] && $p['messages'][0]['role'] === 'system', json_encode($p));
$r = send($s0, $th, 'tool:list_categories {}', $s0, false);
$req = lastModelRequest();
check('bot resumes with the full history (staff turns included)', isset($r['reply']) && strpos($r['reply'], 'RESULT') !== false && $r['mode'] === 'bot' && strpos($req, '[Store staff Maria P]: Здравейте! Тук съм.') !== false && strpos($req, 'Great, I need 50 pieces') !== false, json_encode($r) . substr($req, 0, 200));

// /done typed in the topic
probe($s0, $th, 'request_human', array('reason' => 'again'));
check('second request reuses the topic', conv($th)['mode'] === 'waiting' && count(tgCalls('createForumTopic')) === 1);
webhook($s0, $tgSecret, $tgSecret, $tm('Hi again'));
check('writing in the topic takes the chat', conv($th)['mode'] === 'human');
list($code, $w) = webhook($s0, $tgSecret, $tgSecret, $tm('/done'));
check('/done returns the chat to the bot', $w['result'] === 'done' && conv($th)['mode'] === 'bot');

// ---------------------------------------------------------------- timeout -> leave_contact -> mail
@unlink($mailFile);
$bo = boot($s0);
$to = $bo['token'];
send($s0, $to, 'tool:list_categories {}', $s0, false);
probe($s0, $to, 'request_human', array('reason' => 'complaint'));
$co = conv($to);
check('timeout: waiting first', $co['mode'] === 'waiting');
$p = pollc($s0, $to);
check('timeout: not yet', $p['mode'] === 'waiting');
q("UPDATE oc_aichat_conversation SET waiting_since = NOW() - INTERVAL 61 SECOND WHERE conversation_id = " . (int)$co['conversation_id']);
$p = pollc($s0, $to);
$tl = tgCalls('sendMessage');
$lastP = end($p['messages']);
check('timeout inside poll: back to bot, apology asks for a contact', $p['mode'] === 'bot' && $lastP && $lastP['role'] === 'assistant' && strpos($lastP['content'], 'e-mail or phone') !== false && strpos(end($tl)['params']['text'], 'Nobody took the chat') !== false, json_encode($p));
$p2 = pollc($s0, $to);
check('timeout message written once', count($p2['messages']) === count($p['messages']));
list($v) = probe($s0, $to, 'leave_contact', array('email' => 'me@example.com', 'phone' => '0888 111 222', 'note' => 'complaint about delivery'));
$mail = mailText();
$reqRow = q("SELECT * FROM oc_aichat_request WHERE type = 'contact' ORDER BY request_id DESC LIMIT 1")->fetch_assoc();
check('leave_contact: request stored', $v && $v['ok'] === true && $reqRow['email'] === 'me@example.com' && $reqRow['phone'] === '0888 111 222' && (int)$reqRow['conversation_id'] === (int)$co['conversation_id'], json_encode(array($v, $reqRow)));
check('leave_contact: transcript + contact mailed to the store', strpos($mail, 'To: shop0@example.com') !== false && strpos($mail, 'Subject: AI Chat: please contact the customer') !== false && strpos($mail, 'me@example.com') !== false && strpos($mail, 'complaint about delivery') !== false && strpos($mail, 'Visitor: tool:list_categories') !== false && strpos($mail, 'Reply-To: me@example.com') !== false, $mail);
list($v) = probe($s0, $to, 'leave_contact', array('note' => 'x'));
check('leave_contact: needs an e-mail or phone', $v && $v['ok'] === false);

// ---------------------------------------------------------------- outside hours / not configured
$closed = array();
foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $d) {
	$closed[$d] = array('from' => '', 'to' => '');
}
setting(0, 's_work_hours', $closed);
$before = count(tgCalls('createForumTopic'));
$bx = boot($s0);
list($v, $resp) = probe($s0, $bx['token'], 'request_human', array('reason' => 'x'));
check('outside work hours: no handover, collect a contact', $v && $v['status'] === 'outside_hours' && strpos($v['message'], 'leave_contact') !== false && $resp['mode'] === 'bot' && conv($bx['token'])['mode'] === 'bot' && count(tgCalls('createForumTopic')) === $before, json_encode($v));
setting(0, 's_work_hours', $open);
@unlink($storage . '/secret_tg_token.php');
list($v) = probe($s0, $bx['token'], 'request_human', array('reason' => 'x'));
check('Telegram not configured: no handover', $v && $v['status'] === 'unavailable' && conv($bx['token'])['mode'] === 'bot', json_encode($v));
file_put_contents($storage . '/secret_tg_token.php', "<?php\nreturn " . var_export($tgToken, true) . ";\n");
setting(0, 'g_tg_chat_id', '-100666'); // fake API answers "chat not found"
list($v) = probe($s0, $bx['token'], 'request_human', array('reason' => 'x'));
check('Telegram API failure: no handover, no error to the visitor', $v && $v['status'] === 'unavailable' && conv($bx['token'])['mode'] === 'bot', json_encode($v));
setting(0, 'g_tg_chat_id', '-100123');

// ================================================================ forget
$bf = boot($s0);
$tf = $bf['token'];
$uf = upload($s0, $tf, $tmp . '/ok.png');
send($s0, $tf, 'Photo', $s0, false, array('images' => array($uf['id'])));
$cf = conv($tf);
$frow = q("SELECT file FROM oc_aichat_upload WHERE upload_id = " . (int)$uf['id'])->fetch_assoc();
$ffile = $storage . '/uploads/' . $frow['file'] . '.jpg';
$r = post($s0, 'forget', array('token' => $tf));
check('forget: conversation, messages, uploads and files deleted', isset($r['ok']) && !conv($tf) && !q("SELECT 1 FROM oc_aichat_message WHERE conversation_id = " . (int)$cf['conversation_id'])->num_rows && !q("SELECT 1 FROM oc_aichat_upload WHERE conversation_id = " . (int)$cf['conversation_id'])->num_rows && !is_file($ffile) && !is_file(str_replace('.jpg', '_t.jpg', $ffile)), json_encode($r));
$h = boot($s0, $tf);
check('forget: boot afterwards has no history', $h['history'] === array() && $h['returning'] === false);
$r = post($s0, 'forget', array('token' => 'bogus'));
check('forget: invalid token refused', isset($r['error']['code']) && $r['error']['code'] === 'invalid_token');
$r = post($s0, 'forget', array('token' => $th));
check('forget: other conversations untouched', isset($r['ok']) && conv($ta) && !conv($th));

// ================================================================ weekly report + retention (lazy, once)
@unlink($mailFile);
setting(0, 'g_report_weekly', 1);
setting(0, 'g_report_email', 'report@example.com');
setting(0, 'g_report_week', date('o-\WW', strtotime('-7 days')));
setting(0, 'g_cleanup_day', date('Y-m-d', strtotime('-1 day')));
// an upload older than g_image_retention_days (30)
$ou = upload($s0, $ta, $tmp . '/ok.png');
$orow = q("SELECT file FROM oc_aichat_upload WHERE upload_id = " . (int)$ou['id'])->fetch_assoc();
q("UPDATE oc_aichat_upload SET date_added = NOW() - INTERVAL 31 DAY WHERE upload_id = " . (int)$ou['id']);
boot($s0);
usleep(300000);
boot($s1);
boot($s0);
usleep(300000);
$mail = mailText();
check('weekly report sent once', substr_count($mail, 'Subject: AI Chat: weekly report') === 1 && strpos($mail, 'To: report@example.com') !== false && strpos($mail, '== Your Store ==') !== false && strpos($mail, 'Chats:') !== false, $mail);
check('weekly report: week remembered', settingValue('g_report_week') === date('o-\WW') && settingValue('g_cleanup_day') === date('Y-m-d'));
check('retention: old upload + files deleted, recent kept', !q("SELECT 1 FROM oc_aichat_upload WHERE upload_id = " . (int)$ou['id'])->num_rows && !is_file($storage . '/uploads/' . $orow['file'] . '.jpg') && q("SELECT 1 FROM oc_aichat_upload WHERE upload_id = " . (int)$u['id'])->num_rows);
setting(0, 'g_report_weekly', null);
setting(0, 'g_report_email', null);

// ---------------------------------------------------------------- restore
foreach (array('g_tg_chat_id', 'g_tg_canned', 'g_tg_staff_lang') as $k) {
	setting(0, $k, null);
}
foreach (array('s_tg_chat_id', 's_handover_timeout') as $k) {
	setting(0, $k, null);
	setting(1, $k, null);
}
$hours = array();
foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $d) {
	$hours[$d] = in_array($d, array('sat', 'sun'), true) ? array('from' => '', 'to' => '') : array('from' => '09:00', 'to' => '17:00');
}
setting(0, 's_work_hours', $hours);
@unlink($storage . '/secret_tg_token.php');
setting(0, 's_max_msgs_minute', 6);
setting(1, 's_max_msgs_minute', 6);
foreach (glob($tmp . '/*') as $f) {
	@unlink($f);
}
@rmdir($tmp);
resetRates();
