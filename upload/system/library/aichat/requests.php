<?php
namespace Aichat;

/**
 * Rows in aichat_request (contact | stock | return | order) + the e-mail to the store.
 *
 * The e-mail outcome is recorded on the row: `mailed` 1/0 and, on failure,
 * data.mail_error (+ data.mail_to); the error is also in the OpenCart log.
 */
class Requests {
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

		if ($to === '') {
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
