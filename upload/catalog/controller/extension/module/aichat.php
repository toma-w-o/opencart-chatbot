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
	/** Transcript e-mails per store per day. */
	const TRANSCRIPT_STORE_DAY = 50;

	private $settingsObj = null;
	private $streaming = false;

	// ================================================================ boot

	public function boot() {
		$settings = $this->settings();
		$this->ensureSchema($settings);
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

		// boot without a conversation writes nothing; still, no endless free hits per IP
		if (!$guard->hit('b:i:' . \Aichat\Guard::rateIp($guard->clientIp($this->request->server)), 60, 120)) {
			$this->response->addHeader('HTTP/1.1 429 Too Many Requests');
			$this->json(array('enabled' => false, 'retry' => true));
			return;
		}

		// token in the X-Aichat-Token header (not in the URL: URLs end up in logs);
		// the query parameter is still read here only, for widgets cached before 1.0.2
		$token = $this->headerToken();
		if ($token === '' && isset($this->request->get['token']) && is_string($this->request->get['token'])) {
			$token = $this->request->get['token'];
		}
		$publicId = $guard->parseToken($token, $storeId);
		$conversation = $publicId ? $this->model_extension_module_aichat->getConversation($publicId, $storeId) : null;

		// Never show a customer's conversation to someone else on the same browser
		if ($conversation && (int)$conversation['customer_id'] && (int)$conversation['customer_id'] !== (int)$this->customer->getId()) {
			$conversation = null;
			$publicId = false;
		}

		// Resume window: older conversations start fresh
		if ($conversation && !$this->resumable($conversation, $store)) {
			$conversation = null;
			$publicId = false;
		}

		if (!$publicId) {
			// The conversation row is created lazily on the first message
			$publicId = \Aichat\Guard::newPublicId();
		}

		$botName = $this->botName($store);
		$history = array();
		$token = $guard->makeToken($publicId, $storeId);

		if ($conversation && (new \Aichat\Handover($this->registry, $settings))->checkTimeout($conversation)) {
			$conversation = $this->model_extension_module_aichat->getConversation($publicId, $storeId);
		}

		if ($conversation) {
			$tools = new \Aichat\Tools($this->registry, $storeId, $languageId);
			$tools->setContext($settings, $conversation, '');

			foreach ($this->model_extension_module_aichat->getMessages($conversation['conversation_id'], self::HISTORY_FOR_WIDGET, array('user', 'assistant', 'staff', 'system')) as $message) {
				$item = array('role' => in_array($message['role'], array('user', 'staff', 'system'), true) ? $message['role'] : 'assistant', 'content' => $message['content'], 'message_id' => (int)$message['message_id']);
				if ($message['role'] === 'staff' && !empty($message['meta']['staff_name'])) {
					$item['staff_name'] = (string)$message['meta']['staff_name'];
				}
				if ($item['role'] === 'assistant') {
					$item['rating'] = (int)$message['rating'];
				}
				if (!empty($message['meta']['images']) && is_array($message['meta']['images'])) {
					$item['images'] = $this->imageItems($message['meta']['images'], $guard);
				}
				$groups = $this->cardGroups($tools, $message['meta']);
				if ($groups) {
					$item['products'] = $this->flatten($groups);
					$item['product_groups'] = $groups;
				}
				if (!empty($message['meta']['cards']) && is_array($message['meta']['cards'])) {
					$item['cards'] = array_values($message['meta']['cards']);
				}
				if ($item['content'] === '' && empty($item['products']) && empty($item['cards']) && empty($item['images'])) {
					continue;
				}
				$history[] = $item;
			}
		}

		$pageType = 'other';
		$url = isset($this->request->get['url']) ? (string)$this->request->get['url'] : '';
		if ((int)$store['s_proactive'] && !$history && $url !== '') {
			$pageContext = new \Aichat\PageContext($this->registry);
			$page = $pageContext->resolve(substr($url, 0, 2000));
			$pageType = $page['type'];
		}
		$proactiveText = $this->byLanguage($store['s_proactive_text'], $languageId);

		$greeting = $this->byLanguage($store['s_greeting'], $languageId);
		if ($greeting === '') {
			$greeting = sprintf($this->language->get('widget_greeting'), $botName);
		}

		$welcomeBack = $this->byLanguage($store['s_welcome_back'], $languageId);
		if ($welcomeBack === '') {
			$welcomeBack = $this->language->get('widget_welcome_back');
		}

		$turnstile = $guard->turnstileEnabled() ? trim((string)$settings->getGlobal('g_turnstile_site')) : '';
		$wait = $conversation ? (new \Aichat\Handover($this->registry, $settings))->waitInfo($conversation) : array();

		$this->json($wait + array(
			'enabled'        => true,
			'bot_name'       => $botName,
			'avatar'         => $this->avatarId($store['s_avatar']),
			'greeting'       => $greeting,
			'emoji'          => (int)$store['s_emoji'],
			'strings'        => $this->strings($botName),
			'token'          => $token,
			'history'        => $history,
			'mode'           => $conversation ? ($conversation['mode'] === 'closed' ? 'bot' : $conversation['mode']) : 'bot',
			'turnstile_site' => $turnstile !== '' ? $turnstile : null,
			'max_len'        => (int)$store['s_max_len'],
			'returning'      => (bool)$history,
			'welcome_back'   => $welcomeBack,
			'quick_replies'  => $this->quickReplies($store, $languageId),
			'logged_in'      => (bool)$this->customer->isLogged(),
			'appearance'     => $this->appearance($store, $languageId, $botName),
			'features'       => array(
				'images' => (int)$store['s_feat_images'] === 1 && function_exists('imagecreatetruecolor'),
				'rating' => (int)$store['s_feat_rating'] === 1,
				'cart'   => (int)$store['s_feat_cart'] === 1,
			),
			'sound'          => (int)$store['s_sound'] === 1,
			'proactive'      => array(
				// decided here: only on product / category / search / brand pages, only for new visitors
				'enabled' => (int)$store['s_proactive'] === 1 && in_array($pageType, array('product', 'category', 'search', 'manufacturer'), true),
				'delay'   => max(3, min(600, (int)$store['s_proactive_delay'])),
				'text'    => $proactiveText !== '' ? $proactiveText : $this->language->get('widget_proactive'),
			),
		));

		$this->maintenance($settings);
	}

	/** Lazy housekeeping after the response (weekly report, retention), see \Aichat\Maintenance. */
	private function maintenance(\Aichat\Settings $settings) {
		$maintenance = new \Aichat\Maintenance($this->registry, $settings);

		if (!$maintenance->due()) {
			return;
		}

		$response = $this->response;
		register_shutdown_function(function () use ($maintenance, $response) {
			if (function_exists('fastcgi_finish_request')) {
				@fastcgi_finish_request();
			}
			$maintenance->run();
		});
	}

	/** New columns / tables on the storefront before the admin was opened after an update. */
	private function ensureSchema(\Aichat\Settings $settings) {
		if ((int)$settings->getGlobal('g_schema') < \Aichat\Installer::SCHEMA && (int)$settings->getGlobal('g_schema') > 0) {
			\Aichat\Installer::upgrade($this->registry);
			$this->settingsObj = null;
		}
	}

	/** history / poll images: [{id, thumb}] (short-lived signed URLs, no token in them). */
	private function imageItems(array $ids, \Aichat\Guard $guard) {
		$out = array();

		foreach ($ids as $id) {
			$out[] = array('id' => (int)$id, 'thumb' => $this->imageUrl($guard, (int)$id, true));
		}

		return $out;
	}

	/** image&i=<id>&e=<expiry>&t=<thumb>&s=<hmac>: valid for Guard::IMAGE_URL_TTL seconds. */
	private function imageUrl(\Aichat\Guard $guard, $id, $thumb) {
		$expires = (int)(ceil((time() + \Aichat\Guard::IMAGE_URL_TTL) / 600) * 600) - 600; // stable for 10 min (browser cache)
		$expires = max($expires, time() + 60);

		return $this->endpoint() . 'image&i=' . (int)$id . '&e=' . $expires . '&t=' . ($thumb ? 1 : 0) . '&s=' . $guard->imageSig((int)$id, $expires, $thumb);
	}

	/** Full client IP (stored on the conversation; rate keys use Guard::rateIp()). */
	private function guardIp(\Aichat\Guard $guard) {
		return $guard->clientIp($this->request->server);
	}

	/** Conversation token from the X-Aichat-Token header ('' when absent). */
	private function headerToken() {
		return isset($this->request->server['HTTP_X_AICHAT_TOKEN']) && is_string($this->request->server['HTTP_X_AICHAT_TOKEN']) ? trim($this->request->server['HTTP_X_AICHAT_TOKEN']) : '';
	}

	/**
	 * State-changing POST routes: refuse cross-site requests. A browser always
	 * sends Origin (or at least Referer) cross-site; it must be one of this
	 * store's hosts. $json: the body must be declared application/json (a
	 * cross-site form cannot send that without a CORS preflight).
	 * Server-to-server callers (admin test chat) send neither header.
	 */
	private function sameOrigin($json) {
		$server = $this->request->server;

		if ($json) {
			$type = isset($server['CONTENT_TYPE']) ? strtolower((string)$server['CONTENT_TYPE']) : (isset($server['HTTP_CONTENT_TYPE']) ? strtolower((string)$server['HTTP_CONTENT_TYPE']) : '');
			if (strpos(trim($type), 'application/json') !== 0) {
				return false;
			}
		}

		$source = '';

		if (isset($server['HTTP_ORIGIN']) && (string)$server['HTTP_ORIGIN'] !== '') {
			$source = (string)$server['HTTP_ORIGIN'];
		} elseif (isset($server['HTTP_REFERER']) && (string)$server['HTTP_REFERER'] !== '') {
			$source = (string)$server['HTTP_REFERER'];
		} else {
			return true;
		}

		$host = preg_replace('/^www\./', '', strtolower((string)parse_url($source, PHP_URL_HOST)));

		return $host !== '' && in_array($host, \Aichat\PageContext::storeHosts($this->config), true);
	}

	/** Absolute base of our routes: <store url>index.php?route=extension/module/aichat/ */
	private function endpoint() {
		$secure = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off';
		$base = $secure && $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');

		return rtrim((string)$base, '/') . '/index.php?route=extension/module/aichat/';
	}

	/** Conversation still inside the resume window (s_resume_days, 0 = always resume). */
	private function resumable(array $conversation, array $store) {
		$days = (int)$store['s_resume_days'];

		if ($days <= 0) {
			return true;
		}

		$last = strtotime($conversation['date_modified']);

		return $last && $last >= time() - $days * 86400;
	}

	/** Value of a per-language setting (array by language_id) as plain text. */
	private function byLanguage($value, $languageId) {
		if (is_array($value) && isset($value[$languageId]) && is_string($value[$languageId])) {
			return trim(html_entity_decode($value[$languageId], ENT_QUOTES, 'UTF-8'));
		}

		return '';
	}

	private function quickReplies(array $store, $languageId) {
		$raw = isset($store['s_quick_replies'][$languageId]) ? $store['s_quick_replies'][$languageId] : '';
		$lines = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n/', html_entity_decode((string)$raw, ENT_QUOTES, 'UTF-8'));
		$out = array();

		foreach ($lines as $line) {
			$line = trim((string)$line);
			if ($line !== '' && \Aichat\Tools::len($line) <= 80 && !in_array($line, $out, true)) {
				$out[] = $line;
			}
			if (count($out) >= 6) {
				break;
			}
		}

		return $out;
	}

	/** boot.appearance from the per-store settings (validated, defaults filled in). */
	private function appearance(array $store, $languageId, $botName) {
		$defaults = \Aichat\Settings::storeDefaults();
		$color = function ($key) use ($store, $defaults) {
			$value = trim((string)$store[$key]);
			return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) ? strtolower($value) : $defaults[$key];
		};
		$pick = function ($value, array $allowed) {
			return in_array($value, $allowed, true) ? $value : $allowed[0];
		};
		$int = function ($value, $min, $max, $default) {
			return is_numeric($value) && (int)$value >= $min && (int)$value <= $max ? (int)$value : $default;
		};

		$style = $pick((string)$store['s_launcher_style'], array('avatar', 'avatar_text'));
		$size = $int($store['s_launcher_size'], 32, 120, 56);
		$avatarSize = (int)$store['s_launcher_avatar_size'] > 0 ? $int($store['s_launcher_avatar_size'], 16, 120, 45) : ($style === 'avatar_text' ? 36 : 45);
		$text = $this->byLanguage($store['s_launcher_text'], $languageId);

		$css = html_entity_decode((string)$store['s_custom_css'], ENT_QUOTES, 'UTF-8');
		$css = str_ireplace(array('</style', '<script', '</script'), '', $css);

		return array(
			'accent'      => $color('s_color_accent'),
			'header_bg'   => $color('s_color_header_bg'),
			'header_text' => $color('s_color_header_text'),
			'user_bg'     => $color('s_color_user_bg'),
			'user_text'   => $color('s_color_user_text'),
			'launcher'    => array(
				'style'       => $style,
				'text'        => $text !== '' ? $text : $botName,
				'shape'       => $pick((string)$store['s_launcher_shape'], array('circle', 'rounded', 'pill')),
				'size'        => $size,
				'avatar_size' => $avatarSize,
			),
			'position'    => array(
				'desktop' => array(
					'side' => $pick((string)$store['s_pos_desktop_side'], array('right', 'left')),
					'x'    => $int($store['s_pos_desktop_x'], 0, 2000, 20),
					'y'    => $int($store['s_pos_desktop_y'], 0, 2000, 20),
				),
				'mobile'  => array(
					'side' => $pick((string)$store['s_pos_mobile_side'], array('right', 'left')),
					'x'    => $int($store['s_pos_mobile_x'], 0, 2000, 20),
					'y'    => $int($store['s_pos_mobile_y'], 0, 2000, 20),
				),
			),
			'z_index'     => $int($store['s_zindex'], 1, 2147483647, 2147483000),
			'custom_css'  => $css,
			'attention'   => array(
				'enabled'  => (int)$store['s_attention'] === 1,
				'interval' => $int($store['s_attention_interval'], 2, 600, 5),
			),
		);
	}

	/** Saved avatar id, or "default" when it is not in the installed avatar set (avatars/manifest.json). */
	private function avatarId($id) {
		$id = (string)$id;

		if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
			return 'default';
		}

		$file = DIR_APPLICATION . 'view/javascript/aichat/avatars/manifest.json';
		$list = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;

		if (!is_array($list)) {
			return $id; // no manifest: the widget falls back itself
		}

		foreach ($list as $item) {
			if (is_array($item) && isset($item['id']) && (string)$item['id'] === $id) {
				return $id;
			}
		}

		return 'default';
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

		if (!$this->sameOrigin(true)) {
			$this->fail('forbidden');
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

		// --- token -> conversation (bound to this store); admin test chat: preview token
		$preview = null;

		if (isset($input['preview']) && $input['preview'] !== '' && $input['preview'] !== null) {
			$previewId = $guard->parsePreviewToken($input['preview']);
			$preview = $previewId ? $model->getPreview($previewId) : null;

			if (!$preview || (int)$preview['store_id'] !== $storeId) {
				$this->fail('invalid_token');
				return;
			}

			$publicId = $guard->previewPublicId($previewId, (string)$preview['date_added']);
		} else {
			$publicId = $guard->parseToken(isset($input['token']) ? $input['token'] : '', $storeId);
		}

		if (!$publicId) {
			$this->fail('invalid_token');
			return;
		}

		$isTest = $preview !== null;
		$conversation = $model->getConversation($publicId, $storeId);

		if ($conversation && (int)$conversation['customer_id'] && (int)$conversation['customer_id'] !== $customerId) {
			$this->fail('invalid_token');
			return;
		}

		if ($conversation && $isTest !== (bool)(int)$conversation['is_test']) {
			$this->fail('invalid_token');
			return;
		}

		// --- message (+ images)
		$message = isset($input['message']) && is_string($input['message']) ? $this->cleanText($input['message']) : '';
		$url = isset($input['url']) && is_string($input['url']) ? substr($input['url'], 0, 2000) : '';
		$imageIds = isset($input['images']) && is_array($input['images']) ? array_slice(array_values(array_filter(array_map('intval', array_filter($input['images'], 'is_numeric')))), 0, 10) : array();

		// the widget sends an image-only message with the text "Photo" (or its localised string): no caption
		if ($imageIds && in_array($message, array('Photo', $this->language->get('widget_img_caption')), true)) {
			$message = '';
		}

		if ($isTest && $url === '') {
			$url = (string)$preview['url'];
		}

		if ($imageIds && !(int)$store['s_feat_images']) {
			$imageIds = array();
		}

		if (count($imageIds) > \Aichat\Uploads::MAX_PER_MESSAGE) {
			$this->fail('image_limit', \Aichat\Uploads::MAX_PER_MESSAGE);
			return;
		}

		if ($message === '' && !$imageIds) {
			$this->fail('empty');
			return;
		}

		if (\Aichat\Tools::len($message) > (int)$store['s_max_len']) {
			$this->fail('too_long', (int)$store['s_max_len']);
			return;
		}

		$ip = $guard->clientIp($this->request->server);
		$ipKey = \Aichat\Guard::rateIp($ip);
		$newVerified = 0;

		if ($isTest) {
			// admin test chat (HMAC preview token): no Turnstile, no visitor limits, a generous flood guard
			if (!$guard->hit('m:p:' . $publicId, 60, 30)) {
				$this->fail('rate_limited');
				return;
			}
		} else {
			// --- stage 2: Cloudflare Turnstile, once per conversation (challenge, never a hard block)
			$verifiedState = $conversation ? (int)$conversation['human_verified'] : 0;
			$turnstileToken = isset($input['turnstile']) && is_string($input['turnstile']) ? trim($input['turnstile']) : '';
			// many failed order checks in this store this hour (spread over IPs): conversations that
			// only got through on a Turnstile outage are challenged again
			$mustChallenge = $verifiedState === 0 || ($verifiedState !== 1 && $guard->underOrderAttack($storeId));

			if ($guard->turnstileEnabled() && $mustChallenge) {
				if ($turnstileToken === '') {
					$this->fail('challenge');
					return;
				}

				if ($turnstileToken === 'unavailable') {
					// the visitor's browser could not run Turnstile: a tight per-IP budget and a small
					// store-wide hourly budget; past it the challenge stands (a real outage of
					// Cloudflare is detected server-side below, never taken from the browser)
					if (!$guard->hitUnavailable($ip, $storeId)) {
						$this->fail('challenge');
						return;
					}
				} else {
					$check = $guard->verifyTurnstile($turnstileToken, $ip);

					if ($check === 'fail') {
						$this->fail('challenge');
						return;
					}

					// 'outage': remembered as 2 = not challenged again, but no per-IP exemption
					$newVerified = $check === 'ok' ? 1 : 2;
					$verifiedState = $newVerified;
				}
			}

			// --- rate limits (per conversation, per IP, per chat, daily chat cap)
			$limit = $guard->checkMessage($store, $conversation, $publicId, $ip, $verifiedState === 1, $storeId);

			if ($limit !== '') {
				$this->fail($limit, $this->contactsLine($store));
				return;
			}
		}

		// --- one of a few concurrent model requests per store (shared hosting: keep workers free)
		if (!$guard->acquireSlot($storeId)) {
			$this->fail('busy');
			return;
		}
		register_shutdown_function(array($guard, 'releaseSlots'));

		// images must have been uploaded to this very conversation
		$uploads = new \Aichat\Uploads($this->registry);

		if ($imageIds) {
			$owned = $conversation ? $uploads->filterOwned($imageIds, (int)$conversation['conversation_id']) : array();
			if (count($owned) !== count(array_unique($imageIds))) {
				$this->fail('image_not_found');
				return;
			}
			$imageIds = $owned;
		}

		if ($this->streaming) {
			$this->sseStart();
		}

		// --- persist
		if (!$conversation) {
			$conversationId = $this->createConversation($model, $guard, $storeId, $languageId, $customerId, $publicId, $ip, $url, $isTest);
		} else {
			$conversationId = (int)$conversation['conversation_id'];
			if ((int)$conversation['user_messages'] === 0 && !$isTest) {
				$guard->countChat($storeId); // created by an image upload: counted with its first message
			}
		}

		if ($newVerified) {
			$model->setHumanVerified($conversationId, $newVerified);
		}

		$conversation = $model->getConversation($publicId, $storeId);
		$handover = new \Aichat\Handover($this->registry, $settings);

		if ($handover->checkTimeout($conversation)) {
			$conversation = $model->getConversation($publicId, $storeId);
		}

		$tools = new \Aichat\Tools($this->registry, $storeId, $languageId);
		$tools->setContext($settings, $conversation, $ipKey);
		$pageContext = new \Aichat\PageContext($this->registry, $tools);
		$page = $pageContext->resolve($url);
		$orderAttemptsBefore = (int)$conversation['order_attempts'];

		$history = $model->getMessages($conversationId, self::HISTORY_FOR_MODEL);
		$userMeta = array('page' => $this->pageMeta($page), 'url' => $url);
		if ($imageIds) {
			$userMeta['images'] = $imageIds;
		}
		$userMessageId = $model->addMessage($conversationId, 'user', $message, $userMeta);
		$model->touchConversation($conversationId, $url, $languageId, $customerId);

		if (!$isTest) {
			$guard->countMessage($storeId);
		}

		$imageRows = array();
		foreach ($imageIds as $imageId) {
			$row = $uploads->get($imageId, $conversationId);
			if ($row) {
				$imageRows[] = $row;
			}
		}

		// --- not in bot mode: stage 5, the visitor's message goes to the staff topic
		$mode = $conversation['mode'] === 'closed' ? 'bot' : $conversation['mode'];

		if ($conversation['mode'] === 'closed') {
			$model->setMode($conversationId, 'bot');
		}

		if ($mode !== 'bot') {
			$handover->forwardVisitor($conversation, $message, $imageRows);
			$this->finish($handover->waitInfo($conversation) + array('reply' => '', 'products' => array(), 'product_groups' => array(), 'cards' => array(), 'mode' => $mode, 'message_id' => $userMessageId));
			return;
		}

		// --- budget and cost caps (checked again before every API call in the loop)
		$budget = $guard->checkBudget($storeId, $conversationId, $isTest ? '' : $ip);
		if ($budget !== '') {
			$this->fail($budget, $this->contactsLine($store));
			return;
		}

		// --- prompt + conversation
		$botName = $this->botName($store);
		$prompt = new \Aichat\Prompt($this->registry, $settings, $tools);
		if ($isTest) {
			$prompt->setDraft($preview['data']);
		}
		$system = $prompt->system($store, $botName, $this->languageName($languageId), $page, $this->customer->isLogged() ? (string)$this->customer->getFirstName() : '', (int)$conversation['verified_order_id'], $this->customer->isLogged());
		$messages = $this->modelMessages($history, $message, $imageRows);

		$client = new \Aichat\Client($settings->getApiKey(), $settings->getGlobal('g_model'));
		$filter = new \Aichat\LinkFilter(\Aichat\PageContext::storeHosts($this->config));

		// courier tracking links of the order verified in this conversation
		foreach ($tools->orders()->verifiedUrls() as $trackingUrl) {
			$filter->allowUrl($trackingUrl);
		}

		$self = $this;
		$streaming = $this->streaming;
		$reply = '';
		$groups = array();
		$linkCards = array();
		$totals = array('in' => 0, 'out' => 0, 'cost' => 0.0);

		$costIp = $isTest ? '' : $ip;
		$hooks = array(
			'before' => function () use ($self, $guard, $storeId, $conversationId, $costIp) {
				// the visitor is gone: no further (paid) rounds
				if (!$self->clientConnected()) {
					return 'aborted';
				}
				return $guard->checkBudget($storeId, $conversationId, $costIp);
			},
			'usage' => function ($usage) use ($guard, $storeId, $conversationId, $costIp, &$totals) {
				list($in, $out, $cost) = $guard->recordUsage($storeId, $conversationId, $usage, false, $costIp);
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
			'tool' => function ($name, $toolInput) use ($tools, $filter) {
				$out = $tools->execute($name, $toolInput);
				// tracking links of an order verified just now may appear in the reply
				foreach ($tools->orders()->allowedUrls() as $trackingUrl) {
					$filter->allowUrl($trackingUrl);
				}
				return $out;
			},
			'round_done' => function () use ($self, $tools, $streaming, &$groups, &$linkCards) {
				foreach ($tools->takeCards() as $group) {
					$groups[] = $group;
					if ($streaming) {
						$self->sse('products', $self->productsEvent($group));
					}
				}
				$cards = $tools->takeLinkCards();
				if ($cards) {
					foreach ($cards as $card) {
						$linkCards[] = $card;
					}
					if ($streaming) {
						$self->sse('cards', array('items' => $cards));
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

		if ($linkCards) {
			$meta['cards'] = $linkCards;
		}

		if ($result['error']) {
			$meta['error'] = $result['error'];
		}

		$messageId = 0;

		if ($totals['cost'] > 0) {
			$guard->budgetWarnings($this->registry, $storeId);
		}

		if ($reply !== '' || $groups || $linkCards || $totals['in'] || $totals['out']) {
			$messageId = $model->addMessage($conversationId, 'assistant', $reply, $meta, $totals['in'], $totals['out'], $totals['cost']);
			$model->bumpMessageCount($conversationId);
		}

		// failed order verifications of this request -> store-wide counter (Guard::underOrderAttack)
		$after = $model->getConversation($publicId, $storeId);
		if ($after && !$isTest) {
			$guard->recordOrderFailures($storeId, (int)$after['order_attempts'] - $orderAttemptsBefore);
		}

		if ($result['error'] && $result['error']['code'] === 'aborted') {
			return; // nobody is listening any more; what was produced is stored
		}

		if ($result['error'] && $reply === '') {
			$code = in_array($result['error']['code'], array('budget', 'cost_limit'), true) ? $result['error']['code'] : 'api_error';
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

		// request_human may have switched the conversation to waiting
		$mode = $after && in_array($after['mode'], array('waiting', 'human'), true) ? $after['mode'] : 'bot';

		$this->finish($handover->waitInfo($after) + array(
			'reply'          => $reply,
			'products'       => $this->flatten($groups),
			'product_groups' => $groups,
			'cards'          => $linkCards,
			'mode'           => $mode,
			'message_id'     => $messageId,
		));
	}

	/** Public only for the tool-loop closure: false once the visitor closed the stream. */
	public function clientConnected() {
		if (!$this->streaming) {
			return true;
		}

		// connection_aborted() notices a closed connection only when a write fails; the first
		// write after the peer left usually still succeeds, so write twice, a moment apart
		echo ": .\n\n";
		flush();

		if (!connection_aborted()) {
			usleep(20000);
			echo ": .\n\n";
			flush();
		}

		return !connection_aborted();
	}

	private function createConversation($model, \Aichat\Guard $guard, $storeId, $languageId, $customerId, $publicId, $ip, $url, $isTest, $count = true) {
		$conversationId = $model->addConversation(array(
			'store_id'    => $storeId,
			'language_id' => $languageId,
			'customer_id' => $customerId,
			'public_id'   => $publicId,
			'ip'          => $ip,
			'user_agent'  => isset($this->request->server['HTTP_USER_AGENT']) ? $this->request->server['HTTP_USER_AGENT'] : '',
			'url'         => $url,
			'is_test'     => $isTest,
		));

		if (!$isTest && $count) {
			$guard->countChat($storeId); // test chats are not counted (their cost still is)
		}

		return $conversationId;
	}

	/** Public only for the closures. `products` event: {label, items}; suggestion items carry label too. */
	public function productsEvent($group = array()) {
		if (!is_array($group) || !isset($group['items'])) {
			return array('items' => array());
		}

		$items = array();

		foreach ($group['items'] as $card) {
			if ($group['label'] === 'suggestion') {
				$card['label'] = 'suggestion';
			}
			$items[] = $card;
		}

		return array('label' => $group['label'], 'items' => $items);
	}

	// ================================================================ shared request helpers

	private function jsonInput() {
		$input = json_decode((string)file_get_contents('php://input'), true);

		return is_array($input) ? $input : array();
	}

	/**
	 * Token -> {store, store_id, public_id, conversation|null, guard, ip}, or
	 * null after a failure reply. Bound to this store and to the logged-in
	 * customer (never another customer's conversation). Test (preview)
	 * conversations are not reachable with a visitor token.
	 */
	private function tokenContext($token, $needPost = true, $json = true) {
		$this->load->language('extension/module/aichat');

		if ($needPost && $this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->fail('method');
			return null;
		}

		if ($needPost && !$this->sameOrigin($json)) {
			$this->fail('forbidden');
			return null;
		}

		$settings = $this->settings();
		$this->ensureSchema($settings);
		$settings = $this->settings();
		$storeId = (int)$this->config->get('config_store_id');
		$store = $settings->getStore($storeId);

		if (!$store['s_status'] || !$settings->hasApiKey()) {
			$this->fail('disabled');
			return null;
		}

		$guard = new \Aichat\Guard($this->registry, $settings);
		$publicId = $guard->parseToken(is_string($token) ? $token : '', $storeId);

		if (!$publicId) {
			$this->fail('invalid_token');
			return null;
		}

		$this->load->model('extension/module/aichat');
		$conversation = $this->model_extension_module_aichat->getConversation($publicId, $storeId);

		if ($conversation && (((int)$conversation['customer_id'] && (int)$conversation['customer_id'] !== (int)$this->customer->getId()) || (int)$conversation['is_test'])) {
			$this->fail('invalid_token');
			return null;
		}

		return array(
			'settings'     => $settings,
			'store'        => $store,
			'store_id'     => $storeId,
			'public_id'    => $publicId,
			'token'        => (string)$token,
			'conversation' => $conversation,
			'guard'        => $guard,
			'ip'           => \Aichat\Guard::rateIp($guard->clientIp($this->request->server)),
		);
	}

	private function limited(\Aichat\Guard $guard, array $keys) {
		foreach ($keys as $key => $rule) {
			if (!$guard->hit($key, $rule[0], $rule[1])) {
				$this->fail('rate_limited');
				return true;
			}
		}

		return false;
	}

	// ================================================================ images (stage 4)

	/** POST multipart token, file -> {id, thumb} */
	public function upload() {
		$ctx = $this->tokenContext(isset($this->request->post['token']) ? $this->request->post['token'] : '', true, false);

		if (!$ctx) {
			return;
		}

		if (!(int)$ctx['store']['s_feat_images']) {
			$this->fail('feature_off');
			return;
		}

		if ($this->limited($ctx['guard'], array('u:c:' . $ctx['public_id'] => array(600, 15), 'u:i:' . $ctx['ip'] => array(3600, 40)))) {
			return;
		}

		$file = isset($this->request->files['file']) && is_array($this->request->files['file']) ? $this->request->files['file'] : null;

		if (!$file || !isset($file['tmp_name'], $file['error']) || is_array($file['tmp_name'])) {
			$this->fail('image_bad');
			return;
		}

		if (in_array((int)$file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
			$this->fail('image_too_large', '5 MB');
			return;
		}

		if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
			$this->fail('image_bad');
			return;
		}

		// cheap checks before a conversation row is created for it
		if ((int)$file['size'] > \Aichat\Uploads::MAX_BYTES || (int)@filesize($file['tmp_name']) > \Aichat\Uploads::MAX_BYTES) {
			$this->fail('image_too_large', '5 MB');
			return;
		}

		if (\Aichat\Uploads::sniff($file['tmp_name']) === '') {
			$this->fail('image_type');
			return;
		}

		$conversation = $ctx['conversation'];

		if (!$conversation) {
			// a photo before the first message: the row is created now but counted as a chat (daily cap)
			// and challenged (Turnstile) with its first message; here only per-IP / store-wide row limits
			$cap = (int)$ctx['store']['s_max_chats_day'];
			if ($cap > 0 && $ctx['guard']->chatsToday($ctx['store_id']) >= $cap) {
				$this->fail('chat_day_limit', $this->contactsLine($ctx['store']));
				return;
			}
			if ($this->limited($ctx['guard'], array('uc:i:' . $ctx['ip'] . ':' . date('Y-m-d') => array(86400, 10), 'uc:s:' . $ctx['store_id'] => array(3600, 100)))) {
				return;
			}
			$this->createConversation($this->model_extension_module_aichat, $ctx['guard'], $ctx['store_id'], (int)$this->config->get('config_language_id'), (int)$this->customer->getId(), $ctx['public_id'], $this->guardIp($ctx['guard']), isset($this->request->post['url']) && is_string($this->request->post['url']) ? substr($this->request->post['url'], 0, 2000) : '', false, false);
			$conversation = $this->model_extension_module_aichat->getConversation($ctx['public_id'], $ctx['store_id']);
		}

		$uploads = new \Aichat\Uploads($this->registry);
		$result = $uploads->store((int)$conversation['conversation_id'], $file['tmp_name'], (int)$file['size']);

		if (isset($result['error'])) {
			$map = array('too_large' => 'image_too_large', 'bad_type' => 'image_type', 'bad_image' => 'image_bad', 'limit' => 'image_count', 'storage' => 'image_storage');
			$this->fail(isset($map[$result['error']]) ? $map[$result['error']] : 'image_bad', '5 MB');
			return;
		}

		$this->json(array('id' => $result['upload_id'], 'thumb' => $this->imageUrl($ctx['guard'], $result['upload_id'], true)));
	}

	/**
	 * GET image&i=<upload_id>&e=<expiry>&t=<0|1>&s=<signature>: a short-lived
	 * URL signed by the server for the owning conversation (history, upload
	 * answer). No conversation token in the URL.
	 */
	public function image() {
		$settings = $this->settings();
		$storeId = (int)$this->config->get('config_store_id');
		$guard = new \Aichat\Guard($this->registry, $settings);
		$get = $this->request->get;
		$id = isset($get['i']) && is_string($get['i']) && ctype_digit($get['i']) ? (int)$get['i'] : 0;
		$expires = isset($get['e']) && is_string($get['e']) && ctype_digit($get['e']) ? (int)$get['e'] : 0;
		$thumb = isset($get['t']) && $get['t'] === '1';
		$sig = isset($get['s']) && is_string($get['s']) ? $get['s'] : '';
		$file = '';

		if ($id && $guard->checkImageSig($id, $expires, $thumb, $sig)) {
			$this->load->model('extension/module/aichat');
			$row = $this->model_extension_module_aichat->getUploadWithStore($id);
			if ($row && (int)$row['store_id'] === $storeId) {
				$file = \Aichat\Uploads::path($row, $thumb);
			}
		}

		if ($file === '') {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
			$this->response->setOutput('Not found');
			return;
		}

		$this->response->addHeader('Content-Type: image/jpeg');
		$this->response->addHeader('X-Content-Type-Options: nosniff');
		$this->response->addHeader('Content-Disposition: inline; filename="image-' . $id . '.jpg"');
		$this->response->addHeader('Cache-Control: private, max-age=' . max(0, min(\Aichat\Guard::IMAGE_URL_TTL, $expires - time())));
		$this->response->setOutput((string)file_get_contents($file));
	}

	// ================================================================ rating

	/** POST {token, message_id, value: 1|-1} */
	public function rate() {
		$input = $this->jsonInput();
		$ctx = $this->tokenContext(isset($input['token']) ? $input['token'] : '');

		if (!$ctx) {
			return;
		}

		if (!(int)$ctx['store']['s_feat_rating']) {
			$this->fail('feature_off');
			return;
		}

		if ($this->limited($ctx['guard'], array('r:c:' . $ctx['public_id'] => array(60, 20)))) {
			return;
		}

		$value = isset($input['value']) && is_numeric($input['value']) ? (int)$input['value'] : 0;
		$messageId = isset($input['message_id']) && is_numeric($input['message_id']) ? (int)$input['message_id'] : 0;

		if (!in_array($value, array(1, -1, 0), true) || !$ctx['conversation'] || !$this->model_extension_module_aichat->rateMessage((int)$ctx['conversation']['conversation_id'], $messageId, $value)) {
			$this->fail('not_found');
			return;
		}

		$this->json(array('ok' => true));
	}

	// ================================================================ cart (OpenCart's own cart)

	/** POST {token, product_id, quantity, option?} -> {ok, cart_count, cart_total, message} | {need_options} */
	public function cart() {
		$input = $this->jsonInput();
		$ctx = $this->tokenContext(isset($input['token']) ? $input['token'] : '');

		if (!$ctx) {
			return;
		}

		if (!(int)$ctx['store']['s_feat_cart']) {
			$this->fail('feature_off');
			return;
		}

		if ($this->limited($ctx['guard'], array('k:i:' . $ctx['ip'] => array(600, 40)))) {
			return;
		}

		$productId = isset($input['product_id']) && is_numeric($input['product_id']) ? (int)$input['product_id'] : 0;
		$quantity = isset($input['quantity']) && is_numeric($input['quantity']) ? (int)$input['quantity'] : 1;
		$quantity = max(1, min(999, $quantity));

		// store-bound, status = 1, date_available: OpenCart's own product model
		$this->load->model('catalog/product');
		$product = $productId ? $this->model_catalog_product->getProduct($productId) : null;

		if (!$product) {
			$this->fail('cart_product');
			return;
		}

		if ($this->config->get('config_customer_price') && !$this->customer->isLogged()) {
			$this->fail('cart_login');
			return;
		}

		if ($this->model_catalog_product->getProfiles($productId)) {
			$this->fail('cart_options'); // subscription products: product page only
			return;
		}

		$given = isset($input['option']) && is_array($input['option']) ? $input['option'] : array();
		$option = array();
		$missing = false;
		$describe = array();
		$chosenValues = array();

		foreach ($this->model_catalog_product->getProductOptions($productId) as $po) {
			$poid = (int)$po['product_option_id'];
			$valid = array();
			foreach (isset($po['product_option_value']) && is_array($po['product_option_value']) ? $po['product_option_value'] : array() as $v) {
				$valid[(int)$v['product_option_value_id']] = $v;
			}

			$value = isset($given[$poid]) ? $given[$poid] : (isset($given[(string)$poid]) ? $given[(string)$poid] : null);

			if (in_array($po['type'], array('select', 'radio'), true)) {
				if (is_scalar($value) && isset($valid[(int)$value])) {
					$option[$poid] = (int)$value;
					$chosenValues[] = $valid[(int)$value];
				}
			} elseif ($po['type'] === 'checkbox') {
				$list = array();
				foreach (is_array($value) ? $value : (is_scalar($value) && $value !== '' ? array($value) : array()) as $v) {
					if (is_scalar($v) && isset($valid[(int)$v])) {
						$list[] = (int)$v;
						$chosenValues[] = $valid[(int)$v];
					}
				}
				if ($list) {
					$option[$poid] = array_values(array_unique($list));
				}
			} elseif (in_array($po['type'], array('text', 'textarea', 'date', 'time', 'datetime'), true)) {
				$text = is_scalar($value) ? trim($this->cleanText((string)$value)) : '';
				$patterns = array('date' => '/^\d{4}-\d{2}-\d{2}$/', 'time' => '/^\d{2}:\d{2}$/', 'datetime' => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/');
				if ($text !== '' && (!isset($patterns[$po['type']]) || preg_match($patterns[$po['type']], $text))) {
					$option[$poid] = htmlspecialchars(\Aichat\Tools::len($text) > 255 ? mb_substr($text, 0, 255, 'UTF-8') : $text, ENT_QUOTES, 'UTF-8');
				}
			} elseif ($po['type'] === 'file' && $po['required']) {
				$this->fail('cart_options'); // file options: product page only
				return;
			}

			if ($po['type'] === 'file') {
				continue;
			}

			$values = array();
			foreach ($valid as $v) {
				$values[] = array(
					'product_option_value_id' => (int)$v['product_option_value_id'],
					'name'                    => html_entity_decode((string)$v['name'], ENT_QUOTES, 'UTF-8'),
					'price'                   => (float)$v['price'] > 0 ? $v['price_prefix'] . $this->currency->format($this->tax->calculate($v['price'], $product['tax_class_id'], $this->config->get('config_tax') ? 'P' : false), $this->session->data['currency']) : '',
				);
			}

			$describe[] = array(
				'product_option_id' => $poid,
				'name'              => html_entity_decode((string)$po['name'], ENT_QUOTES, 'UTF-8'),
				'type'              => $po['type'],
				'required'          => (bool)$po['required'],
				'values'            => $values,
			);

			if ($po['required'] && !isset($option[$poid])) {
				$missing = true;
			}
		}

		if ($missing) {
			$this->json(array('need_options' => $describe));
			return;
		}

		$minimum = max(1, (int)$product['minimum']);

		if ($quantity < $minimum) {
			$this->fail('cart_minimum', $minimum);
			return;
		}

		// stock rules: without "checkout out-of-stock" nothing beyond the stock goes in (exact stock is not told)
		if (!$this->config->get('config_stock_checkout')) {
			$inCart = 0;
			foreach ($this->cart->getProducts() as $p) {
				if ((int)$p['product_id'] === $productId) {
					$inCart += (int)$p['quantity'];
				}
			}
			if ((int)$product['quantity'] < $quantity + $inCart) {
				$this->fail('cart_stock');
				return;
			}
			foreach ($chosenValues as $v) {
				if ((int)$v['subtract'] && (int)$v['quantity'] < $quantity) {
					$this->fail('cart_stock');
					return;
				}
			}
		}

		$this->cart->add($productId, $quantity, $option, 0);

		unset($this->session->data['shipping_method'], $this->session->data['shipping_methods'], $this->session->data['payment_method'], $this->session->data['payment_methods']);

		$count = $this->cart->countProducts() + (isset($this->session->data['vouchers']) ? count($this->session->data['vouchers']) : 0);

		$this->json(array(
			'ok'         => true,
			'cart_count' => $count,
			'cart_total' => $this->currency->format($this->cart->getTotal(), $this->session->data['currency']),
			'message'    => sprintf($this->language->get('text_cart_added'), html_entity_decode((string)$product['name'], ENT_QUOTES, 'UTF-8')),
		));
	}

	// ================================================================ transcript / forget

	/**
	 * POST {token, check: 1} -> {available, masked?, message?}
	 * POST {token}           -> mails the conversation to the address that
	 * belongs to it: the logged-in customer's e-mail, else the e-mail of the
	 * order verified in this conversation. No free address (no mail relay).
	 * Rate-limited per conversation, per IP and per store.
	 */
	public function transcript() {
		$input = $this->jsonInput();
		$ctx = $this->tokenContext(isset($input['token']) ? $input['token'] : '');

		if (!$ctx) {
			return;
		}

		if (!$ctx['conversation']) {
			$this->fail('not_found');
			return;
		}

		$email = $this->transcriptTarget($ctx);

		if (!empty($input['check'])) {
			$this->json($email !== ''
				? array('available' => true, 'masked' => \Aichat\Mailer::maskEmail($email))
				: array('available' => false, 'message' => $this->language->get('text_transcript_unavailable')));
			return;
		}

		if ($email === '') {
			$this->fail('transcript_unavailable');
			return;
		}

		if ($this->limited($ctx['guard'], array('t:c:' . $ctx['public_id'] => array(86400, 3), 't:i:' . $ctx['ip'] . ':' . date('Y-m-d') => array(86400, 10), 't:s:' . $ctx['store_id'] . ':' . date('Y-m-d') => array(86400, self::TRANSCRIPT_STORE_DAY)))) {
			return;
		}

		$info = \Aichat\Mailer::storeConfig($this->registry, $ctx['store_id'], $ctx['settings']);
		$from = $info['email'];
		$botName = $this->botName($ctx['store']);
		$filter = new \Aichat\LinkFilter(\Aichat\PageContext::storeHosts($this->config));
		$text = sprintf($this->language->get('transcript_intro'), $info['name']) . "\n\n"
			. \Aichat\Handover::transcript($this->db, (int)$ctx['conversation']['conversation_id'], 500, array(
				'user'      => $this->language->get('transcript_you'),
				'assistant' => $botName,
				'staff'     => $this->language->get('transcript_staff'),
				'system'    => '—',
			), $filter) . "\n\n" . rtrim((string)$info['url'], '/') . "/\n";

		$ok = \Aichat\Mailer::send($this->registry, $email, sprintf($this->language->get('transcript_subject'), $info['name']), $text, array('from' => $from, 'sender' => $info['name'], 'store_id' => $ctx['store_id'], 'settings' => $ctx['settings']));

		if (!$ok) {
			$this->fail('api_error');
			return;
		}

		$this->json(array('ok' => true, 'message' => sprintf($this->language->get('text_transcript_sent'), \Aichat\Mailer::maskEmail($email))));
	}

	/** Address a transcript may go to ('' = none): logged-in owner's e-mail, else the verified order's e-mail. */
	private function transcriptTarget(array $ctx) {
		$conversation = $ctx['conversation'];

		if ($this->customer->isLogged() && (int)$conversation['customer_id'] === (int)$this->customer->getId()) {
			$email = \Aichat\Requests::validEmail((string)$this->customer->getEmail());
			if ($email !== '') {
				return $email;
			}
		}

		if ((int)$conversation['verified_order_id']) {
			return \Aichat\Requests::validEmail($this->model_extension_module_aichat->getOrderEmail((int)$conversation['verified_order_id'], $ctx['store_id']));
		}

		return '';
	}

	/** POST {token}: GDPR - deletes the conversation, its messages and uploads. */
	public function forget() {
		$input = $this->jsonInput();
		$ctx = $this->tokenContext(isset($input['token']) ? $input['token'] : '');

		if (!$ctx) {
			return;
		}

		if ($this->limited($ctx['guard'], array('f:i:' . $ctx['ip'] => array(3600, 20)))) {
			return;
		}

		$conversation = $ctx['conversation'];

		if ($conversation) {
			if ((string)$conversation['tg_chat_id'] !== '' && (int)$conversation['tg_topic_id'] && in_array($conversation['mode'], array('waiting', 'human'), true)) {
				$telegram = new \Aichat\Telegram($ctx['settings']);
				$telegram->sendMessage((string)$conversation['tg_chat_id'], (int)$conversation['tg_topic_id'], '🗑 The visitor deleted this conversation (GDPR). The chat is closed.');
			}
			\Aichat\Maintenance::deleteConversation($this->registry, (int)$conversation['conversation_id']);
		}

		$this->json(array('ok' => true, 'message' => $this->language->get('text_forgotten')));
	}

	// ================================================================ handover (stage 5)

	/** GET poll&token=&after=<message_id> -> {mode, messages:[{message_id, role, content, staff_name?}]} */
	public function poll() {
		// token in the X-Aichat-Token header only (never in the URL)
		$ctx = $this->tokenContext($this->headerToken(), false);

		if (!$ctx) {
			return;
		}

		$conversation = $ctx['conversation'];

		if (!$conversation) {
			// nothing to poll: no counters written for tokens that have no conversation
			$this->json(array('mode' => 'bot', 'messages' => array()));
			return;
		}

		if ($this->limited($ctx['guard'], array('p:c:' . $ctx['public_id'] => array(60, 40), 'p:i:' . $ctx['ip'] => array(60, 240)))) {
			return;
		}

		$handover = new \Aichat\Handover($this->registry, $ctx['settings']);

		if ($handover->checkTimeout($conversation)) {
			$conversation = $this->model_extension_module_aichat->getConversation($ctx['public_id'], $ctx['store_id']);
		}

		$after = isset($this->request->get['after']) && is_numeric($this->request->get['after']) ? (int)$this->request->get['after'] : 0;
		$messages = array();

		foreach ($this->model_extension_module_aichat->getMessagesAfter((int)$conversation['conversation_id'], $after) as $row) {
			$item = array('message_id' => (int)$row['message_id'], 'role' => $row['role'], 'content' => (string)$row['content']);
			if ($row['role'] === 'staff' && !empty($row['meta']['staff_name'])) {
				$item['staff_name'] = (string)$row['meta']['staff_name'];
			}
			if (!empty($row['meta']['cards']) && is_array($row['meta']['cards'])) {
				$item['cards'] = array_values($row['meta']['cards']);
			}
			$messages[] = $item;
		}

		$mode = $conversation['mode'] === 'closed' ? 'bot' : $conversation['mode'];

		$this->json($handover->waitInfo($conversation) + array('mode' => $mode, 'messages' => $messages));
	}

	/**
	 * Telegram webhook: index.php?route=extension/module/aichat/telegram, authenticated by
	 * the header X-Telegram-Bot-Api-Secret-Token (g_tg_secret). Public URL:
	 * everything else is answered 403 / ignored; only the configured groups count.
	 */
	public function telegram() {
		$settings = $this->settings();
		$this->ensureSchema($settings);
		$settings = $this->settings();
		$secret = $settings->telegramSecret();
		$header = isset($this->request->server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? (string)$this->request->server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
		// webhooks registered before 1.0.2 carry &s=<secret> in the URL: still accepted, but it must match
		$path = isset($this->request->get['s']) ? $this->request->get['s'] : null;

		$this->response->addHeader('Content-Type: application/json; charset=utf-8');

		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !hash_equals($secret, $header) || ($path !== null && (!is_string($path) || !hash_equals($secret, $path)))) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('{"ok":false}');
			return;
		}

		$raw = (string)file_get_contents('php://input');
		$update = strlen($raw) <= 65536 ? json_decode($raw, true) : null;
		$result = 'ignored';

		if (is_array($update) && isset($update['update_id']) && is_numeric($update['update_id'])) {
			$guard = new \Aichat\Guard($this->registry, $settings);
			// Telegram re-delivers on errors: each update_id once
			if ($guard->hit('tg:u:' . (int)$update['update_id'], 86400 * 2, 1)) {
				try {
					$handover = new \Aichat\Handover($this->registry, $settings);
					$result = $handover->handleUpdate($update);
				} catch (\Exception $e) {
					$this->log->write('AI Chat telegram: ' . $e->getMessage());
					$result = 'error';
				}
			} else {
				$result = 'duplicate';
			}
		}

		$this->response->setOutput(json_encode(array('ok' => true, 'result' => $result)));
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
	private function modelMessages(array $history, $current, array $imageRows = array()) {
		$turns = array();

		foreach ($history as $row) {
			$role = $row['role'] === 'user' ? 'user' : 'assistant';
			$text = trim((string)$row['content']);

			if ($row['role'] === 'staff') {
				$text = '[Store staff' . (!empty($row['meta']['staff_name']) ? ' ' . $row['meta']['staff_name'] : '') . ']: ' . $text;
			}

			if ($role === 'user' && !empty($row['meta']['images']) && is_array($row['meta']['images'])) {
				$text .= ($text !== '' ? "\n" : '') . '[Attached images earlier: #' . implode(', #', array_map('intval', $row['meta']['images'])) . ']';
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

			if ($role === 'assistant' && !empty($row['meta']['cards']) && is_array($row['meta']['cards'])) {
				$titles = array();
				foreach ($row['meta']['cards'] as $card) {
					if (isset($card['type'], $card['title'])) {
						$titles[] = $card['type'] . ': ' . $card['title'];
					}
				}
				if ($titles) {
					$text .= ($text !== '' ? "\n" : '') . '[Link cards shown: ' . implode('; ', $titles) . ']';
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

		if ($imageRows) {
			$ids = array();
			foreach ($imageRows as $img) {
				$ids[] = (int)$img['upload_id'];
			}
			$current = '[Attached images: #' . implode(', #', $ids) . ']' . ($current !== '' ? "\n" . $current : '');
		}

		$n = count($turns);

		if ($n && $turns[$n - 1]['role'] === 'user') {
			$turns[$n - 1]['content'] .= "\n\n" . $current;
		} else {
			$turns[] = array('role' => 'user', 'content' => $current);
		}

		// vision: image blocks first, then the text of the turn
		if ($imageRows) {
			$n = count($turns);
			$blocks = array();
			foreach ($imageRows as $img) {
				$block = \Aichat\Uploads::contentBlock($img);
				if ($block) {
					$blocks[] = $block;
				}
			}
			$blocks[] = array('type' => 'text', 'text' => $turns[$n - 1]['content']);
			$turns[$n - 1]['content'] = $blocks;
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
						if ($label === 'suggestion') {
							$card['label'] = 'suggestion';
						}
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
			$done = array('message_id' => $data['message_id'], 'mode' => $data['mode']);
			foreach (array('wait_until', 'wait_seconds', 'wait_text') as $k) {
				if (isset($data[$k])) {
					$done[$k] = $data[$k];
				}
			}
			$this->sse('done', $done);
			return;
		}

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
		@set_time_limit(90);
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
