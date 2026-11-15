<?php
/**
 * Fake external services for the smoke test (router script for php -S):
 *
 *   POST /siteverify   Cloudflare Turnstile. secret must be "test-secret".
 *                      response "good" -> success, "outage" -> HTTP 500,
 *                      "cf-internal" -> success:false + internal-error, anything else -> invalid.
 *   POST /v1/track     Speedy REST API v1 track (shape as documented by Speedy, unverified).
 *                      userName/password must be speedy-user / speedy-pass.
 *                      63798828004 delivered, 63798837619 in transit, 63700000000 -> HTTP 500,
 *                      other numbers -> parcel error.
 *
 *   POST /tg/bot<token>/<method>  Telegram Bot API. Token must be "123456:TEST-token-abcdefghijklmnop".
 *                      createForumTopic -> new message_thread_id, sendMessage / sendPhoto -> message_id,
 *                      answerCallbackQuery / editMessageReplyMarkup / setWebhook -> true.
 *                      chat_id "-100666" -> error (simulated outage). Every call is kept in FAKE_TG_STATE.
 *   GET  /tg-log       the recorded calls {next_thread, calls:[{method, params, photo_bytes?}]}
 *   POST /tg-reset     clears them
 *
 * Every request is appended to FAKE_SVC_LOG (env) or the system temp dir.
 */
$log = getenv('FAKE_SVC_LOG') ? getenv('FAKE_SVC_LOG') : sys_get_temp_dir() . '/fake_services.log';
$raw = file_get_contents('php://input');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents($log, date('c') . ' ' . $path . ' ' . $raw . "\n", FILE_APPEND);
header('Content-Type: application/json');

if ($path === '/siteverify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	parse_str($raw, $form);
	$response = isset($form['response']) ? $form['response'] : '';
	if (!isset($form['secret']) || $form['secret'] !== 'test-secret') {
		echo json_encode(array('success' => false, 'error-codes' => array('invalid-input-secret')));
	} elseif ($response === 'outage') {
		http_response_code(500);
		echo 'Internal Server Error';
	} elseif ($response === 'cf-internal') {
		echo json_encode(array('success' => false, 'error-codes' => array('internal-error')));
	} elseif ($response === 'good') {
		echo json_encode(array('success' => true, 'challenge_ts' => date('c'), 'hostname' => '127.0.0.1', 'error-codes' => array()));
	} else {
		echo json_encode(array('success' => false, 'error-codes' => array('invalid-input-response')));
	}
	exit;
}

if ($path === '/v1/track' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$body = json_decode($raw, true);
	if (!is_array($body) || !isset($body['userName'], $body['password']) || $body['userName'] !== 'speedy-user' || $body['password'] !== 'speedy-pass') {
		echo json_encode(array('error' => array('context' => 'user.invalid', 'message' => 'Invalid username or password', 'id' => 'x', 'code' => 1)));
		exit;
	}
	$id = isset($body['parcels'][0]['id']) ? (string)$body['parcels'][0]['id'] : '';
	if ($id === '63700000000') {
		http_response_code(500);
		echo '{}';
		exit;
	}
	if ($id === '63798828004') {
		$ops = array(
			array('dateTime' => '2026-11-10T09:12:00+02:00', 'operationCode' => 1, 'description' => 'Приета в куриерски офис', 'place' => array('name' => 'София')),
			array('dateTime' => '2026-11-11T14:05:00+02:00', 'operationCode' => -14, 'description' => 'Доставена', 'place' => array('name' => 'Пловдив')),
		);
	} elseif ($id === '63798837619') {
		$ops = array(
			array('dateTime' => '2026-11-12T08:00:00+02:00', 'operationCode' => 1, 'description' => 'Приета', 'place' => array('name' => 'София')),
			array('dateTime' => '2026-11-12T18:30:00+02:00', 'operationCode' => 2, 'description' => 'В транзит към Варна'),
		);
	} else {
		echo json_encode(array('parcels' => array(array('parcelId' => $id, 'error' => array('message' => 'Parcel not found')))));
		exit;
	}
	echo json_encode(array('parcels' => array(array('parcelId' => $id, 'operations' => $ops))));
	exit;
}

$tgState = getenv('FAKE_TG_STATE') ? getenv('FAKE_TG_STATE') : sys_get_temp_dir() . '/fake_tg.json';
$tgLoad = function () use ($tgState) {
	$s = is_file($tgState) ? json_decode((string)file_get_contents($tgState), true) : null;
	return is_array($s) ? $s : array('next_thread' => 100, 'next_message' => 5000, 'calls' => array());
};

if ($path === '/tg-log') {
	echo json_encode($tgLoad(), JSON_UNESCAPED_UNICODE);
	exit;
}

if ($path === '/tg-reset') {
	@unlink($tgState);
	echo '{"ok":true}';
	exit;
}

if (preg_match('#^/tg/bot([^/]+)/(\w+)$#', $path, $m)) {
	if ($m[1] !== '123456:TEST-token-abcdefghijklmnop') {
		http_response_code(401);
		echo json_encode(array('ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'));
		exit;
	}
	$params = $_POST;
	if (!$params) {
		parse_str($raw, $params);
	}
	$state = $tgLoad();
	$call = array('method' => $m[2], 'params' => $params);
	if (isset($_FILES['photo'])) {
		$call['photo_bytes'] = (int)$_FILES['photo']['size'];
	}
	if (isset($params['chat_id']) && $params['chat_id'] === '-100666') {
		$state['calls'][] = $call + array('failed' => true);
		file_put_contents($tgState, json_encode($state, JSON_UNESCAPED_UNICODE));
		http_response_code(400);
		echo json_encode(array('ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'));
		exit;
	}
	switch ($m[2]) {
		case 'createForumTopic':
			$result = array('message_thread_id' => $state['next_thread']++, 'name' => isset($params['name']) ? $params['name'] : '', 'icon_color' => 7322096);
			break;
		case 'sendMessage':
		case 'sendPhoto':
			$result = array('message_id' => $state['next_message']++, 'chat' => array('id' => (int)$params['chat_id'], 'type' => 'supergroup'), 'date' => time());
			break;
		default:
			$result = true;
	}
	$state['calls'][] = $call;
	file_put_contents($tgState, json_encode($state, JSON_UNESCAPED_UNICODE));
	echo json_encode(array('ok' => true, 'result' => $result));
	exit;
}

http_response_code(404);
echo '{"error":"not found"}';
