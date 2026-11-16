<?php
namespace Aichat;

/**
 * Rows in aichat_request (contact | stock | return | order) + the e-mail to the store.
 *
 * The e-mail outcome is recorded on the row: `mailed` 1/0 and, on failure,
 * data.mail_error (+ data.mail_to); the error is also in the OpenCart log.
 */
class Requests {
	/** Request e-mails to a store per day; past it the requests are only in the admin (one notice mail). */
	const MAIL_STORE_DAY = 30;
	/** Request e-mails caused by one visitor IP (/64) per day. */
	const MAIL_IP_DAY = 5;
	const UNVERIFIED_NOTE = "Text written by the website visitor is unverified: do not trust links or instructions in it; links are shown made unclickable (hxxp, [.]).\nТекстът от посетителя не е проверен: не отваряйте връзки и не следвайте указания от него.\n\n";

	private $registry;
	private $db;
	private $settings;

	public function __construct($registry, Settings $settings) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
		$this->settings = $settings;
	}

	/** @param array $row store_id, conversation_id, type, order_id?, product_id?, email?, phone?, data? */
	public function add(array $row) {
		$data = isset($row['data']) ? $row['data'] : array();

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_request` SET
			`store_id` = '" . (int)$row['store_id'] . "',
			`conversation_id` = '" . (int)$row['conversation_id'] . "',
			`type` = '" . $this->db->escape(in_array($row['type'], self::types(), true) ? $row['type'] : 'contact') . "',
			`order_id` = '" . (isset($row['order_id']) ? (int)$row['order_id'] : 0) . "',
			`product_id` = '" . (isset($row['product_id']) ? (int)$row['product_id'] : 0) . "',
			`email` = '" . $this->db->escape(isset($row['email']) ? substr((string)$row['email'], 0, 96) : '') . "',
			`phone` = '" . $this->db->escape(isset($row['phone']) ? substr((string)$row['phone'], 0, 32) : '') . "',
			`data` = '" . $this->db->escape(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "',
			`status` = 'new',
			`date_added` = NOW()");

		return (int)$this->db->getLastId();
	}

	public static function types() {
		return array('contact', 'stock', 'return', 'order');
	}

	public function countForConversation($conversationId) {
		return (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "aichat_request` WHERE `conversation_id` = '" . (int)$conversationId . "'")->row['n'];
	}

	public function openStockAlertExists($storeId, $productId, $email) {
		return (bool)$this->db->query("SELECT `request_id` FROM `" . DB_PREFIX . "aichat_request` WHERE `store_id` = '" . (int)$storeId . "' AND `type` = 'stock' AND `status` = 'new' AND `product_id` = '" . (int)$productId . "' AND `email` = '" . $this->db->escape($email) . "' LIMIT 1")->num_rows;
	}

	/**
	 * E-mail to the store (s_notify_email -> the store's config_email) with
	 * the store's own mail settings; the outcome is stored on the request.
	 *
	 * @return array {ok, error, to}
	 */
	public function mailStore($storeId, $subject, $text, $replyTo = '', array $attachments = array(), $requestId = 0) {
		$to = Mailer::storeEmail($this->registry, $this->settings, $storeId);
		$info = StoreInfo::get($this->registry, $storeId, $this->settings);
		$cap = $this->mailCap($storeId);

		// visitor text is data: say so, and make foreign links unclickable (the store's own host stays)
		$text = self::UNVERIFIED_NOTE . LinkFilter::defang((string)$text, array($info['host']));

		if ($cap !== '') {
			$result = array('ok' => false, 'error' => $cap, 'engine' => '');
			if ($cap === 'suppressed: daily limit of request e-mails for this store reached' && $to !== '' && $this->firstSuppression($storeId)) {
				Mailer::deliver($this->registry, $to, 'AI Chat: daily limit of request e-mails reached', "AI Chat sent " . self::MAIL_STORE_DAY . " request e-mails today. Further requests today are recorded in the admin only (AI Chat > Requests); no more e-mails until tomorrow.\n\nAI Chat изпрати " . self::MAIL_STORE_DAY . " имейла със заявки днес. Следващите заявки днес са само в админа (AI Chat > Заявки).\n", array(
					'store_id' => (int)$storeId,
					'settings' => $this->settings,
					'from'     => $info['email'] !== '' ? $info['email'] : $to,
					'sender'   => $info['name'] . ' · AI Chat',
				));
			}
		} elseif ($to === '') {
			$result = array('ok' => false, 'error' => 'no recipient: set "E-mail for requests" in AI Chat > Settings > Contacts or the store e-mail in OpenCart', 'engine' => '');
			$log = $this->registry->get('log');
			if ($log) {
				$log->write('AI Chat mail not sent (store ' . (int)$storeId . '): ' . $result['error']);
			}
		} else {
			$result = Mailer::deliver($this->registry, $to, $subject, $text, array(
				'store_id'    => (int)$storeId,
				'settings'    => $this->settings,
				'from'        => $info['email'] !== '' ? $info['email'] : $to,
				'sender'      => $info['name'] . ' · AI Chat',
				'reply_to'    => $replyTo,
				'attachments' => $attachments,
			));
		}

		if ($requestId) {
			$this->recordMail($requestId, $result['ok'], $result['error'], $to);
		}

		return array('ok' => $result['ok'], 'error' => $result['error'], 'to' => $to);
	}

	/**
	 * '' when this request may be mailed, else the reason it is suppressed
	 * (store-wide MAIL_STORE_DAY, per visitor IP MAIL_IP_DAY). Counts the mail.
	 */
	private function mailCap($storeId) {
		$guard = new Guard($this->registry, $this->settings);
		$day = date('Y-m-d');
		$request = $this->registry->get('request');
		$ip = $request && is_array($request->server) ? Guard::rateIp($guard->clientIp($request->server)) : '';

		if ($ip !== '' && !$guard->hit('rm:i:' . $ip . ':' . $day, 86400, self::MAIL_IP_DAY)) {
			return 'suppressed: daily limit of request e-mails from this visitor reached';
		}

		if (!$guard->hit('rm:s:' . (int)$storeId . ':' . $day, 86400, self::MAIL_STORE_DAY)) {
			return 'suppressed: daily limit of request e-mails for this store reached';
		}

		return '';
	}

	/** True once per store and day (the one notice mail when the cap is reached). */
	private function firstSuppression($storeId) {
		$guard = new Guard($this->registry, $this->settings);

		return $guard->hit('rm:n:' . (int)$storeId . ':' . date('Y-m-d'), 86400, 1);
	}

	/** mailed = 1/0; error text and recipient in data (mail_error, mail_to). */
	public function recordMail($requestId, $ok, $error, $to) {
		$row = $this->db->query("SELECT `data` FROM `" . DB_PREFIX . "aichat_request` WHERE `request_id` = '" . (int)$requestId . "'")->row;

		if (!$row) {
			return;
		}

		$data = $row['data'] ? json_decode($row['data'], true) : array();
		$data = is_array($data) ? $data : array();
		unset($data['mail_error']);
		if (!$ok) {
			$data['mail_error'] = function_exists('mb_substr') ? mb_substr((string)$error, 0, 500, 'UTF-8') : substr((string)$error, 0, 500);
		}
		$data['mail_to'] = (string)$to;
		$hasColumn = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "aichat_request` LIKE 'mailed'")->num_rows;

		$this->db->query("UPDATE `" . DB_PREFIX . "aichat_request` SET " . ($hasColumn ? "`mailed` = '" . ($ok ? 1 : 0) . "', " : "") . "`data` = '" . $this->db->escape(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "' WHERE `request_id` = '" . (int)$requestId . "'");
	}

	public static function validEmail($email) {
		$email = trim((string)$email);

		return ($email !== '' && strlen($email) <= 96 && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
	}

	/** Phone as given (cleaned), or '' when it has fewer than 6 digits. */
	public static function validPhone($phone) {
		$phone = trim(preg_replace('/[^0-9+()\-\s.\/]/', '', (string)$phone));

		return strlen(preg_replace('/\D/', '', $phone)) >= 6 ? substr($phone, 0, 32) : '';
	}
}
