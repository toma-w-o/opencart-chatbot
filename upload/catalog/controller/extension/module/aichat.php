<?php
/**
 * AI Chat - storefront endpoints.
 *
 *   GET  index.php?route=extension/module/aichat/boot&url=<page>&token=<token?>
 *   POST index.php?route=extension/module/aichat/send   JSON {token, message, url, stream, turnstile?}
 *   event catalog/view/common/footer/after -> footer()  (injects the widget)
 *
 * store_id, language_id and customer_id always come from OpenCart's config /
 * session, never from the browser.
 */
class ControllerExtensionModuleAichat extends Controller {
	const HISTORY_FOR_MODEL = 20;
	const HISTORY_FOR_WIDGET = 30;

	private $settingsObj = null;
	private $streaming = false;

	// ================================================================ boot

	public function boot() {
		$settings = $this->settings();
		$storeId = (int)$this->config->get('config_store_id');
		$languageId = (int)$this->config->get('config_language_id');
		$store = $settings->getStore($storeId);

		if (!$store['s_status'] || !$settings->hasApiKey()) {
			$this->json(array('enabled' => false));
			return;
		}

		$this->load->language('extension/module/aichat');
		$this->load->model('extension/module/aichat');

		$guard = new \Aichat\Guard($this->registry, $settings);
		$token = isset($this->request->get['token']) ? (string)$this->request->get['token'] : '';
		$publicId = $guard->parseToken($token, $storeId);
		$conversation = $publicId ? $this->model_extension_module_aichat->getConversation($publicId, $storeId) : null;

		// Never show a customer's conversation to someone else on the same browser
		if ($conversation && (int)$conversation['customer_id'] && (int)$conversation['customer_id'] !== (int)$this->customer->getId()) {
			$conversation = null;
			$publicId = false;
		}

		if (!$publicId) {
			// The conversation row is created lazily on the first message
			$publicId = \Aichat\Guard::newPublicId();
		}

		$botName = $this->botName($store);
		$history = array();

		if ($conversation) {
			$tools = new \Aichat\Tools($this->registry, $storeId, $languageId);

			foreach ($this->model_extension_module_aichat->getMessages($conversation['conversation_id'], self::HISTORY_FOR_WIDGET) as $message) {
				$item = array('role' => $message['role'] === 'user' ? 'user' : ($message['role'] === 'staff' ? 'staff' : 'assistant'), 'content' => $message['content']);
				$groups = $this->cardGroups($tools, $message['meta']);
				if ($groups) {
					$item['products'] = $this->flatten($groups);
					$item['product_groups'] = $groups;
				}
				$history[] = $item;
			}
		}

		$greeting = '';
		if (is_array($store['s_greeting']) && !empty($store['s_greeting'][$languageId])) {
			$greeting = trim(html_entity_decode($store['s_greeting'][$languageId], ENT_QUOTES, 'UTF-8'));
		}
		if ($greeting === '') {
			$greeting = sprintf($this->language->get('widget_greeting'), $botName);
		}

		$turnstile = trim((string)$settings->getGlobal('g_turnstile_site'));

		$this->json(array(
			'enabled'        => true,
			'bot_name'       => $botName,
			'avatar'         => $store['s_avatar'] !== '' ? $store['s_avatar'] : 'default',
			'greeting'       => $greeting,
			'emoji'          => (int)$store['s_emoji'],
			'strings'        => $this->strings($botName),
			'token'          => $guard->makeToken($publicId, $storeId),
			'history'        => $history,
			'mode'           => $conversation ? $conversation['mode'] : 'bot',
			'turnstile_site' => $turnstile !== '' ? $turnstile : null,
			'max_len'        => (int)$store['s_max_len'],
		));
	}

	// ================================================================ send

	public function send() {
		$input = json_decode((string)file_get_contents('php://input'), true);
		$input = is_array($input) ? $input : array();
		$this->streaming = !empty($input['stream']) && (int)$input['stream'] === 1;

		$this->load->language('extension/module/aichat');

		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->fail('method');
			return;
		}

		$settings = $this->settings();
		$storeId = (int)$this->config->get('config_store_id');
		$languageId = (int)$this->config->get('config_language_id');
		$customerId = (int)$this->customer->getId();
		$store = $settings->getStore($storeId);

