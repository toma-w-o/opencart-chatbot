<?php
/**
 * AI Chat: conversations and messages (catalog side).
 */
class ModelExtensionModuleAichat extends Model {
	public function getConversation($publicId, $storeId) {
		$query = $this->db->query("SELECT c.*, (SELECT COUNT(*) FROM `" . DB_PREFIX . "aichat_message` m WHERE m.conversation_id = c.conversation_id AND m.role = 'user') AS user_messages FROM `" . DB_PREFIX . "aichat_conversation` c WHERE c.public_id = '" . $this->db->escape($publicId) . "' AND c.store_id = '" . (int)$storeId . "'");

		return $query->num_rows ? $query->row : null;
	}

	public function addConversation(array $data) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_conversation` SET
			`store_id` = '" . (int)$data['store_id'] . "',
			`language_id` = '" . (int)$data['language_id'] . "',
			`customer_id` = '" . (int)$data['customer_id'] . "',
			`public_id` = '" . $this->db->escape($data['public_id']) . "',
			`ip` = '" . $this->db->escape(substr((string)$data['ip'], 0, 45)) . "',
			`user_agent` = '" . $this->db->escape($this->cut($data['user_agent'], 255)) . "',
			`mode` = 'bot',
			`is_test` = '" . (!empty($data['is_test']) ? 1 : 0) . "',
			`first_url` = '" . $this->db->escape($this->cut($data['url'], 1000)) . "',
			`last_url` = '" . $this->db->escape($this->cut($data['url'], 1000)) . "',
			`note` = '',
			`date_added` = NOW(),
			`date_modified` = NOW()");

		return (int)$this->db->getLastId();
	}

	/** After each visitor message: url, language, customer (server side), counters. */
	public function touchConversation($conversationId, $url, $languageId, $customerId) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET
			`last_url` = '" . $this->db->escape($this->cut($url, 1000)) . "',
			`language_id` = '" . (int)$languageId . "',
			" . ((int)$customerId ? "`customer_id` = '" . (int)$customerId . "'," : "") . "
			`message_count` = `message_count` + 1,
			`date_modified` = NOW()
			WHERE `conversation_id` = '" . (int)$conversationId . "'");
	}

	public function bumpMessageCount($conversationId) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `message_count` = `message_count` + 1, `date_modified` = NOW() WHERE `conversation_id` = '" . (int)$conversationId . "'");
	}

	public function setMode($conversationId, $mode) {
		if (in_array($mode, array('bot', 'waiting', 'human', 'closed'), true)) {
			$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `mode` = '" . $this->db->escape($mode) . "', `date_modified` = NOW() WHERE `conversation_id` = '" . (int)$conversationId . "'");
		}
	}

	/** Turnstile result remembered on the conversation: 1 verified, 2 Turnstile was unavailable. */
	public function setHumanVerified($conversationId, $state) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `human_verified` = '" . (int)$state . "' WHERE `conversation_id` = '" . (int)$conversationId . "'");
	}

	/** Privacy notice accepted (s_privacy_accept); the first acceptance is kept. */
	public function setPrivacyAccepted($conversationId) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `privacy_accepted_at` = NOW() WHERE `conversation_id` = '" . (int)$conversationId . "' AND `privacy_accepted_at` IS NULL");
	}

	public function addMessage($conversationId, $role, $content, $meta = null, $inputTokens = 0, $outputTokens = 0, $cost = 0) {
		$json = $meta === null ? 'NULL' : "'" . $this->db->escape(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0))) . "'";

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_message` SET
			`conversation_id` = '" . (int)$conversationId . "',
			`role` = '" . $this->db->escape($role) . "',
			`content` = '" . $this->db->escape((string)$content) . "',
			`meta` = " . $json . ",
			`input_tokens` = '" . (int)$inputTokens . "',
			`output_tokens` = '" . (int)$outputTokens . "',
			`cost` = '" . sprintf('%.6F', (float)$cost) . "',
			`date_added` = NOW()");

		return (int)$this->db->getLastId();
	}

	/** Last $limit messages in chronological order (meta decoded). */
	public function getMessages($conversationId, $limit = 50, array $roles = array('user', 'assistant', 'staff')) {
		$in = array();

		foreach ($roles as $role) {
			$in[] = "'" . $this->db->escape($role) . "'";
		}

		$query = $this->db->query("SELECT `message_id`, `role`, `content`, `meta`, `rating`, `date_added` FROM `" . DB_PREFIX . "aichat_message` WHERE `conversation_id` = '" . (int)$conversationId . "' AND `role` IN (" . implode(',', $in) . ") ORDER BY `message_id` DESC LIMIT " . (int)$limit);

		$rows = array_reverse($query->rows);

		foreach ($rows as $i => $row) {
			$rows[$i]['meta'] = $row['meta'] ? json_decode($row['meta'], true) : array();
		}

		return $rows;
	}

	/** Widget poll: staff / assistant / system messages after $afterId. */
	public function getMessagesAfter($conversationId, $afterId, $limit = 50) {
		$query = $this->db->query("SELECT `message_id`, `role`, `content`, `meta` FROM `" . DB_PREFIX . "aichat_message` WHERE `conversation_id` = '" . (int)$conversationId . "' AND `message_id` > '" . (int)$afterId . "' AND `role` IN ('staff', 'assistant', 'system') ORDER BY `message_id` ASC LIMIT " . (int)$limit);
		$rows = $query->rows;

		foreach ($rows as $i => $row) {
			$rows[$i]['meta'] = $row['meta'] ? json_decode($row['meta'], true) : array();
		}

		return $rows;
	}

	/** Rating of an assistant message of this conversation; false when it is not one. */
	public function rateMessage($conversationId, $messageId, $value) {
		$row = $this->db->query("SELECT `message_id` FROM `" . DB_PREFIX . "aichat_message` WHERE `message_id` = '" . (int)$messageId . "' AND `conversation_id` = '" . (int)$conversationId . "' AND `role` = 'assistant'")->row;

		if (!$row) {
			return false;
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "aichat_message` SET `rating` = '" . (int)$value . "' WHERE `message_id` = '" . (int)$messageId . "'");

		return true;
	}

	/** E-mail of an order of this store (transcript target after order verification). */
	public function getOrderEmail($orderId, $storeId) {
		$query = $this->db->query("SELECT `email` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$orderId . "' AND `store_id` = '" . (int)$storeId . "' AND `order_status_id` > '0'");

		return $query->num_rows ? trim((string)$query->row['email']) : '';
	}

	/** Upload row with its conversation's store (signed image URLs). */
	public function getUploadWithStore($uploadId) {
		$query = $this->db->query("SELECT u.*, c.store_id FROM `" . DB_PREFIX . "aichat_upload` u JOIN `" . DB_PREFIX . "aichat_conversation` c ON (c.conversation_id = u.conversation_id) WHERE u.upload_id = '" . (int)$uploadId . "'");

		return $query->num_rows ? $query->row : null;
	}

	public function getPreview($previewId) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aichat_preview` WHERE `preview_id` = '" . (int)$previewId . "' AND `date_added` > NOW() - INTERVAL 1 DAY");

		if (!$query->num_rows) {
			return null;
		}

		$row = $query->row;
		$row['data'] = $row['data'] ? json_decode($row['data'], true) : array();
		$row['data'] = is_array($row['data']) ? $row['data'] : array();

		return $row;
	}

	private function cut($text, $max) {
		$text = (string)$text;

		return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
	}
}
