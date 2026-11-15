<?php
/**
 * Reporting queries for the AI Chat admin pages. Reads only the aichat_* tables.
 */
class ModelExtensionModuleAichat extends Model {
	private function t($name) {
		return '`' . DB_PREFIX . 'aichat_' . $name . '`';
	}

	private function like($v) {
		return $this->db->escape(addcslashes((string)$v, '%_\\'));
	}

	private function storeWhere($column, $storeId) {
		return ($storeId === null || $storeId === '' || (int)$storeId < 0) ? '' : " AND " . $column . " = '" . (int)$storeId . "'";
	}

	// ---- dashboard ----

	/** Rows per store and day from the usage table. */
	public function getUsage($storeId, $fromDay) {
		$sql = "SELECT `store_id`, `day`, `chats`, `messages`, `input_tokens`, `output_tokens`, `cost` FROM " . $this->t('usage') . " WHERE `day` >= '" . $this->db->escape($fromDay) . "'" . $this->storeWhere('`store_id`', $storeId) . " ORDER BY `day`";

		return $this->db->query($sql)->rows;
	}

	public function getMonthSpend($storeId) {
		$sql = "SELECT SUM(`cost`) AS total FROM " . $this->t('usage') . " WHERE `day` >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" . $this->storeWhere('`store_id`', $storeId);
		$row = $this->db->query($sql)->row;

		return $row ? (float)$row['total'] : 0.0;
	}

	public function countActive($storeId, $minutes) {
		$sql = "SELECT COUNT(*) AS total FROM " . $this->t('conversation') . " WHERE `date_modified` >= (NOW() - INTERVAL " . (int)$minutes . " MINUTE)" . $this->storeWhere('`store_id`', $storeId);

		return (int)$this->db->query($sql)->row['total'];
	}

	public function countFlagged($storeId, $fromDay) {
		$sql = "SELECT COUNT(*) AS total FROM " . $this->t('conversation') . " WHERE `flagged` = '1' AND `date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->storeWhere('`store_id`', $storeId);

		return (int)$this->db->query($sql)->row['total'];
	}

	public function countHandover($storeId, $fromDay) {
		$sql = "SELECT COUNT(*) AS total FROM " . $this->t('conversation') . " WHERE `mode` <> 'bot' AND `date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->storeWhere('`store_id`', $storeId);

		return (int)$this->db->query($sql)->row['total'];
	}

	/** Customer messages per hour of day (0-23). */
	public function getHourly($storeId, $fromDay) {
		$sql = "SELECT HOUR(m.`date_added`) AS h, COUNT(*) AS total FROM " . $this->t('message') . " m JOIN " . $this->t('conversation') . " c ON c.`conversation_id` = m.`conversation_id` WHERE m.`role` = 'user' AND m.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->storeWhere('c.`store_id`', $storeId) . " GROUP BY h";
		$out = array_fill(0, 24, 0);

		foreach ($this->db->query($sql)->rows as $row) {
			$out[(int)$row['h']] = (int)$row['total'];
		}

		return $out;
	}

