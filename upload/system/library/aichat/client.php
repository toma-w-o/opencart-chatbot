<?php
namespace Aichat;

/**
 * Claude Messages API over cURL: non-streaming and SSE streaming requests,
 * and the client-side tool loop.
 *
 * Endpoint base can be overridden for tests by defining the constant
 * AICHAT_API_BASE (e.g. "http://127.0.0.1:8099") before the call.
 *
 * Request shape (see the claude-api reference):
 *  - headers x-api-key, anthropic-version, content-type
 *  - model, max_tokens, system[] (stable block carries cache_control),
 *    tools[], messages[], output_config.effort, top-level cache_control for
 *    the growing conversation tail, stream
 *  - adaptive thinking (default on current models; thinking param omitted),
 *    thinking blocks are passed back unchanged within the tool loop
 *  - server-side refusal fallback (beta) for models that support it
 */
class Client {
	const API_VERSION = '2026-10-21';
	const DEFAULT_BASE = 'https://api.anthropic.com';
	const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
	/**
	 * Rounds with tools per visitor message; one more round answers without
	 * tools, so at most MAX_TOOL_ROUNDS + 1 API calls per message.
	 */
	const MAX_TOOL_ROUNDS = 4;
	/**
	 * Output budget per API call, adaptive thinking included. A shop reply is
	 * 1-4 sentences plus a few tool calls; 3000 leaves room for thinking at
	 * effort "low" and caps the cost of one call (audit M1).
	 */
	const MAX_TOKENS = 3000;
	/** Tool calls executed per model turn / per visitor message; the rest get an error result (audit M2). */
	const MAX_TOOLS_PER_TURN = 8;
	const MAX_TOOLS_PER_MESSAGE = 16;
	const RETRIES = 2;

	private $apiKey;
	private $model;
	private $effort;
	private $maxTokens;
	private $timeout;
	private $fallbacks;

	/**
	 * @param array $options effort ('low'), max_tokens (16000), timeout (120 s),
	 *                       fallbacks (auto: on for models that support it)
	 */
	public function __construct($apiKey, $model, array $options = array()) {
		$this->apiKey = (string)$apiKey;
		$this->model = trim((string)$model) !== '' ? trim((string)$model) : Settings::DEFAULT_MODEL;
		$this->effort = isset($options['effort']) ? $options['effort'] : 'low';
		$this->maxTokens = isset($options['max_tokens']) ? (int)$options['max_tokens'] : self::MAX_TOKENS;
		$this->timeout = isset($options['timeout']) ? (int)$options['timeout'] : 120;
		$this->fallbacks = isset($options['fallbacks']) ? (bool)$options['fallbacks'] : self::supportsFallbacks($this->model);
	}

	public static function endpoint() {
		$base = defined('AICHAT_API_BASE') ? rtrim(constant('AICHAT_API_BASE'), '/') : self::DEFAULT_BASE;

		return $base . '/v1/messages';
	}

	/** Models that accept the server-side refusal fallback ("default" routing). */
	public static function supportsFallbacks($model) {
		return in_array($model, array('claude-sonnet-5-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-fable-5-2'), true);
	}

	public function getModel() {
		return $this->model;
	}