		if (!$store['s_status'] || !$settings->hasApiKey()) {
			$this->fail('disabled');
			return;
		}

		$this->load->model('extension/module/aichat');
		$model = $this->model_extension_module_aichat;
		$guard = new \Aichat\Guard($this->registry, $settings);

		// --- token -> conversation (bound to this store)
		$publicId = $guard->parseToken(isset($input['token']) ? $input['token'] : '', $storeId);

		if (!$publicId) {
			$this->fail('invalid_token');
			return;
		}

		$conversation = $model->getConversation($publicId, $storeId);

		if ($conversation && (int)$conversation['customer_id'] && (int)$conversation['customer_id'] !== $customerId) {
			$this->fail('invalid_token');
			return;
		}

		// --- message
		$message = isset($input['message']) && is_string($input['message']) ? $this->cleanText($input['message']) : '';
		$url = isset($input['url']) && is_string($input['url']) ? substr($input['url'], 0, 2000) : '';

		if ($message === '') {
			$this->fail('empty');
			return;
		}

		if (\Aichat\Tools::len($message) > (int)$store['s_max_len']) {
			$this->fail('too_long', (int)$store['s_max_len']);
			return;
		}

		// --- stage 2 hook: Cloudflare Turnstile
		$human = $this->verifyHuman($conversation, isset($input['turnstile']) ? (string)$input['turnstile'] : '', $settings);

		if ($human !== true) {
			$this->fail('turnstile');
			return;
		}

		// --- rate limits (per conversation, per IP, per chat, new chats per day)
		$ip = isset($this->request->server['REMOTE_ADDR']) ? (string)$this->request->server['REMOTE_ADDR'] : '';
		$limit = $guard->checkMessage($store, $conversation, $publicId, $ip);

		if ($limit !== '') {
			$this->fail($limit, $this->contactsLine($store));
			return;
		}

		if ($this->streaming) {
			$this->sseStart();
		}

		// --- persist
		$isNew = !$conversation;

		if ($isNew) {
			$conversationId = $model->addConversation(array(
				'store_id'    => $storeId,
				'language_id' => $languageId,
				'customer_id' => $customerId,
				'public_id'   => $publicId,
				'ip'          => $ip,
				'user_agent'  => isset($this->request->server['HTTP_USER_AGENT']) ? $this->request->server['HTTP_USER_AGENT'] : '',
				'url'         => $url,
			));
			$guard->countChat($storeId);
			$conversation = $model->getConversation($publicId, $storeId);
		} else {
			$conversationId = (int)$conversation['conversation_id'];
		}

		$tools = new \Aichat\Tools($this->registry, $storeId, $languageId);
		$pageContext = new \Aichat\PageContext($this->registry, $tools);
		$page = $pageContext->resolve($url);

		$history = $model->getMessages($conversationId, self::HISTORY_FOR_MODEL);
		$model->addMessage($conversationId, 'user', $message, array('page' => $this->pageMeta($page), 'url' => $url));
		$model->touchConversation($conversationId, $url, $languageId, $customerId);
		$guard->countMessage($storeId);

		// --- not in bot mode: stage 5 (human handover) takes over here
		$mode = $conversation['mode'] === 'closed' ? 'bot' : $conversation['mode'];

		if ($conversation['mode'] === 'closed') {
			$model->setMode($conversationId, 'bot');
		}

		if ($mode !== 'bot') {
			$this->finish(array('reply' => '', 'products' => array(), 'product_groups' => array(), 'mode' => $mode, 'message_id' => 0));
			return;
		}

		// --- budget (checked again before every API call in the loop)
		if ($guard->checkBudget($storeId) !== '') {
			$this->fail('budget', $this->contactsLine($store));
			return;
		}

		// --- prompt + conversation
		$botName = $this->botName($store);
		$prompt = new \Aichat\Prompt($this->registry, $settings, $tools);
		$system = $prompt->system($store, $botName, $this->languageName($languageId), $page, $this->customer->isLogged() ? (string)$this->customer->getFirstName() : '');
		$messages = $this->modelMessages($history, $message);

