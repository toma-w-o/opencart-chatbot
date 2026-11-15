<?php
namespace Aichat;

/**
 * Telegram Bot API over cURL (human handover, stage 5).
 *
 * The API base URL can be overridden for tests with the constant
 * AICHAT_TELEGRAM_BASE (e.g. "http://127.0.0.1:8097"); requests go to
 * <base>/bot<token>/<method>.
 *
 * The bot token lives in DIR_STORAGE/aichat/secret_tg_token.php
 * (Settings::setSecret('tg_token', ..)), never in the database.
 */
class Telegram {
	const BASE = 'https://api.telegram.org';
	const TIMEOUT = 8;
	const MAX_TEXT = 4000;

	private $settings;
	private $token;
	private $lastError = '';

	public function __construct(Settings $settings, $token = null) {
		$this->settings = $settings;
		$this->token = $token === null ? $settings->getNamedSecret('tg_token') : (string)$token;
	}

	public static function base() {
		return defined('AICHAT_TELEGRAM_BASE') ? rtrim((string)constant('AICHAT_TELEGRAM_BASE'), '/') : self::BASE;
	}

	public function hasToken() {
		return (bool)preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $this->token);
	}

	public function lastError() {
		return $this->lastError;
	}

	/** Group of a store: s_tg_chat_id override, else g_tg_chat_id ('' = not configured). */
	public function chatFor($storeId) {
		$own = trim((string)$this->settings->getStore($storeId, 's_tg_chat_id'));

		return self::validChat($own) ? $own : (self::validChat(trim((string)$this->settings->getGlobal('g_tg_chat_id'))) ? trim((string)$this->settings->getGlobal('g_tg_chat_id')) : '');
	}

	public static function validChat($chatId) {
		return (bool)preg_match('/^-?\d{5,20}$/', (string)$chatId);
	}

	/** Every configured group (global + per-store overrides): the webhook accepts only these. */
	public function allowedChats(array $storeIds) {
		$out = array();
		$global = trim((string)$this->settings->getGlobal('g_tg_chat_id'));

		if (self::validChat($global)) {
			$out[$global] = true;
		}

		foreach ($storeIds as $sid) {
			$own = trim((string)$this->settings->getStore($sid, 's_tg_chat_id'));
			if (self::validChat($own)) {
				$out[$own] = true;
			}
		}

		return array_keys($out);
	}

	public function configured($storeId) {
		return $this->hasToken() && $this->chatFor($storeId) !== '';
	}

	// ---------------------------------------------------------------- API methods

	/** @return int message_thread_id or 0 */
	public function createTopic($chatId, $name) {
		$result = $this->call('createForumTopic', array('chat_id' => $chatId, 'name' => self::cut($name, 128)));

		return is_array($result) && isset($result['message_thread_id']) ? (int)$result['message_thread_id'] : 0;
	}

	/** @return int message_id or 0 */
	public function sendMessage($chatId, $threadId, $text, $keyboard = null) {
		$params = array('chat_id' => $chatId, 'text' => self::cut($text, self::MAX_TEXT), 'disable_web_page_preview' => true);

		if ((int)$threadId > 0) {
			$params['message_thread_id'] = (int)$threadId;
		}

		if ($keyboard) {
			$params['reply_markup'] = json_encode(array('inline_keyboard' => $keyboard));
		}

		$result = $this->call('sendMessage', $params);

		return is_array($result) && isset($result['message_id']) ? (int)$result['message_id'] : 0;
	}

	public function sendPhoto($chatId, $threadId, $file, $caption = '') {
		if (!is_file($file) || !class_exists('CURLFile')) {
			return 0;
		}

		$params = array('chat_id' => $chatId, 'photo' => new \CURLFile($file, 'image/jpeg', 'photo.jpg'));

		if ((int)$threadId > 0) {
			$params['message_thread_id'] = (int)$threadId;
		}

		if ($caption !== '') {
			$params['caption'] = self::cut($caption, 1000);
		}

		$result = $this->call('sendPhoto', $params, true);

		return is_array($result) && isset($result['message_id']) ? (int)$result['message_id'] : 0;
	}

	public function answerCallback($callbackId, $text = '') {
		return $this->call('answerCallbackQuery', array('callback_query_id' => (string)$callbackId, 'text' => self::cut($text, 190))) !== null;
	}

	public function editKeyboard($chatId, $messageId, $keyboard) {
		return $this->call('editMessageReplyMarkup', array('chat_id' => $chatId, 'message_id' => (int)$messageId, 'reply_markup' => json_encode(array('inline_keyboard' => $keyboard ? $keyboard : array())))) !== null;
	}

	/**
	 * Registers the webhook (admin button). $url = catalog URL of
	 * extension/module/aichat/telegram&s=<g_tg_secret>; Telegram sends the
	 * same secret in the X-Telegram-Bot-Api-Secret-Token header.
	 */
	public function setWebhook($url) {
		$this->lastError = '';

		if (!preg_match('#^https://#i', (string)$url) && !defined('AICHAT_TELEGRAM_BASE')) {
			$this->lastError = 'Telegram requires an https:// webhook URL';
			return false;
		}

		return $this->call('setWebhook', array(
			'url'             => (string)$url,
			'secret_token'    => $this->settings->telegramSecret(),
			'allowed_updates' => json_encode(array('message', 'callback_query')),
			'drop_pending_updates' => 'true',
		)) !== null;
	}

	/** Webhook URL for a catalog base URL (e.g. "https://shop.bg/"). */
	public function webhookUrl($catalogUrl) {
		return rtrim((string)$catalogUrl, '/') . '/index.php?route=extension/module/aichat/telegram&s=' . rawurlencode($this->settings->telegramSecret());
	}

	/** Admin "send test" button: a message to the group (or the store's group). */
	public function test($chatId = '', $text = '') {
		$chatId = $chatId !== '' ? (string)$chatId : trim((string)$this->settings->getGlobal('g_tg_chat_id'));

		if (!$this->hasToken()) {
			$this->lastError = 'no bot token';
			return false;
		}

		if (!self::validChat($chatId)) {
			$this->lastError = 'no group id';
			return false;
		}

		return $this->sendMessage($chatId, 0, $text !== '' ? $text : 'AI Chat: test message ✅') > 0;
	}

	/** Raw API call; returns `result` or null (error in lastError()). */
	public function call($method, array $params, $multipart = false) {
		$this->lastError = '';

		if (!$this->hasToken() || !function_exists('curl_init')) {
			$this->lastError = 'no bot token';
			return null;
		}

		$ch = curl_init(self::base() . '/bot' . $this->token . '/' . $method);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $multipart ? $params : http_build_query($params),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
		));
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($body === false) {
			$this->lastError = 'network: ' . $error;
			return null;
		}

		$json = json_decode((string)$body, true);

		if (!is_array($json) || empty($json['ok'])) {
			// never log the token: the URL is not part of the message
			$this->lastError = 'HTTP ' . $status . ': ' . (is_array($json) && isset($json['description']) ? substr((string)$json['description'], 0, 200) : 'invalid response');
			return null;
		}

		return isset($json['result']) ? $json['result'] : true;
	}

	public static function cut($text, $max) {
		$text = (string)$text;

		return function_exists('mb_substr') && mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max - 1, 'UTF-8') . '…' : $text;
	}
}