	/** Raw meta of the latest messages in range (decoded by the caller). */
	public function getMetaRows($storeId, $fromDay, $limit) {
		$sql = "SELECT m.`role`, m.`meta` FROM " . $this->t('message') . " m JOIN " . $this->t('conversation') . " c ON c.`conversation_id` = m.`conversation_id` WHERE m.`meta` IS NOT NULL AND m.`role` IN ('user', 'assistant') AND m.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->storeWhere('c.`store_id`', $storeId) . " ORDER BY m.`message_id` DESC LIMIT " . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	public function getLive($storeId, $minutes, $limit) {
		$sql = "SELECT c.`conversation_id`, c.`store_id`, c.`language_id`, c.`mode`, c.`last_url`, c.`first_url`, c.`message_count`, c.`flagged`, c.`date_modified`, TIMESTAMPDIFF(SECOND, c.`date_modified`, NOW()) AS ago,
			(SELECT m.`content` FROM " . $this->t('message') . " m WHERE m.`conversation_id` = c.`conversation_id` AND m.`role` IN ('user', 'staff') ORDER BY m.`message_id` DESC LIMIT 1) AS last_message
			FROM " . $this->t('conversation') . " c WHERE c.`date_modified` >= (NOW() - INTERVAL " . (int)$minutes . " MINUTE)" . $this->storeWhere('c.`store_id`', $storeId) . " ORDER BY c.`date_modified` DESC LIMIT " . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	// ---- conversations ----

	private function listWhere($f) {
		$w = array('1');

		if (isset($f['store_id']) && $f['store_id'] !== '') {
			$w[] = "c.`store_id` = '" . (int)$f['store_id'] . "'";
		}
		if (!empty($f['language_id'])) {
			$w[] = "c.`language_id` = '" . (int)$f['language_id'] . "'";
		}
		if (!empty($f['date_from'])) {
			$w[] = "c.`date_added` >= '" . $this->db->escape($f['date_from']) . " 00:00:00'";
		}
		if (!empty($f['date_to'])) {
			$w[] = "c.`date_added` <= '" . $this->db->escape($f['date_to']) . " 23:59:59'";
		}
		if (isset($f['has_order']) && $f['has_order'] !== '') {
			$w[] = $f['has_order'] ? "c.`verified_order_id` > 0" : "c.`verified_order_id` = 0";
		}
		if (isset($f['flagged']) && $f['flagged'] !== '') {
			$w[] = "c.`flagged` = '" . ($f['flagged'] ? 1 : 0) . "'";
		}
		if (!empty($f['q'])) {
			$q = $this->like($f['q']);
			$w[] = "(c.`public_id` LIKE '%" . $q . "%' OR c.`first_url` LIKE '%" . $q . "%' OR c.`note` LIKE '%" . $q . "%' OR EXISTS (SELECT 1 FROM " . $this->t('message') . " m WHERE m.`conversation_id` = c.`conversation_id` AND m.`content` LIKE '%" . $q . "%'))";
		}

		return implode(' AND ', $w);
	}

	public function getConversations($f, $start, $limit) {
		$sql = "SELECT c.`conversation_id`, c.`store_id`, c.`language_id`, c.`customer_id`, c.`mode`, c.`verified_order_id`, c.`message_count`, c.`cost`, c.`input_tokens`, c.`output_tokens`, c.`flagged`, c.`first_url`, c.`date_added`, c.`date_modified`,
			(SELECT m.`content` FROM " . $this->t('message') . " m WHERE m.`conversation_id` = c.`conversation_id` AND m.`role` = 'user' ORDER BY m.`message_id` LIMIT 1) AS first_message
			FROM " . $this->t('conversation') . " c WHERE " . $this->listWhere($f) . " ORDER BY c.`conversation_id` DESC LIMIT " . (int)$start . "," . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	public function getTotalConversations($f) {
		return (int)$this->db->query("SELECT COUNT(*) AS total FROM " . $this->t('conversation') . " c WHERE " . $this->listWhere($f))->row['total'];
	}

	public function getConversation($id) {
		$q = $this->db->query("SELECT * FROM " . $this->t('conversation') . " WHERE `conversation_id` = '" . (int)$id . "'");

		return $q->row;
	}

	public function getMessages($id) {
		return $this->db->query("SELECT `message_id`, `role`, `content`, `meta`, `input_tokens`, `output_tokens`, `cost`, `date_added` FROM " . $this->t('message') . " WHERE `conversation_id` = '" . (int)$id . "' ORDER BY `message_id`")->rows;
	}

	/**
	 * Flag (or unflag) one message. The flag lives in the message meta JSON under "flag".
	 * Keeps conversation.flagged = "any message flagged".
	 */
	public function setMessageFlag($messageId, $flagged, $note) {
		$q = $this->db->query("SELECT `conversation_id`, `meta` FROM " . $this->t('message') . " WHERE `message_id` = '" . (int)$messageId . "'");

		if (!$q->num_rows) {
			return false;
		}

		$meta = $q->row['meta'] ? json_decode($q->row['meta'], true) : array();
		$meta = is_array($meta) ? $meta : array();

		if ($flagged) {
			$meta['flag'] = array('note' => $note, 'by' => (int)$this->user->getId(), 'at' => date('Y-m-d H:i:s'));
		} else {
			unset($meta['flag']);
		}

		$json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
		$this->db->query("UPDATE " . $this->t('message') . " SET `meta` = " . ($json === '' ? 'NULL' : "'" . $this->db->escape($json) . "'") . " WHERE `message_id` = '" . (int)$messageId . "'");

		$cid = (int)$q->row['conversation_id'];
		$any = $this->db->query("SELECT 1 FROM " . $this->t('message') . " WHERE `conversation_id` = '" . $cid . "' AND `meta` LIKE '%\"flag\":{%' LIMIT 1")->num_rows;
		$this->db->query("UPDATE " . $this->t('conversation') . " SET `flagged` = '" . ($any ? 1 : 0) . "' WHERE `conversation_id` = '" . $cid . "'");

		return true;
	}

	public function setConversationFlag($id, $flagged, $note) {
		$this->db->query("UPDATE " . $this->t('conversation') . " SET `flagged` = '" . ($flagged ? 1 : 0) . "', `note` = '" . $this->db->escape($note) . "' WHERE `conversation_id` = '" . (int)$id . "'");
	}
}