		$client = new \Aichat\Client($settings->getApiKey(), $settings->getGlobal('g_model'));
		$filter = new \Aichat\LinkFilter(\Aichat\PageContext::storeHosts($this->config));

		$self = $this;
		$streaming = $this->streaming;
		$reply = '';
		$groups = array();
		$totals = array('in' => 0, 'out' => 0, 'cost' => 0.0);

		$hooks = array(
			'before' => function () use ($guard, $storeId) {
				return $guard->checkBudget($storeId);
			},
			'usage' => function ($usage) use ($guard, $storeId, $conversationId, &$totals) {
				list($in, $out, $cost) = $guard->recordUsage($storeId, $conversationId, $usage);
				$totals['in'] += $in;
				$totals['out'] += $out;
				$totals['cost'] += $cost;
			},
			'text' => function ($delta) use ($self, $filter, &$reply) {
				$safe = $filter->push($delta);
				if ($safe !== '') {
					$reply .= $safe;
					$self->sse('delta', array('text' => $safe));
				}
			},
			'tool' => function ($name, $toolInput) use ($tools) {
				return $tools->execute($name, $toolInput);
			},
			'round_done' => function () use ($self, $tools, $streaming, &$groups) {
				foreach ($tools->takeCards() as $group) {
					$groups[] = $group;
					if ($streaming) {
						$self->sse('products', $group);
					}
				}
			},
		);

		$result = $client->run($system, $messages, \Aichat\Tools::definitions($streaming), $streaming, $hooks);

		if ($streaming) {
			$rest = $filter->flush();
			if ($rest !== '') {
				$reply .= $rest;
				$this->sse('delta', array('text' => $rest));
			}
		} else {
			$reply = $filter->filter($result['text']);
		}

		$reply = trim($reply);

		if ($result['stop_reason'] === 'refusal' && $reply === '') {
			$reply = $this->language->get('error_refusal');
			if ($streaming) {
				$this->sse('delta', array('text' => $reply));
			}
		}

		$meta = array(
			'model'       => $client->getModel(),
			'stop_reason' => $result['stop_reason'],
			'rounds'      => $result['rounds'],
			'tools'       => $result['tool_calls'],
			'products'    => $this->groupsMeta($groups),
		);

		if ($result['error']) {
			$meta['error'] = $result['error'];
		}

		$messageId = 0;

		if ($reply !== '' || $groups || $totals['in'] || $totals['out']) {
			$messageId = $model->addMessage($conversationId, 'assistant', $reply, $meta, $totals['in'], $totals['out'], $totals['cost']);
			$model->bumpMessageCount($conversationId);
		}

		if ($result['error'] && $reply === '') {
			$code = $result['error']['code'] === 'budget' ? 'budget' : 'api_error';
			if ($result['error']['code'] === 'api_error') {
				$this->log->write('AI Chat API error: ' . (isset($result['error']['type']) ? $result['error']['type'] : '') . ' ' . $result['error']['status'] . ' ' . $result['error']['detail']);
			}
			$this->fail($code, $this->contactsLine($store));
			return;
		}

		if ($result['error'] && $streaming) {
			// partial answer already shown; tell the widget it was cut short
			$this->sse('error', $this->errorBody('api_error'));
		}

