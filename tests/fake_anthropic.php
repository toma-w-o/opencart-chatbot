<?php
/**
 * Minimal fake of the Claude Messages API for local tests (no API key needed).
 *
 *   php -S 127.0.0.1:8099 tests/fake_anthropic.php
 *   (OpenCart side: define('AICHAT_API_BASE', 'http://127.0.0.1:8099') via auto_prepend_file)
 *
 * Script per conversation turn, chosen from the request's message history:
 *   1. no tool_result yet      -> thinking block + tool_use search_products(query = visitor text)
 *   2. after search result     -> short text + tool_use show_products(first two ids found)
 *   3. after show_products     -> final text with an own-host link and foreign links,
 *                                 streamed in tiny chunks (tests the link filter across chunks)
 * Tool probe: visitor text "[SAY[text]] tool:<name> <json input>" calls that tool, then answers
 * "<text>\nRESULT: <tool result>" (SAY text first, so links in it go through the filter).
 * Validates what the real API would reject (headers, alternating roles, tool_result ids,
 * thinking blocks replayed unchanged, empty tool input as {}), answering 400 like the API.
 * Every request body is appended to FAKE_LOG (env) or the system temp dir.
 */

$log = getenv('FAKE_LOG') ? getenv('FAKE_LOG') : sys_get_temp_dir() . '/fake_anthropic.log';
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : array();

file_put_contents($log, date('c') . ' ' . $_SERVER['REQUEST_URI'] . ' ' . $raw . "\n", FILE_APPEND);

function fail400($message) {
	http_response_code(400);
	header('Content-Type: application/json');
	echo json_encode(array('type' => 'error', 'error' => array('type' => 'invalid_request_error', 'message' => $message)));
	exit;
}

if ($_SERVER['REQUEST_URI'] !== '/v1/messages' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(404);
	echo '{"type":"error","error":{"type":"not_found_error","message":"not found"}}';
	exit;
}

if (empty($headers['x-api-key']) || empty($headers['anthropic-version'])) {
	http_response_code(401);
	echo '{"type":"error","error":{"type":"authentication_error","message":"missing key or version"}}';
	exit;
}

if (!is_array($body) || empty($body['model']) || empty($body['max_tokens']) || empty($body['messages'])) {
	fail400('model, max_tokens and messages are required');
}

if (getenv('FAKE_OVERLOADED')) {
	http_response_code(529);
	echo '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}';
	exit;
}

if (empty($body['system'][0]['cache_control'])) {
	fail400('expected cache_control on the stable system block');
}

// Roles alternate and start with user
$messages = $body['messages'];
$prev = '';
foreach ($messages as $i => $m) {
	if ($i === 0 && $m['role'] !== 'user') {
		fail400('first message must be user');
	}
	if ($m['role'] === $prev) {
		fail400('roles must alternate at messages.' . $i);
	}
	$prev = $m['role'];
}

if (strpos($raw, '"input":[]') !== false) {
	fail400('tool_use.input must be an object, got []');
}

// tool_result ids must answer the preceding tool_use ids; replayed blocks unchanged
$toolUses = array();
foreach ($messages as $i => $m) {
	if (!is_array($m['content'])) {
		continue;
	}
	foreach ($m['content'] as $block) {
		if ($block['type'] === 'thinking' && (!isset($block['signature']) || $block['signature'] !== 'sig-' . md5($block['thinking']))) {
			fail400('thinking block was modified at messages.' . $i);
		}
		if ($block['type'] === 'tool_use') {
			if (!is_array($block['input']) || (array_values($block['input']) === $block['input'] && $block['input'] !== array())) {
				fail400('tool_use.input must be an object');
			}
			$toolUses[$block['id']] = $block;
		}
		if ($block['type'] === 'tool_result' && !isset($toolUses[$block['tool_use_id']])) {
			fail400('unknown tool_use_id ' . $block['tool_use_id']);
		}
	}
}

