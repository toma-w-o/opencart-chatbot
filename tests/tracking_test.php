<?php
/**
 * Round 4 unit checks without a database: tracking-number extraction on the
 * real comment samples, courier patterns, the handover wait phrase (bg/en/el).
 *
 *   php tests/tracking_test.php <oc dir or repo upload dir>
 */
$oc = isset($argv[1]) ? rtrim($argv[1], '/') : __DIR__ . '/../upload';
error_reporting(E_ALL);
define('DIR_CATALOG', $oc . '/catalog/');
spl_autoload_register(function ($class) use ($oc) {
	$file = $oc . '/system/library/' . str_replace('\\', '/', strtolower($class)) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

$fails = 0;
function check($label, $ok, $detail = '') {
	global $fails;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	$fails += $ok ? 0 : 1;
}

$couriers = array();
foreach (\Aichat\Settings::defaultCouriers() as $c) {
	$c['regex'] = \Aichat\Settings::patternRegex($c['pattern']);
	$couriers[$c['code']] = $c;
}
$ctx = array('order_id' => '9101', 'phone' => '+359 888 123 456', 'statuses' => 'Нова Revolut | Подготвя се с NextLevel | Доставена', 'shipping' => 'Доставка до адрес');
$x = function ($text, $default = '', array $context = null) use ($couriers, $ctx) {
	return \Aichat\Orders::extract($text, $couriers, $default, false, $context === null ? $ctx : $context);
};
$nums = function (array $list) {
	$out = array();
	foreach ($list as $t) {
		$out[] = $t['number'] . '/' . $t['courier'] . '/' . $t['detected_by'];
	}
	return $out;
};

check('seeds: Speedy + NextLevel with patterns', isset($couriers['speedy'], $couriers['nextlevel']) && $couriers['speedy']['regex'] !== '' && $couriers['nextlevel']['hosts'] === array('nextlevel.delivery') && $couriers['nextlevel']['track_url'] === 'https://nextlevel.delivery/bg/track?awb={number}');
check('pattern: Speedy ^6\\d{10}$', preg_match($couriers['speedy']['regex'], '63792919762') === 1 && preg_match($couriers['speedy']['regex'], '1000034300847') === 0);
check('pattern: NextLevel ^1000\\d{9}$', preg_match($couriers['nextlevel']['regex'], '1000034300847') === 1 && preg_match($couriers['nextlevel']['regex'], '63792919762') === 0);
check('pattern: invalid regex -> empty', \Aichat\Settings::patternRegex('([') === '' && \Aichat\Settings::patternRegex('') === '');

$r = $x('https://nextlevel.delivery/track?awb=1000031131956');
check('NextLevel link /track?awb=', $nums($r) === array('1000031131956/nextlevel/link'), json_encode($r));
$r = $x('Изпратена: https://nextlevel.delivery/bg/track?awb=1000031131957.');
check('NextLevel link /bg/track?awb= (trailing dot)', $nums($r) === array('1000031131957/nextlevel/link'), json_encode($r));
$r = $x('Поръчката е изпратена: https://www.speedy.bg/bg/track-shipment?shipmentNumber=63798828004,63798837619');
check('Speedy link with comma-separated numbers', $nums($r) === array('63798828004/speedy/link', '63798837619/speedy/link'), json_encode($r));
$r = $x('Your tracking number is 1000034300847.');
check('plain text "Your tracking number is …" (pattern)', $nums($r) === array('1000034300847/nextlevel/number_format'), json_encode($r));
$r = $x('Your tracking number is 1000034300847. You can track the delivery here https://nextlevel.delivery/bg/track?awb=1000034300847');
check('plain text + link of the same number', count($r) === 2 && $r[0]['number'] === '1000034300847' && $r[1]['number'] === '1000034300847', json_encode($r));
$r = $x('Revolut Payment Gateway - Order Payment Completed. Transaction ID: 6ad8f3f6-667b-a3aa-8f92-16a243889f38');
check('payment gateway comment: nothing', $r === array(), json_encode($r));
$r = $x('Плащане с карта, transaction 63792919762 одобрена.');
check('number in a payment sentence ignored even if it matches a pattern', $r === array(), json_encode($r));
$r = $x('Поръчка 9101 на стойност 120.50 лв., тел. 0888123456, дата 20261022 и 22102026, ще ви се обадим.');
check('order id, phone, amount, dates: nothing', $r === array(), json_encode($r));
$r = $x('Товарителница: 359888123456 / 888123456');
check('customer phone (+359 / short) not a tracking number even after a keyword', $r === array(), json_encode($r));
$r = $x('Изпратена с товарителница 77788899');
check('keyword + no pattern: accepted, courier from the order status (NextLevel)', $nums($r) === array('77788899/nextlevel/status'), json_encode($r));
$r = $x('Пратката е предадена на Спиди, номер 77788899');
check('courier alias named in the same comment (Спиди)', $nums($r) === array('77788899/speedy/comment'), json_encode($r));
$r = $x('Номер на пратка 77788899', 'speedy', array('order_id' => '1', 'phone' => '', 'statuses' => 'Обработва се', 'shipping' => 'Speedy до офис'));
check('courier from the shipping method', $nums($r) === array('77788899/speedy/shipping_method'), json_encode($r));
$r = $x('AWB 77788899', 'speedy', array('order_id' => '1', 'phone' => '', 'statuses' => 'Pending', 'shipping' => 'Flat rate'));
check('courier: store default as the last resort', $nums($r) === array('77788899/speedy/store_default'), json_encode($r));
$r = $x('AWB 77788899', '', array('order_id' => '1', 'phone' => '', 'statuses' => 'Pending', 'shipping' => 'Flat rate'));
check('courier: none (honest unknown)', $nums($r) === array('77788899//none'), json_encode($r));
$r = $x('Ο αριθμός αποστολής σας είναι 77788899');
check('Greek context word', count($r) === 1 && $r[0]['number'] === '77788899', json_encode($r));
$r = $x('Благодарим за поръчката! Ще получите 12345678 точки.');
check('bare number without context or pattern ignored', $r === array(), json_encode($r));
$r = $x('по товарителница 1000034300847 Спиди с товарителници 63792919762,63792921672,63792922698');
check('(extract itself would read the private sample: the caller passes notify = 1 comments only)', count($r) === 4);
$r = $x('Връзка: https://evil.example/track?awb=1000034300847');
check('foreign host link ignored', $r === array(), json_encode($r));

// handover wait phrase
$bg = \Aichat\Handover::stringsFor('bg-bg');
$en = \Aichat\Handover::stringsFor('en-gb');
$el = \Aichat\Handover::stringsFor('el-gr');
check('wait bg 60 s', \Aichat\Handover::waitPhrase($bg, 60) === 'Прехвърлям ви към колега. Това може да отнеме до 1 минута. Моля, изчакайте.', \Aichat\Handover::waitPhrase($bg, 60));
check('wait bg 45 s / 150 s', strpos(\Aichat\Handover::waitPhrase($bg, 45), 'до 45 секунди') !== false && strpos(\Aichat\Handover::waitPhrase($bg, 150), 'до 3 минути') !== false);
check('wait en 60 s / 30 s', \Aichat\Handover::waitPhrase($en, 60) === 'I am transferring you to a colleague. This may take up to 1 minute. Please wait.' && strpos(\Aichat\Handover::waitPhrase($en, 30), 'up to 30 seconds') !== false);
check('wait el 60 s / 120 s', strpos(\Aichat\Handover::waitPhrase($el, 60), 'έως 1 λεπτό') !== false && strpos(\Aichat\Handover::waitPhrase($el, 120), 'έως 2 λεπτά') !== false, \Aichat\Handover::waitPhrase($el, 120));

exit($fails ? 1 : 0);