	/**
	 * Tool loop.
	 *
	 * @param array $system   system blocks
	 * @param array $messages conversation (user/assistant, text)
	 * @param array $tools    tool definitions
	 * @param bool  $stream   SSE streaming
	 * @param array $hooks    callables:
	 *   before()                -> '' to continue or an error code (budget check)
	 *   usage(array $usage)     -> called once per API response
	 *   text(string $delta)     -> streamed text (stream mode only)
	 *   tool(string $name, array $input) -> array(content, is_error)
	 *   round_done()            -> after each round's tools ran (emit product cards)
	 * @return array text, stop_reason, error (null|array(code, status, detail)), tool_calls[], rounds
	 */
	public function run(array $system, array $messages, array $tools, $stream, array $hooks) {
		$full = '';
		$toolCalls = array();
		$stopReason = '';
		$rounds = 0;
		$toolCount = 0;
		$pendingSeparator = false;

		$onText = null;

		if ($stream && isset($hooks['text'])) {
			$textHook = $hooks['text'];
			$onText = function ($delta) use (&$full, &$pendingSeparator, $textHook) {
				if ($delta === '') {
					return;
				}
				if ($pendingSeparator) {
					$pendingSeparator = false;
					if ($full !== '' && !preg_match('/\s$/', $full)) {
						$full .= "\n\n";
						call_user_func($textHook, "\n\n");
					}
				}
				$full .= $delta;
				call_user_func($textHook, $delta);
			};
		}

		for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
			$rounds++;

			if (isset($hooks['before'])) {
				$error = call_user_func($hooks['before']);
				if ($error) {
					return $this->result($full, $stopReason, array('code' => $error, 'status' => 0, 'detail' => ''), $toolCalls, $rounds);
				}
			}

			$body = array(
				'model'         => $this->model,
				'max_tokens'    => $this->maxTokens,
				'system'        => $system,
				'tools'         => $tools,
				'messages'      => $messages,
				'cache_control' => array('type' => 'ephemeral'),
			);

			if ($this->effort) {
				$body['output_config'] = array('effort' => $this->effort);
			}

			if ($round === self::MAX_TOOL_ROUNDS || $toolCount >= self::MAX_TOOLS_PER_MESSAGE) {
				// last round: answer with what we have
				$body['tool_choice'] = array('type' => 'none');
			}

			if ($this->fallbacks) {
				$body['fallbacks'] = 'default';
			}

			$response = $this->request($body, $stream, $onText);

			// every attempt that consumed tokens is priced, retried / failed ones too (audit L5)
			if (isset($hooks['usage'])) {
				foreach ($response['usages'] as $usage) {
					call_user_func($hooks['usage'], $usage);
				}
			}

			if (!$response['ok']) {
				return $this->result($full, $stopReason, $response['error'], $toolCalls, $rounds);
			}

			$message = $response['message'];
			$stopReason = isset($message['stop_reason']) ? (string)$message['stop_reason'] : '';
			$content = isset($message['content']) && is_array($message['content']) ? $message['content'] : array();

			if (!$stream) {
				$text = self::textOf($content);
				if ($text !== '') {
					$full .= ($full !== '' && !preg_match('/\s$/', $full) ? "\n\n" : '') . $text;
				}
			}

			$uses = array();

			foreach ($content as $block) {
				if (isset($block['type']) && $block['type'] === 'tool_use') {
					$uses[] = $block;
				}
			}

			if ($stopReason === 'pause_turn') {
				$messages[] = array('role' => 'assistant', 'content' => self::replayable($content));
				$pendingSeparator = true;
				continue;
			}

			// Never run tools from a truncated or refused turn
			if ($stopReason !== 'tool_use' || !$uses) {
				break;
			}

			$results = array();
			$turnCount = 0;

			foreach ($uses as $use) {
				$name = isset($use['name']) ? (string)$use['name'] : '';
				$id = isset($use['id']) ? (string)$use['id'] : '';

				// every tool_use needs a tool_result, but only the first few are executed (audit M2)
				if ($turnCount >= self::MAX_TOOLS_PER_TURN || $toolCount >= self::MAX_TOOLS_PER_MESSAGE) {
					$toolCalls[] = array('name' => $name, 'input' => isset($use['input']) ? $use['input'] : null, 'is_error' => true, 'skipped' => true);
					$results[] = array('type' => 'tool_result', 'tool_use_id' => $id, 'content' => json_encode(array('error' => 'not executed: too many tool calls (at most ' . self::MAX_TOOLS_PER_TURN . ' per turn and ' . self::MAX_TOOLS_PER_MESSAGE . ' per customer message). Answer with what you have.')), 'is_error' => true);
					continue;
				}

				$turnCount++;
				$toolCount++;

				if (isset($use['_invalid_json'])) {
					$out = array('content' => json_encode(array('INVALID_JSON' => $use['_invalid_json'])), 'is_error' => true);
				} else {
					$input = isset($use['input']) && is_array($use['input']) ? $use['input'] : array();
					$out = isset($hooks['tool']) ? call_user_func($hooks['tool'], $name, $input) : array('content' => '{"error":"no tools"}', 'is_error' => true);
				}

				$toolCalls[] = array('name' => $name, 'input' => isset($use['input']) ? $use['input'] : null, 'is_error' => !empty($out['is_error']));

				$result = array('type' => 'tool_result', 'tool_use_id' => $id, 'content' => (string)$out['content']);
				if (!empty($out['is_error'])) {
					$result['is_error'] = true;
				}
				$results[] = $result;
			}

			if (isset($hooks['round_done'])) {
				call_user_func($hooks['round_done']);
			}

			// All results of one turn go back in a single user message
			$messages[] = array('role' => 'assistant', 'content' => self::replayable($content));
			$messages[] = array('role' => 'user', 'content' => $results);
			$pendingSeparator = true;
		}