// Which step are we at?
$last = end($messages);
$visitor = '';
$images = 0;
foreach ($messages as $m) {
	if ($m['role'] === 'user' && is_string($m['content'])) {
		$visitor = $m['content'];
		$images = 0;
	} elseif ($m['role'] === 'user' && is_array($m['content']) && $m['content'] && $m['content'][0]['type'] !== 'tool_result') {
		// vision turn: image blocks first, then one text block
		$visitor = '';
		$images = 0;
		foreach ($m['content'] as $block) {
			if ($block['type'] === 'image') {
				if (!isset($block['source']['type'], $block['source']['media_type'], $block['source']['data']) || $block['source']['type'] !== 'base64' || base64_decode($block['source']['data'], true) === false) {
					fail400('invalid image block');
				}
				$images++;
			} elseif ($block['type'] === 'text') {
				$visitor .= $block['text'];
			}
		}
	}
}

// translation requests (light model): "[<target>] <text>"
$systemText = isset($body['system'][0]['text']) ? $body['system'][0]['text'] : '';
if (strpos($systemText, 'You translate') === 0) {
	preg_match('/Target language: ([^.]+)\./', $systemText, $tm);
	preg_match('/<text>\n(.*)\n<\/text>/s', $visitor, $xm);
	$out = '[' . (isset($tm[1]) ? $tm[1] : '?') . '] ' . (isset($xm[1]) ? $xm[1] : $visitor);
	header('Content-Type: application/json');
	echo json_encode(array('id' => 'msg_tr', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => array(array('type' => 'text', 'text' => $out)), 'stop_reason' => 'end_turn', 'usage' => array('input_tokens' => 50, 'output_tokens' => 20)));
	exit;
}

$lastToolResult = null;
$lastToolName = null;
if (is_array($last['content'])) {
	foreach ($last['content'] as $block) {
		if ($block['type'] === 'tool_result') {
			$lastToolResult = $block;
			$lastToolName = $toolUses[$block['tool_use_id']]['name'];
		}
	}
}

$id = 'msg_' . substr(md5(uniqid('', true)), 0, 12);