		$this->finish(array(
			'reply'          => $reply,
			'products'       => $this->flatten($groups),
			'product_groups' => $groups,
			'mode'           => 'bot',
			'message_id'     => $messageId,
		));
	}

	/**
	 * Stage 2: verify Cloudflare Turnstile here (g_turnstile_secret, siteverify),
	 * e.g. once per conversation or after suspicious activity. Return true to
	 * allow, anything else to reject with error code `turnstile`.
	 */
	protected function verifyHuman($conversation, $turnstileToken, $settings) {
		return true;
	}

	// ================================================================ footer event

	/**
	 * Event `catalog/view/common/footer/after`: appends the widget tags.
	 * Defaults let OpenCart's router call it without arguments (it does nothing then).
	 */
	public function footer(&$route = '', &$args = array(), &$output = '') {
		if (!is_string($output) || $output === '') {
			return;
		}

		$settings = $this->settings();
		$storeId = (int)$this->config->get('config_store_id');

		if (!$settings->getStore($storeId, 's_status') || !$settings->hasApiKey()) {
			return;
		}

		if (strpos($output, 'aichat/widget.js') !== false) {
			return;
		}

		$secure = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off';
		$base = $secure && $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$base = rtrim((string)$base, '/') . '/';

		$asset = 'catalog/view/javascript/aichat/';
		$version = '';
		if (is_file(DIR_APPLICATION . 'view/javascript/aichat/widget.js')) {
			$version = '?v=' . filemtime(DIR_APPLICATION . 'view/javascript/aichat/widget.js');
		}

		$endpoint = $base . 'index.php?route=extension/module/aichat/';
		$config = json_encode(array('base' => $endpoint), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
		$widgetConfig = json_encode(array('endpoint' => $endpoint), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

		$tags = "\n" . '<link rel="stylesheet" href="' . htmlspecialchars($base . $asset . 'widget.css' . $version, ENT_QUOTES, 'UTF-8') . '" data-aichat="1">'
			. "\n" . '<script>window.AICHAT=' . $config . ';window.AICHAT_CONFIG=' . $widgetConfig . ';</script>'
			. "\n" . '<script src="' . htmlspecialchars($base . $asset . 'widget.js' . $version, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";

		$pos = strripos($output, '</body>');

		if ($pos !== false) {
			$output = substr($output, 0, $pos) . $tags . substr($output, $pos);
		} else {
			$output .= $tags;
		}
	}

	// ================================================================ helpers

	private function settings() {
		if ($this->settingsObj === null) {
			$this->settingsObj = new \Aichat\Settings($this->registry);
		}

		return $this->settingsObj;
	}

	private function botName(array $store) {
		$name = trim(html_entity_decode((string)$store['s_bot_name'], ENT_QUOTES, 'UTF-8'));

		if ($name === '') {
			$this->load->language('extension/module/aichat');
			$name = $this->language->get('default_bot_name');
		}

		return $name;
	}

	private function strings($botName) {
		$out = array();

		foreach ($this->language->all() as $key => $value) {
			if (strpos($key, 'widget_') === 0 && is_string($value)) {
				$out[substr($key, 7)] = $key === 'widget_greeting' ? sprintf($value, $botName) : $value;
			}
		}

		return $out;
	}

	private function languageName($languageId) {
		$this->load->model('localisation/language');
		$language = $this->model_localisation_language->getLanguage($languageId);

		return $language && !empty($language['name']) ? html_entity_decode($language['name'], ENT_QUOTES, 'UTF-8') : (string)$this->config->get('config_language');
	}

	private function contactsLine(array $store) {
		$c = is_array($store['s_contacts']) ? $store['s_contacts'] : array();
		$parts = array();

		foreach (array('phone', 'email') as $key) {
			if (!empty($c[$key])) {
				$parts[] = trim($c[$key]);
			}
		}

		if (!$parts) {
			foreach (array('config_telephone', 'config_email') as $key) {
				if (trim((string)$this->config->get($key)) !== '') {
					$parts[] = trim((string)$this->config->get($key));
				}
			}
		}

		return $parts ? implode(', ', $parts) : $this->language->get('error_contacts_page');
	}

	/** Visitor text: valid UTF-8, no control characters except newlines/tabs. */
	private function cleanText($text) {
		if (function_exists('mb_convert_encoding')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}

		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$text);

		return trim((string)$text);
	}

	/**
	 * Stored history -> Messages API turns. Staff replies become assistant
	 * turns; shown product cards are noted so the model knows what it showed.
	 */
	private function modelMessages(array $history, $current) {
		$turns = array();

		foreach ($history as $row) {
			$role = $row['role'] === 'user' ? 'user' : 'assistant';
			$text = trim((string)$row['content']);

			if ($row['role'] === 'staff') {
				$text = '[Store staff]: ' . $text;
			}

			if ($role === 'assistant' && !empty($row['meta']['products'])) {
				$names = array();
				foreach ($row['meta']['products'] as $group) {
					foreach ($group['items'] as $item) {
						$names[] = '#' . (int)$item['product_id'] . ' ' . $item['name'] . ($group['label'] === 'suggestion' ? ' (suggestion)' : '');
					}
				}
				if ($names) {
					$text .= ($text !== '' ? "\n" : '') . '[Product cards shown: ' . implode('; ', $names) . ']';
				}
			}

			if ($text === '') {
				continue;
			}

			$n = count($turns);

			if ($n && $turns[$n - 1]['role'] === $role) {
				$turns[$n - 1]['content'] .= "\n\n" . $text;
			} else {
				$turns[] = array('role' => $role, 'content' => $text);
			}
		}

		// The API conversation starts with the visitor
		while ($turns && $turns[0]['role'] !== 'user') {
			array_shift($turns);
		}

		$n = count($turns);

		if ($n && $turns[$n - 1]['role'] === 'user') {
			$turns[$n - 1]['content'] .= "\n\n" . $current;
		} else {
			$turns[] = array('role' => 'user', 'content' => $current);
		}

		return $turns;
	}

	private function pageMeta(array $page) {
		$meta = array('type' => $page['type']);

		foreach (array('id', 'title') as $key) {
			if (isset($page[$key])) {
				$meta[$key] = $page[$key];
			}
		}

		return $meta;
	}

	/** Groups stored in message meta: ids + names only (cards are rebuilt with current prices). */
	private function groupsMeta(array $groups) {
		$out = array();

		foreach ($groups as $group) {
			$items = array();
			foreach ($group['items'] as $card) {
				$items[] = array('product_id' => (int)$card['product_id'], 'name' => $card['name']);
			}
			$out[] = array('label' => $group['label'], 'items' => $items);
		}

		return $out;
	}

	private function cardGroups(\Aichat\Tools $tools, $meta) {
		if (!is_array($meta) || empty($meta['products']) || !is_array($meta['products'])) {
			return array();
		}

		$out = array();

		foreach ($meta['products'] as $group) {
			$ids = array();
			foreach (isset($group['items']) ? $group['items'] : array() as $item) {
				$ids[] = (int)$item['product_id'];
			}
			$cards = $tools->cards($ids);
			if ($cards) {
				$out[] = array('label' => isset($group['label']) ? $group['label'] : 'matches', 'items' => $cards);
			}
		}

		return $out;
	}

	/** Widget contract: products = flat list of cards (matches first, then suggestions). */
	private function flatten(array $groups) {
		$items = array();

		foreach (array('matches', 'suggestion') as $label) {
			foreach ($groups as $group) {
				if ($group['label'] === $label) {
					foreach ($group['items'] as $card) {
						$items[] = $card;
					}
				}
			}
		}

		return $items;
	}

	private function errorBody($code, $arg = null) {
		$text = $this->language->get('error_' . $code);

		if ($text === 'error_' . $code) {
			$text = $this->language->get('error_api_error');
		}

		if ($arg !== null && strpos($text, '%s') !== false) {
			$text = sprintf($text, $arg);
		}

		return array('code' => $code, 'message' => $text);
	}

	private function fail($code, $arg = null) {
		$error = $this->errorBody($code, $arg);

		if ($this->streaming) {
			$this->sseStart();
			$this->sse('error', $error);
			return;
		}

		$this->json(array('error' => $error));
	}

	private function finish(array $data) {
		if ($this->streaming) {
			$this->sse('done', array('message_id' => $data['message_id'], 'mode' => $data['mode']));
			return;
		}

		unset($data['message_id']);
		$this->json($data);
	}

	private function json($data) {
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private $sseStarted = false;

	private function sseStart() {
		if ($this->sseStarted) {
			return;
		}

		$this->sseStarted = true;

		@ignore_user_abort(true);
		@set_time_limit(180);
		@ini_set('zlib.output_compression', '0');
		@ini_set('implicit_flush', '1');

		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}

		while (ob_get_level() > 0) {
			@ob_end_clean();
		}

		if (!headers_sent()) {
			header('Content-Type: text/event-stream; charset=utf-8');
			header('Cache-Control: no-cache, no-store, no-transform');
			header('X-Accel-Buffering: no');
		}

		// padding defeats small proxy buffers
		echo ':' . str_repeat(' ', 2048) . "\n\n";
		flush();
	}

	/** Public only so the tool-loop closures can call it. Not a route: does nothing outside a stream. */
	public function sse($event = '', $data = array()) {
		if (!$this->sseStarted || $event === '') {
			return;
		}

		echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
		flush();
	}
}