		return $this->result($full, $stopReason, null, $toolCalls, $rounds);
	}

	/**
	 * One non-streaming call without tools (translation with the light model).
	 *
	 * @return array text, usage, error (null|array)
	 */
	public function simple($systemText, $userText) {
		$body = array(
			'model'      => $this->model,
			'max_tokens' => $this->maxTokens,
			'system'     => array(array('type' => 'text', 'text' => (string)$systemText, 'cache_control' => array('type' => 'ephemeral'))),
			'messages'   => array(array('role' => 'user', 'content' => (string)$userText)),
		);

		if ($this->effort) {
			$body['output_config'] = array('effort' => $this->effort);
		}

		$response = $this->request($body, false);
		$usage = self::sumUsage($response['usages']);

		if (!$response['ok']) {
			return array('text' => '', 'usage' => $usage, 'error' => $response['error']);
		}

		$content = isset($response['message']['content']) && is_array($response['message']['content']) ? $response['message']['content'] : array();
		$stop = isset($response['message']['stop_reason']) ? $response['message']['stop_reason'] : '';

		return array('text' => $stop === 'refusal' ? '' : trim(self::textOf($content)), 'usage' => $usage, 'error' => null);
	}

	private function result($text, $stopReason, $error, $toolCalls, $rounds) {
		return array('text' => $text, 'stop_reason' => $stopReason, 'error' => $error, 'tool_calls' => $toolCalls, 'rounds' => $rounds);
	}

	public static function textOf(array $content) {
		$text = '';

		foreach ($content as $block) {
			if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
				$text .= $block['text'];
			}
		}

		return $text;
	}

	/** Assistant content to send back unchanged (thinking blocks included); fixes {} that PHP decoded as []. */
	public static function replayable(array $content) {
		$out = array();

		foreach ($content as $block) {
			unset($block['_invalid_json'], $block['_partial_json']);

			if (isset($block['type']) && $block['type'] === 'tool_use') {
				if (!isset($block['input']) || !is_array($block['input']) || !$block['input']) {
					$block['input'] = new \stdClass();
				}
			}

			$out[] = $block;
		}

		return $out;
	}

	// ------------------------------------------------------------------ HTTP

	/**
	 * One API call with retries for overload / rate limit / network errors
	 * (only while nothing has been streamed yet).
	 *
	 * @return array ok, status, message (assembled response), error
	 */
	public function request(array $body, $stream, $onText = null) {
		$attempt = 0;
		$emitted = false;

		$wrapped = null;
		if ($onText) {
			$wrapped = function ($delta) use ($onText, &$emitted) {
				$emitted = true;
				call_user_func($onText, $delta);
			};
		}

		$usages = array();

		while (true) {
			$response = $stream ? $this->streamOnce($body, $wrapped) : $this->createOnce($body);

			if (!empty($response['message']['usage']) && is_array($response['message']['usage'])) {
				$usages[] = $response['message']['usage'];
			}

			if ($response['ok'] || $emitted || $attempt >= self::RETRIES || !$this->retryable($response)) {
				$response['usages'] = $usages;
				return $response;
			}

			$attempt++;
			$wait = isset($response['retry_after']) && $response['retry_after'] > 0 ? min(5, (int)$response['retry_after']) : $attempt * 2 - 1;
			sleep($wait);
		}
	}

	/** Sum of several usage objects (numeric fields added, nested objects ignored). */
	public static function sumUsage(array $usages) {
		$sum = array();

		foreach ($usages as $usage) {
			foreach (is_array($usage) ? $usage : array() as $k => $v) {
				if (is_int($v) || is_float($v)) {
					$sum[$k] = (isset($sum[$k]) ? $sum[$k] : 0) + $v;
				}
			}
		}

		return $sum;
	}

	private function retryable(array $response) {
		$status = (int)$response['status'];

		return $status === 0 || $status === 408 || $status === 409 || $status === 429 || $status >= 500;
	}

	private function headers($stream) {
		$headers = array(
			'content-type: application/json',
			'x-api-key: ' . $this->apiKey,
			'anthropic-version: ' . self::API_VERSION,
		);

		if ($this->fallbacks) {
			$headers[] = 'anthropic-beta: ' . self::FALLBACK_BETA;
		}

		if ($stream) {
			$headers[] = 'accept: text/event-stream';
		}

		return $headers;
	}

	private function encode(array $body) {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

		if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}

		return json_encode($body, $flags);
	}

	private function createOnce(array $body) {
		$retryAfter = 0;
		$ch = curl_init(self::endpoint());

		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $this->encode($body),
			CURLOPT_HTTPHEADER     => $this->headers(false),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
				if (stripos($line, 'retry-after:') === 0) {
					$retryAfter = (int)trim(substr($line, 12));
				}
				return strlen($line);
			},
		));

		$raw = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($raw === false) {
			return $this->fail(0, 'network', $curlError);
		}

		$json = json_decode($raw, true);

		if ($status !== 200 || !is_array($json) || (isset($json['type']) && $json['type'] === 'error')) {
			$response = $this->fail($status, isset($json['error']['type']) ? $json['error']['type'] : 'http_' . $status, isset($json['error']['message']) ? $json['error']['message'] : substr((string)$raw, 0, 300));
			$response['retry_after'] = $retryAfter;
			return $response;
		}

		return array('ok' => true, 'status' => $status, 'message' => $json, 'error' => null);
	}

	private function streamOnce(array $body, $onText) {
		$body['stream'] = true;

		$state = array(
			'buffer'  => '',
			'message' => array('content' => array(), 'usage' => array()),
			'error'   => null,
			'body'    => '',
			'done'    => false,
		);
		$retryAfter = 0;
		$self = $this;

		$ch = curl_init(self::endpoint());

		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $this->encode($body),
			CURLOPT_HTTPHEADER     => $this->headers(true),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
				if (stripos($line, 'retry-after:') === 0) {
					$retryAfter = (int)trim(substr($line, 12));
				}
				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$state, $onText, $self) {
				$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

				if ($status !== 200) {
					if (strlen($state['body']) < 65536) {
						$state['body'] .= $chunk;
					}
					return strlen($chunk);
				}

				$state['buffer'] .= $chunk;
				$self->drainSse($state, $onText);

				return strlen($chunk);
			},
		));

		$ok = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($status === 200 && $state['buffer'] !== '') {
			$state['buffer'] .= "\n\n";
			$this->drainSse($state, $onText);
		}

		if ($status !== 200) {
			$json = json_decode($state['body'], true);
			$response = $this->fail($status, isset($json['error']['type']) ? $json['error']['type'] : ($status ? 'http_' . $status : 'network'), isset($json['error']['message']) ? $json['error']['message'] : ($curlError ? $curlError : substr($state['body'], 0, 300)));
			$response['retry_after'] = $retryAfter;
			return $response;
		}

		if ($state['error']) {
			$response = $this->fail($status, $state['error']['type'], $state['error']['message']);
			$response['message'] = $state['message'];
			// overloaded mid-stream before any output can be retried
			$response['status'] = $state['error']['type'] === 'overloaded_error' ? 529 : 500;
			return $response;
		}

		if ($ok === false || !$state['done']) {
			$response = $this->fail(0, 'network', $curlError ? $curlError : 'stream ended early');
			$response['message'] = $state['message'];
			return $response;
		}

		return array('ok' => true, 'status' => 200, 'message' => $state['message'], 'error' => null);
	}

	/** Parses complete SSE events from $state['buffer'] and assembles the message. Public for tests. */
	public function drainSse(array &$state, $onText) {
		$state['buffer'] = str_replace("\r\n", "\n", $state['buffer']);

		while (($pos = strpos($state['buffer'], "\n\n")) !== false) {
			$raw = substr($state['buffer'], 0, $pos);
			$state['buffer'] = (string)substr($state['buffer'], $pos + 2);

			$event = '';
			$data = '';

			foreach (explode("\n", $raw) as $line) {
				if (strpos($line, 'event:') === 0) {
					$event = trim(substr($line, 6));
				} elseif (strpos($line, 'data:') === 0) {
					$data .= ltrim(substr($line, 5));
				}
			}

			if ($data === '') {
				continue;
			}

			$json = json_decode($data, true);

			if (!is_array($json)) {
				continue;
			}

			$type = isset($json['type']) ? $json['type'] : $event;
			$this->applyEvent($state, $type, $json, $onText);
		}
	}

	private function applyEvent(array &$state, $type, array $json, $onText) {
		$msg = &$state['message'];

		switch ($type) {
			case 'message_start':
				$start = isset($json['message']) && is_array($json['message']) ? $json['message'] : array();
				$content = $msg['content'];
				$msg = array_merge($msg, $start);
				$msg['content'] = $content;
				$msg['usage'] = isset($start['usage']) && is_array($start['usage']) ? $start['usage'] : array();
				break;

			case 'content_block_start':
				$i = (int)$json['index'];
				$block = isset($json['content_block']) && is_array($json['content_block']) ? $json['content_block'] : array();
				if (isset($block['type']) && $block['type'] === 'tool_use') {
					$block['_partial_json'] = '';
				}
				$msg['content'][$i] = $block;
				if (isset($block['type']) && $block['type'] === 'text' && !empty($block['text']) && $onText) {
					call_user_func($onText, $block['text']);
				}
				break;

			case 'content_block_delta':
				$i = (int)$json['index'];
				$delta = isset($json['delta']) ? $json['delta'] : array();
				$dtype = isset($delta['type']) ? $delta['type'] : '';

				if (!isset($msg['content'][$i])) {
					$msg['content'][$i] = array('type' => $dtype === 'text_delta' ? 'text' : 'unknown');
				}

				if ($dtype === 'text_delta') {
					$msg['content'][$i]['text'] = (isset($msg['content'][$i]['text']) ? $msg['content'][$i]['text'] : '') . $delta['text'];
					if ($onText) {
						call_user_func($onText, $delta['text']);
					}
				} elseif ($dtype === 'input_json_delta') {
					$msg['content'][$i]['_partial_json'] = (isset($msg['content'][$i]['_partial_json']) ? $msg['content'][$i]['_partial_json'] : '') . $delta['partial_json'];
				} elseif ($dtype === 'thinking_delta') {
					$msg['content'][$i]['thinking'] = (isset($msg['content'][$i]['thinking']) ? $msg['content'][$i]['thinking'] : '') . $delta['thinking'];
				} elseif ($dtype === 'signature_delta') {
					$msg['content'][$i]['signature'] = $delta['signature'];
				}
				break;

			case 'content_block_stop':
				$i = (int)$json['index'];
				if (isset($msg['content'][$i]['type']) && $msg['content'][$i]['type'] === 'tool_use') {
					$partial = $msg['content'][$i]['_partial_json'];
					unset($msg['content'][$i]['_partial_json']);
					if (trim($partial) === '') {
						$input = isset($msg['content'][$i]['input']) && is_array($msg['content'][$i]['input']) ? $msg['content'][$i]['input'] : array();
					} else {
						$input = json_decode($partial, true);
					}
					if (!is_array($input)) {
						$msg['content'][$i]['input'] = array();
						$msg['content'][$i]['_invalid_json'] = $partial;
					} else {
						$msg['content'][$i]['input'] = $input;
					}
				}
				break;

			case 'message_delta':
				if (isset($json['delta']) && is_array($json['delta'])) {
					foreach ($json['delta'] as $k => $v) {
						$msg[$k] = $v;
					}
				}
				if (isset($json['usage']) && is_array($json['usage'])) {
					foreach ($json['usage'] as $k => $v) {
						if ($v !== null) {
							$msg['usage'][$k] = $v;
						}
					}
				}
				break;

			case 'message_stop':
				ksort($msg['content']);
				$msg['content'] = array_values($msg['content']);
				$state['done'] = true;
				break;

			case 'error':
				$state['error'] = array(
					'type'    => isset($json['error']['type']) ? $json['error']['type'] : 'api_error',
					'message' => isset($json['error']['message']) ? $json['error']['message'] : '',
				);
				break;
		}
	}

	private function fail($status, $code, $detail) {
		return array(
			'ok'      => false,
			'status'  => (int)$status,
			'message' => array('content' => array(), 'usage' => array()),
			'error'   => array('code' => 'api_error', 'status' => (int)$status, 'type' => (string)$code, 'detail' => substr((string)$detail, 0, 500)),
		);
	}
}