// ---- security probes (audit M1/M2/L5); only for visitor texts that start with these prefixes
// "many:<n> <tool> <json>"  one turn with n tool_use blocks, then "MANY executed=.. skipped=.."
// "loop:<n> <tool> <json>"  n tool_use blocks every turn until tool_choice none, then "LOOP calls=.. results=.. skipped=.."
// "long:<n>"                 n output tokens wanted: usage.output_tokens = min(n, max_tokens), "LONG max_tokens=.."
// "midfail"                  stream: the first attempt dies after message_start (usage 777 in), the retry answers "RECOVERED"
$probeTail = preg_match('/(?:^|\n)(many|loop):(\d+) (\w+) (\{.*\})\s*$/s', $visitor, $sp) ? $sp : null;
$turnResults = 0;
$turnSkipped = 0;
$turnCalls = 0;
for ($k = count($messages) - 1; $k >= 0; $k--) {
	$m = $messages[$k];
	if ($m['role'] === 'user' && !is_array($m['content'])) {
		break;
	}
	if ($m['role'] === 'user' && is_array($m['content']) && $m['content'] && $m['content'][0]['type'] !== 'tool_result') {
		break;
	}
	foreach (is_array($m['content']) ? $m['content'] : array() as $block) {
		if ($block['type'] === 'tool_result') {
			$turnResults++;
			if (strpos((string)$block['content'], 'not executed') !== false) {
				$turnSkipped++;
			}
		} elseif ($block['type'] === 'tool_use') {
			$turnCalls++;
		}
	}
}
$forcedEnd = isset($body['tool_choice']['type']) && $body['tool_choice']['type'] === 'none';
$special = null;
if ($probeTail) {
	$n = max(1, min(500, (int)$probeTail[2]));
	$again = $probeTail[1] === 'loop' || $lastToolResult === null;
	if ($forcedEnd || !$again) {
		$special = array(array(array('type' => 'text', 'text' => strtoupper($probeTail[1]) . ' calls=' . $turnCalls . ' results=' . $turnResults . ' executed=' . ($turnResults - $turnSkipped) . ' skipped=' . $turnSkipped . ' max_tokens=' . $body['max_tokens'])), 'end_turn', 40);
	} else {
		$input = json_decode($probeTail[4], true);
		$blocks = array();
		for ($k = 0; $k < $n; $k++) {
			$blocks[] = array('type' => 'tool_use', 'id' => 'toolu_' . substr(md5($id . $k), 0, 10), 'name' => $probeTail[3], 'input' => $input ? $input : new stdClass());
		}
		$special = array($blocks, 'tool_use', 20 * $n);
	}
} elseif (preg_match('/(?:^|\n)long:(\d+)\s*$/', $visitor, $lp) && $lastToolResult === null) {
	$want = (int)$lp[1];
	$out = min($want, (int)$body['max_tokens']);
	$special = array(array(array('type' => 'text', 'text' => 'LONG max_tokens=' . $body['max_tokens'] . ' output=' . $out)), $want > $out ? 'max_tokens' : 'end_turn', $out);
} elseif (preg_match('/(?:^|\n)midfail\s*$/', $visitor) && $lastToolResult === null) {
	$stateFile = sys_get_temp_dir() . '/fake_midfail_' . md5(json_encode($messages));
	if (!is_file($stateFile)) {
		file_put_contents($stateFile, '1');
		if (!empty($body['stream'])) {
			header('Content-Type: text/event-stream');
			echo "event: message_start\ndata: " . json_encode(array('type' => 'message_start', 'message' => array('id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => array(), 'stop_reason' => null, 'usage' => array('input_tokens' => 777, 'output_tokens' => 1)))) . "\n\n";
			echo "event: error\ndata: " . json_encode(array('type' => 'error', 'error' => array('type' => 'overloaded_error', 'message' => 'Overloaded'))) . "\n\n";
			exit;
		}
		http_response_code(529);
		echo '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}';
		exit;
	}
	@unlink($stateFile);
	$special = array(array(array('type' => 'text', 'text' => 'RECOVERED')), 'end_turn', 5);
}

// Direct tool probe: visitor text "tool:<name> <json input>" -> call it, then echo the result
if ($special !== null) {
	$content = $special[0];
	$stop = $special[1];
} elseif (preg_match('/tool:(\w+) (\{.*\})\s*$/s', $visitor, $probe)) {
	$say = preg_match('/^SAY\[(.*?)\]\s/s', $visitor, $sm) ? $sm[1] . "\n" : '';
	if ($lastToolResult === null) {
		$input = json_decode($probe[2], true);
		$content = array(array('type' => 'tool_use', 'id' => 'toolu_' . substr(md5($id), 0, 10), 'name' => $probe[1], 'input' => $input ? $input : new stdClass()));
		$stop = 'tool_use';
	} else {
		$content = array(array('type' => 'text', 'text' => $say . 'RESULT' . (!empty($lastToolResult['is_error']) ? '(error)' : '') . ': ' . $lastToolResult['content']));
		$stop = 'end_turn';
	}
} elseif ($lastToolResult === null && $images > 0) {
	$content = array(array('type' => 'text', 'text' => 'SAW ' . $images . ' IMAGES: ' . trim($visitor)));
	$stop = 'end_turn';
} elseif ($lastToolResult === null) {
	$thinking = 'The visitor wants products. Search first.';
	$content = array(
		array('type' => 'thinking', 'thinking' => $thinking, 'signature' => 'sig-' . md5($thinking)),
		array('type' => 'tool_use', 'id' => 'toolu_' . substr(md5($id), 0, 10), 'name' => 'search_products', 'input' => array('query' => trim(preg_replace('/^.*?:/', '', $visitor)), 'limit' => 5)),
	);
	$stop = 'tool_use';
} elseif ($lastToolName === 'search_products') {
	$data = json_decode($lastToolResult['content'], true);
	$ids = array();
	foreach (isset($data['products']) ? $data['products'] : array() as $p) {
		$ids[] = $p['product_id'];
	}
	$ids = array_slice($ids, 0, 2);
	$content = array(
		array('type' => 'text', 'text' => 'I found ' . count($ids) . ' products.'),
		array('type' => 'tool_use', 'id' => 'toolu_' . substr(md5($id), 0, 10), 'name' => 'show_products', 'input' => array('product_ids' => $ids)),
	);
	$stop = 'tool_use';
} else {
	$content = array(
		array('type' => 'text', 'text' => 'Here they are! More on [our page](' . (getenv('FAKE_OWN') ? getenv('FAKE_OWN') : 'http://127.0.0.1:8080/') . 'index.php?route=information/information&information_id=4). Not on [Emag](https://www.emag.bg/x) or https://ozone.bg/y or amazon.com/z, just here.'),
	);
	$stop = 'end_turn';
}

$usage = array('input_tokens' => 120, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 0, 'output_tokens' => $special !== null ? (int)$special[2] : 40);
if (count($messages) > 1) {
	$usage['cache_creation_input_tokens'] = 100;
	$usage['cache_read_input_tokens'] = 2000;
}

if (empty($body['stream'])) {
	header('Content-Type: application/json');
	echo json_encode(array('id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => $content, 'stop_reason' => $stop, 'stop_details' => null, 'usage' => $usage));
	exit;
}

// ---- SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

function ev($name, $data) {
	echo "event: " . $name . "\ndata: " . json_encode($data) . "\n\n";
	@ob_flush();
	flush();
}

$startUsage = $usage;
$startUsage['output_tokens'] = 1;
ev('message_start', array('type' => 'message_start', 'message' => array('id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => array(), 'stop_reason' => null, 'usage' => $startUsage)));
ev('ping', array('type' => 'ping'));

foreach ($content as $i => $block) {
	if ($block['type'] === 'text') {
		ev('content_block_start', array('type' => 'content_block_start', 'index' => $i, 'content_block' => array('type' => 'text', 'text' => '')));
		foreach (str_split($block['text'], 3) as $piece) {
			ev('content_block_delta', array('type' => 'content_block_delta', 'index' => $i, 'delta' => array('type' => 'text_delta', 'text' => $piece)));
		}
	} elseif ($block['type'] === 'thinking') {
		ev('content_block_start', array('type' => 'content_block_start', 'index' => $i, 'content_block' => array('type' => 'thinking', 'thinking' => '', 'signature' => '')));
		ev('content_block_delta', array('type' => 'content_block_delta', 'index' => $i, 'delta' => array('type' => 'thinking_delta', 'thinking' => $block['thinking'])));
		ev('content_block_delta', array('type' => 'content_block_delta', 'index' => $i, 'delta' => array('type' => 'signature_delta', 'signature' => $block['signature'])));
	} else {
		ev('content_block_start', array('type' => 'content_block_start', 'index' => $i, 'content_block' => array('type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => new stdClass())));
		$json = json_encode($block['input']);
		foreach (str_split($json, 5) as $piece) {
			ev('content_block_delta', array('type' => 'content_block_delta', 'index' => $i, 'delta' => array('type' => 'input_json_delta', 'partial_json' => $piece)));
		}
	}
	ev('content_block_stop', array('type' => 'content_block_stop', 'index' => $i));
}

ev('message_delta', array('type' => 'message_delta', 'delta' => array('stop_reason' => $stop, 'stop_sequence' => null), 'usage' => array('output_tokens' => $usage['output_tokens'])));
ev('message_stop', array('type' => 'message_stop'));
