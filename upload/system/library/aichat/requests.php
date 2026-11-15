<?php
namespace Aichat;

/**
 * Rows in aichat_request (contact | stock | return) + the e-mail to the store.
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
			`type` = '" . $this->db->escape(in_array($row['type'], array('contact', 'stock', 'return'), true) ? $row['type'] : 'contact') . "',
			`order_id` = '" . (isset($row['order_id']) ? (int)$row['order_id'] : 0) . "',
			`product_id` = '" . (isset($row['product_id']) ? (int)$row['product_id'] : 0) . "',
			`email` = '" . $this->db->escape(isset($row['email']) ? substr((string)$row['email'], 0, 96) : '') . "',
			`phone` = '" . $this->db->escape(isset($row['phone']) ? substr((string)$row['phone'], 0, 32) : '') . "',
			`data` = '" . $this->db->escape(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "',
			`status` = 'new',
			`date_added` = NOW()");

		return (int)$this->db->getLastId();
	}

	public function countForConversation($conversationId) {
		return (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "aichat_request` WHERE `conversation_id` = '" . (int)$conversationId . "'")->row['n'];
	}

	public function openStockAlertExists($storeId, $productId, $email) {
		return (bool)$this->db->query("SELECT `request_id` FROM `" . DB_PREFIX . "aichat_request` WHERE `store_id` = '" . (int)$storeId . "' AND `type` = 'stock' AND `status` = 'new' AND `product_id` = '" . (int)$productId . "' AND `email` = '" . $this->db->escape($email) . "' LIMIT 1")->num_rows;
	}

	/** E-mail to the store's address (s_contacts.email / config_email). */
	public function mailStore($storeId, $subject, $text, $replyTo = '', array $attachments = array()) {
		$to = Mailer::storeEmail($this->registry, $this->settings, $storeId);
		$info = Mailer::storeConfig($this->registry, $storeId);

		return Mailer::send($this->registry, $to, $subject, $text, array(
			'from'        => $info['email'] !== '' ? $info['email'] : $to,
			'sender'      => $info['name'] !== '' ? $info['name'] . ' · AI Chat' : 'AI Chat',
			'reply_to'    => $replyTo,
			'attachments' => $attachments,
		));
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
