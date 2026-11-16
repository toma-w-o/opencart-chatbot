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

	private $schemaCache = array();

	/** Round 3 tables/columns may not exist yet (upgrade pending): pages check before querying. */
	public function hasTable($name) {
		$key = 't:' . $name;

		if (!isset($this->schemaCache[$key])) {
			$this->schemaCache[$key] = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'aichat_' . $name) . "'")->num_rows > 0;
		}

		return $this->schemaCache[$key];
	}

	public function hasColumn($table, $column) {
		$key = 'c:' . $table . '.' . $column;

		if (!isset($this->schemaCache[$key])) {
			$this->schemaCache[$key] = $this->hasTable($table) && $this->db->query("SHOW COLUMNS FROM " . $this->t($table) . " LIKE '" . $this->db->escape($column) . "'")->num_rows > 0;
		}

		return $this->schemaCache[$key];
	}

	/** " AND c.is_test = 0" once the column exists (statistics never count admin test chats). */
	private function noTest($alias = 'c') {
		return $this->hasColumn('conversation', 'is_test') ? " AND " . $alias . ".`is_test` = '0'" : '';
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
		$sql = "SELECT COUNT(*) AS total FROM " . $this->t('conversation') . " c WHERE c.`date_modified` >= (NOW() - INTERVAL " . (int)$minutes . " MINUTE)" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId);

		return (int)$this->db->query($sql)->row['total'];
	}

	public function countFlagged($storeId, $fromDay) {
		$sql = "SELECT COUNT(*) AS total FROM " . $this->t('conversation') . " c WHERE c.`flagged` = '1' AND c.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId);

		return (int)$this->db->query($sql)->row['total'];
	}

	public function countHandover($storeId, $fromDay) {
		$sql = "SELECT COUNT(*) AS total FROM " . $this->t('conversation') . " c WHERE c.`mode` <> 'bot' AND c.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId);

		return (int)$this->db->query($sql)->row['total'];
	}

	/** Customer messages per hour of day (0-23). */
	public function getHourly($storeId, $fromDay) {
		$sql = "SELECT HOUR(m.`date_added`) AS h, COUNT(*) AS total FROM " . $this->t('message') . " m JOIN " . $this->t('conversation') . " c ON c.`conversation_id` = m.`conversation_id` WHERE m.`role` = 'user' AND m.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId) . " GROUP BY h";
		$out = array_fill(0, 24, 0);

		foreach ($this->db->query($sql)->rows as $row) {
			$out[(int)$row['h']] = (int)$row['total'];
		}

		return $out;
	}

	/** Raw meta of the latest messages in range (decoded by the caller). */
	public function getMetaRows($storeId, $fromDay, $limit) {
		$sql = "SELECT m.`role`, m.`meta` FROM " . $this->t('message') . " m JOIN " . $this->t('conversation') . " c ON c.`conversation_id` = m.`conversation_id` WHERE m.`meta` IS NOT NULL AND m.`role` IN ('user', 'assistant') AND m.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId) . " ORDER BY m.`message_id` DESC LIMIT " . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	public function getLive($storeId, $minutes, $limit) {
		$sql = "SELECT c.`conversation_id`, c.`store_id`, c.`language_id`, c.`mode`, c.`last_url`, c.`first_url`, c.`message_count`, c.`flagged`, c.`date_modified`, TIMESTAMPDIFF(SECOND, c.`date_modified`, NOW()) AS ago,
			(SELECT m.`content` FROM " . $this->t('message') . " m WHERE m.`conversation_id` = c.`conversation_id` AND m.`role` IN ('user', 'staff') ORDER BY m.`message_id` DESC LIMIT 1) AS last_message
			FROM " . $this->t('conversation') . " c WHERE c.`date_modified` >= (NOW() - INTERVAL " . (int)$minutes . " MINUTE)" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId) . " ORDER BY c.`date_modified` DESC LIMIT " . (int)$limit;

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
		// test chats (admin playground): hidden by default, "1" = only tests, "all" = both
		if ($this->hasColumn('conversation', 'is_test')) {
			$test = isset($f['test']) ? (string)$f['test'] : '';
			if ($test === '1') {
				$w[] = "c.`is_test` = '1'";
			} elseif ($test !== 'all') {
				$w[] = "c.`is_test` = '0'";
			}
		}
		if (isset($f['rating']) && $f['rating'] !== '' && $this->hasColumn('message', 'rating')) {
			$w[] = "EXISTS (SELECT 1 FROM " . $this->t('message') . " r WHERE r.`conversation_id` = c.`conversation_id` AND r.`rating` = '" . ((int)$f['rating'] < 0 ? -1 : 1) . "')";
		}
		if (!empty($f['q'])) {
			$q = $this->like($f['q']);
			$w[] = "(c.`public_id` LIKE '%" . $q . "%' OR c.`first_url` LIKE '%" . $q . "%' OR c.`note` LIKE '%" . $q . "%' OR EXISTS (SELECT 1 FROM " . $this->t('message') . " m WHERE m.`conversation_id` = c.`conversation_id` AND m.`content` LIKE '%" . $q . "%'))";
		}

		return implode(' AND ', $w);
	}

	public function getConversations($f, $start, $limit) {
		$test = $this->hasColumn('conversation', 'is_test') ? "c.`is_test`" : "0";
		$up = $this->hasColumn('message', 'rating') ? "(SELECT COUNT(*) FROM " . $this->t('message') . " r WHERE r.`conversation_id` = c.`conversation_id` AND r.`rating` = '1')" : "0";
		$down = $this->hasColumn('message', 'rating') ? "(SELECT COUNT(*) FROM " . $this->t('message') . " r WHERE r.`conversation_id` = c.`conversation_id` AND r.`rating` = '-1')" : "0";

		$sql = "SELECT c.`conversation_id`, c.`store_id`, c.`language_id`, c.`customer_id`, c.`mode`, c.`verified_order_id`, c.`message_count`, c.`cost`, c.`input_tokens`, c.`output_tokens`, c.`flagged`, c.`first_url`, c.`date_added`, c.`date_modified`, " . $test . " AS is_test, " . $up . " AS rating_up, " . $down . " AS rating_down,
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
		$rating = $this->hasColumn('message', 'rating') ? '`rating`' : "0 AS `rating`";

		return $this->db->query("SELECT `message_id`, `role`, `content`, `meta`, `input_tokens`, `output_tokens`, `cost`, " . $rating . ", `date_added` FROM " . $this->t('message') . " WHERE `conversation_id` = '" . (int)$id . "' ORDER BY `message_id`")->rows;
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

	public function getMessage($messageId) {
		$rating = $this->hasColumn('message', 'rating') ? '`rating`' : "0 AS `rating`";

		return $this->db->query("SELECT `message_id`, `conversation_id`, `role`, `content`, `meta`, `input_tokens`, `output_tokens`, `cost`, " . $rating . ", `date_added` FROM " . $this->t('message') . " WHERE `message_id` = '" . (int)$messageId . "'")->row;
	}

	/** All rows for the CSV export (same filters as the list), capped. */
	public function getConversationsForExport($f, $limit) {
		return $this->getConversations($f, 0, $limit);
	}

	/** Deletes the conversation with its messages; upload files go through \Aichat\Uploads by the controller. */
	public function deleteConversation($id) {
		$id = (int)$id;
		$this->db->query("DELETE FROM " . $this->t('message') . " WHERE `conversation_id` = '" . $id . "'");
		$this->db->query("DELETE FROM " . $this->t('conversation') . " WHERE `conversation_id` = '" . $id . "'");
	}

	public function getUploadIds($conversationId) {
		if (!$this->hasTable('upload')) {
			return array();
		}

		$ids = array();
		foreach ($this->db->query("SELECT `upload_id` FROM " . $this->t('upload') . " WHERE `conversation_id` = '" . (int)$conversationId . "'")->rows as $row) {
			$ids[] = (int)$row['upload_id'];
		}

		return $ids;
	}

	// ---- dashboard additions (round 3) ----

	/** array(up, down) of visitor ratings on answers of real conversations in range. */
	public function getRatings($storeId, $fromDay) {
		$out = array('up' => 0, 'down' => 0);

		if (!$this->hasColumn('message', 'rating')) {
			return $out;
		}

		$sql = "SELECT SUM(m.`rating` = 1) AS up, SUM(m.`rating` = -1) AS down FROM " . $this->t('message') . " m JOIN " . $this->t('conversation') . " c ON c.`conversation_id` = m.`conversation_id` WHERE m.`rating` <> 0 AND m.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId);
		$row = $this->db->query($sql)->row;

		return array('up' => $row ? (int)$row['up'] : 0, 'down' => $row ? (int)$row['down'] : 0);
	}

	/** array(taken, missed): staff took a waiting chat / nobody took it within the timeout. */
	public function getHandoverOutcome($storeId, $fromDay) {
		$out = array('taken' => 0, 'missed' => 0);
		$sql = "SELECT SUM(m.`meta` LIKE '%\"handover\":\"take\"%') AS taken, SUM(m.`meta` LIKE '%\"handover\":\"timeout\"%') AS missed FROM " . $this->t('message') . " m JOIN " . $this->t('conversation') . " c ON c.`conversation_id` = m.`conversation_id` WHERE m.`role` IN ('system', 'assistant') AND m.`meta` LIKE '%\"handover\"%' AND m.`date_added` >= '" . $this->db->escape($fromDay) . "'" . $this->noTest() . $this->storeWhere('c.`store_id`', $storeId);
		$row = $this->db->query($sql)->row;

		if ($row) {
			$out = array('taken' => (int)$row['taken'], 'missed' => (int)$row['missed']);
		}

		return $out;
	}

	public function countOpenRequests($storeId = null) {
		if (!$this->hasTable('request')) {
			return 0;
		}

		return (int)$this->db->query("SELECT COUNT(*) AS total FROM " . $this->t('request') . " r WHERE r.`status` = 'new'" . $this->storeWhere('r.`store_id`', $storeId))->row['total'];
	}

	/** Top "searched but not found" queries in range. */
	public function getSearchMisses($storeId, $fromDay, $limit) {
		if (!$this->hasTable('search_miss')) {
			return array();
		}

		$sql = "SELECT `query`, SUM(`hits`) AS hits, MAX(`date_modified`) AS last FROM " . $this->t('search_miss') . " WHERE `date_modified` >= '" . $this->db->escape($fromDay) . "'" . $this->storeWhere('`store_id`', $storeId) . " GROUP BY `query` ORDER BY hits DESC, last DESC LIMIT " . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	// ---- knowledge base ----

	private function kbWhere($f) {
		$w = array('1');

		if (isset($f['store_id']) && $f['store_id'] !== '') {
			$w[] = "k.`store_id` = '" . (int)$f['store_id'] . "'";
		}
		if (isset($f['language_id']) && $f['language_id'] !== '') {
			$w[] = "k.`language_id` = '" . (int)$f['language_id'] . "'";
		}
		if (isset($f['status']) && $f['status'] !== '') {
			$w[] = "k.`status` = '" . ((int)$f['status'] ? 1 : 0) . "'";
		}
		if (!empty($f['q'])) {
			$q = $this->like($f['q']);
			$w[] = "(k.`question` LIKE '%" . $q . "%' OR k.`answer` LIKE '%" . $q . "%')";
		}

		return implode(' AND ', $w);
	}

	public function getKbList($f, $start, $limit) {
		return $this->db->query("SELECT k.* FROM " . $this->t('kb') . " k WHERE " . $this->kbWhere($f) . " ORDER BY k.`kb_id` DESC LIMIT " . (int)$start . "," . (int)$limit)->rows;
	}

	public function getKbTotal($f) {
		return (int)$this->db->query("SELECT COUNT(*) AS total FROM " . $this->t('kb') . " k WHERE " . $this->kbWhere($f))->row['total'];
	}

	public function getKb($id) {
		return $this->db->query("SELECT * FROM " . $this->t('kb') . " WHERE `kb_id` = '" . (int)$id . "'")->row;
	}

	/** kb_id => true for the given source messages (transcript: "already saved"). */
	public function getKbBySources(array $messageIds) {
		$ids = array_values(array_filter(array_map('intval', $messageIds)));
		$out = array();

		if (!$ids || !$this->hasTable('kb')) {
			return $out;
		}

		foreach ($this->db->query("SELECT `kb_id`, `source_message_id` FROM " . $this->t('kb') . " WHERE `source_message_id` IN (" . implode(',', $ids) . ") ORDER BY `kb_id`")->rows as $row) {
			$out[(int)$row['source_message_id']] = (int)$row['kb_id'];
		}

		return $out;
	}

	public function saveKb($id, array $d) {
		$set = "`store_id` = '" . (int)$d['store_id'] . "', `language_id` = '" . (int)$d['language_id'] . "', `question` = '" . $this->db->escape($d['question']) . "', `answer` = '" . $this->db->escape($d['answer']) . "', `status` = '" . ((int)$d['status'] ? 1 : 0) . "'";

		if ((int)$id) {
			$this->db->query("UPDATE " . $this->t('kb') . " SET " . $set . " WHERE `kb_id` = '" . (int)$id . "'");

			return (int)$id;
		}

		$this->db->query("INSERT INTO " . $this->t('kb') . " SET " . $set . ", `source_message_id` = '" . (int)$d['source_message_id'] . "', `date_added` = NOW()");

		return (int)$this->db->getLastId();
	}

	public function setKbStatus($id, $status) {
		$this->db->query("UPDATE " . $this->t('kb') . " SET `status` = '" . ($status ? 1 : 0) . "' WHERE `kb_id` = '" . (int)$id . "'");
	}

	public function deleteKb($id) {
		$this->db->query("DELETE FROM " . $this->t('kb') . " WHERE `kb_id` = '" . (int)$id . "'");
	}

	// ---- requests ----

	private function requestWhere($f) {
		$w = array('1');

		if (!empty($f['type']) && in_array($f['type'], array('contact', 'stock', 'return', 'order'), true)) {
			$w[] = "r.`type` = '" . $this->db->escape($f['type']) . "'";
		}
		if (!empty($f['status']) && in_array($f['status'], array('new', 'done'), true)) {
			$w[] = "r.`status` = '" . $this->db->escape($f['status']) . "'";
		}
		if (isset($f['store_id']) && $f['store_id'] !== '') {
			$w[] = "r.`store_id` = '" . (int)$f['store_id'] . "'";
		}
		if (!empty($f['mail_failed']) && $this->hasColumn('request', 'mailed')) {
			$w[] = "r.`mailed` = '0'";
		}

		return implode(' AND ', $w);
	}

	public function getRequests($f, $start, $limit) {
		return $this->db->query("SELECT r.* FROM " . $this->t('request') . " r WHERE " . $this->requestWhere($f) . " ORDER BY (r.`status` = 'new') DESC, r.`request_id` DESC LIMIT " . (int)$start . "," . (int)$limit)->rows;
	}

	public function getRequestsTotal($f) {
		return (int)$this->db->query("SELECT COUNT(*) AS total FROM " . $this->t('request') . " r WHERE " . $this->requestWhere($f))->row['total'];
	}

	public function getRequest($id) {
		return $this->db->query("SELECT * FROM " . $this->t('request') . " WHERE `request_id` = '" . (int)$id . "'")->row;
	}

	public function setRequestStatus($id, $status) {
		$this->db->query("UPDATE " . $this->t('request') . " SET `status` = '" . ($status === 'done' ? 'done' : 'new') . "' WHERE `request_id` = '" . (int)$id . "'");
	}

	public function getProductName($productId, $languageId) {
		$q = $this->db->query("SELECT `name` FROM `" . DB_PREFIX . "product_description` WHERE `product_id` = '" . (int)$productId . "' AND `language_id` = '" . (int)$languageId . "'");

		return $q->num_rows ? $q->row['name'] : '';
	}

	public function conversationExists($id) {
		return (bool)$this->db->query("SELECT 1 FROM " . $this->t('conversation') . " WHERE `conversation_id` = '" . (int)$id . "'")->num_rows;
	}

	// ---- test chat ----

	public function savePreview($previewId, $storeId, $url, array $data) {
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ((int)$previewId && $this->db->query("SELECT 1 FROM " . $this->t('preview') . " WHERE `preview_id` = '" . (int)$previewId . "' AND `store_id` = '" . (int)$storeId . "' AND `date_added` > NOW() - INTERVAL 20 HOUR")->num_rows) {
			// date_added stays: the catalog derives the test conversation's id from it
			$this->db->query("UPDATE " . $this->t('preview') . " SET `url` = '" . $this->db->escape($url) . "', `data` = '" . $this->db->escape($json) . "' WHERE `preview_id` = '" . (int)$previewId . "'");

			return (int)$previewId;
		}

		$this->db->query("INSERT INTO " . $this->t('preview') . " SET `store_id` = '" . (int)$storeId . "', `url` = '" . $this->db->escape($url) . "', `data` = '" . $this->db->escape($json) . "', `date_added` = NOW()");

		return (int)$this->db->getLastId();
	}

	public function getPreviewDate($previewId) {
		$q = $this->db->query("SELECT `date_added` FROM " . $this->t('preview') . " WHERE `preview_id` = '" . (int)$previewId . "'");

		return $q->num_rows ? (string)$q->row['date_added'] : '';
	}

	public function getConversationByPublicId($publicId) {
		return $this->db->query("SELECT * FROM " . $this->t('conversation') . " WHERE `public_id` = '" . $this->db->escape($publicId) . "'")->row;
	}
}
