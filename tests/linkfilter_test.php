<?php
// php tests/linkfilter_test.php  -> exits 1 on failure
require __DIR__ . '/../upload/system/library/aichat/linkfilter.php';

$fails = 0;
function check($name, $got, $want) {
	global $fails;
	if ($got !== $want) {
		$fails++;
		echo "FAIL $name\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
	} else {
		echo "ok   $name\n";
	}
}

$cases = array(
	array('own md link', 'See [our shop](https://magicone.bg/igrachki) now.', 'See [our shop](https://magicone.bg/igrachki) now.'),
	array('www own', 'Visit www.magicone.bg/contact today', 'Visit www.magicone.bg/contact today'),
	array('foreign md link', 'Try [Emag](https://www.emag.bg/x) instead.', 'Try Emag instead.'),
	array('foreign bare', 'Go to https://emag.bg/abc, ok?', 'Go to , ok?'),
	array('foreign www', 'or www.ozone.bg.', 'or .'),
	array('foreign bare domain', 'Buy at ozone.bg or amazon.com/item?x=1!', 'Buy at  or !'),
	array('email kept', 'Mail info@magicone.bg or shop@gmail.com', 'Mail info@magicone.bg or shop@gmail.com'),
	array('relative kept', 'Open [cart](index.php?route=checkout/cart).', 'Open [cart](index.php?route=checkout/cart).'),
	array('protocol relative foreign', 'x //evil.com/a y', 'x  y'),
	array('md label is foreign url', '[https://evil.com](https://evil.com)', ''),
	array('javascript scheme', '[click](javascript:alert(1))', '[click](javascript:alert(1))'),
	array('numbers untouched', 'Price 12.50 lv, size 3.5kg, v1.2', 'Price 12.50 lv, size 3.5kg, v1.2'),
	array('subdomain foreign', 'see shop.evil.co for more', 'see  for more'),
	array('other store same db not allowed', 'also fitball.bg', 'also '),
);

foreach ($cases as $c) {
	$f = new \Aichat\LinkFilter(array('https://magicone.bg/'));
	check($c[0], $f->filter($c[1]), $c[2]);
}

// javascript: inside markdown is not an allowed URL -> label only
$f = new \Aichat\LinkFilter(array('magicone.bg'));
check('js md', $f->filter('[click](javascript:void)'), 'click');

// Streaming: every split position must give the same result as filtering the whole text
$texts = array(
	'Hello! See [this toy](https://emag.bg/toy) or https://magicone.bg/p/1 and www.ozone.bg. Bye [ok](https://magicone.bg/a b)',
	'Links: [a b c](https://evil.com/x) [d](https://magicone.bg/d) end https://evil.com',
	"Line1\n[label with spaces](https://www.magicone.bg/x)\nshop.evil.co/path?q=1 done",
);
foreach ($texts as $n => $text) {
	$want = (new \Aichat\LinkFilter(array('magicone.bg')))->filter($text);
	$bad = 0;
	for ($size = 1; $size <= 12; $size++) {
		$f = new \Aichat\LinkFilter(array('magicone.bg'));
		$out = '';
		foreach (str_split($text, $size) as $chunk) {
			$piece = $f->push($chunk);
			if (preg_match('~evil|emag|ozone~', $piece)) {
				$bad++;
			}
			$out .= $piece;
		}
		$out .= $f->flush();
		if ($out !== $want || preg_match('~evil|emag|ozone~', $out)) {
			$bad++;
			echo "  chunk $size: " . var_export($out, true) . "\n";
		}
	}
	check("stream text $n", $bad, 0);
}

exit($fails ? 1 : 0);
