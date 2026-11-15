<?php
namespace Aichat;

/**
 * Lazy housekeeping (no cron on shared hosting): the first storefront
 * request after the day / ISO week rolls over does the work, under a MySQL
 * named lock so it happens once.
 *
 *  - daily: retention cleanup (images after g_image_retention_days,
 *    conversations after g_retention_months, old previews, old rate rows);
 *  - weekly: report e-mail (g_report_weekly, g_report_email) for the
 *    previous ISO week, per store.
 *
 * due() is one settings read (already cached by the request), so it is
 * cheap to call on every boot.
 */
class Maintenance {
	const LOCK = 'aichat_maintenance';

	private $registry;
	private $db;
	private $settings;

	public function __construct($registry, Settings $settings) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
		$this->settings = $settings;
	}

	public function due() {
		return (string)$this->settings->getGlobal('g_cleanup_day') !== date('Y-m-d')
			|| (string)$this->settings->getGlobal('g_report_week') !== date('o-\WW');
	}

	/** Runs whatever is due. Returns array of what ran (for tests). Never throws. */
	public function run() {
		$ran = array();

		try {
			$lock = $this->db->query("SELECT GET_LOCK('" . $this->db->escape(DB_PREFIX . self::LOCK) . "', 0) AS l")->row;

			if (!$lock || (int)$lock['l'] !== 1) {
				return $ran;
			}

			$fresh = new Settings($this->registry); // re-read inside the lock

			$today = date('Y-m-d');
			if ((string)$fresh->getGlobal('g_cleanup_day') !== $today) {
				$fresh->setInternal('g_cleanup_day', $today);
				$this->cleanup();
				$ran[] = 'cleanup';
			}

			$week = date('o-\WW');
			$last = (string)$fresh->getGlobal('g_report_week');
			if ($last !== $week) {
				$fresh->setInternal('g_report_week', $week);
				// first run after install only remembers the week; reports start with the next one
				if ($last !== '' && (int)$fresh->getGlobal('g_report_weekly') === 1) {
					if ($this->sendWeeklyReport($fresh)) {
						$ran[] = 'report';
					}
				}
			}

			$this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape(DB_PREFIX . self::LOCK) . "')");
		} catch (\Exception $e) {
			$log = $this->registry->get('log');
			if ($log) {
				$log->write('AI Chat maintenance: ' . $e->getMessage());
			}
		}

		return $ran;
	}

	// ---------------------------------------------------------------- retention

	public function cleanup() {
		$uploads = new Uploads($this->registry);
		$uploads->deleteOlderThan((int)$this->settings->getGlobal('g_image_retention_days'));

		$months = (int)$this->settings->getGlobal('g_retention_months');

		if ($months > 0) {
			$old = $this->db->query("SELECT `conversation_id` FROM `" . DB_PREFIX . "aichat_conversation` WHERE `date_modified` < NOW() - INTERVAL " . $months . " MONTH ORDER BY `conversation_id` LIMIT 500")->rows;
			foreach ($old as $row) {
				self::deleteConversation($this->registry, (int)$row['conversation_id']);
			}
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "aichat_preview` WHERE `date_added` < NOW() - INTERVAL 30 DAY");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "aichat_rate` WHERE `window_start` < '" . (time() - 3 * 86400) . "'");
	}

	/** Conversation, its messages and uploads (forget / retention / admin delete). */
	public static function deleteConversation($registry, $conversationId) {
		$db = $registry->get('db');
		$uploads = new Uploads($registry);
		$uploads->deleteForConversation((int)$conversationId);
		$db->query("DELETE FROM `" . DB_PREFIX . "aichat_message` WHERE `conversation_id` = '" . (int)$conversationId . "'");
		$db->query("DELETE FROM `" . DB_PREFIX . "aichat_conversation` WHERE `conversation_id` = '" . (int)$conversationId . "'");
	}

	// ---------------------------------------------------------------- weekly report

	/** Monday..Sunday of the previous ISO week. */
	public static function previousWeek($now = null) {
		$now = $now === null ? time() : $now;
		$monday = strtotime('monday this week', $now);
		$from = date('Y-m-d', strtotime('-7 days', $monday));

		return array($from, date('Y-m-d', strtotime('-1 day', $monday)));
	}

	public function sendWeeklyReport(Settings $settings) {
		list($from, $to) = self::previousWeek();
		$text = $this->reportText($from, $to);
		$email = trim((string)$settings->getGlobal('g_report_email'));

		if ($email === '') {
			$email = trim((string)$settings->getGlobal('g_warn_email'));
		}

		if ($email === '') {
			$info = Mailer::storeConfig($this->registry, 0);
			$email = $info['email'];
		}

		return Mailer::send($this->registry, $email, 'AI Chat: weekly report ' . $from . ' – ' . $to, $text);
	}

	/** Plain-text report per store for [$from, $to] (dates, inclusive). */
	public function reportText($from, $to) {
		$p = DB_PREFIX;
		$range = "BETWEEN '" . $this->db->escape($from) . " 00:00:00' AND '" . $this->db->escape($to) . " 23:59:59'";
		$stores = array(0 => Mailer::storeConfig($this->registry, 0));

		foreach ($this->db->query("SELECT `store_id` FROM `" . $p . "store` ORDER BY `store_id`")->rows as $row) {
			$stores[(int)$row['store_id']] = Mailer::storeConfig($this->registry, (int)$row['store_id']);
		}

		$out = array("AI Chat – weekly report / седмичен отчет", $from . " – " . $to, '');

		foreach ($stores as $sid => $info) {
			if (!(int)$this->settings->getStore($sid, 's_status')) {
				$active = (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_conversation` WHERE `store_id` = '" . $sid . "' AND `date_added` " . $range)->row['n'];
				if (!$active) {
					continue;
				}
			}

			$u = $this->db->query("SELECT COALESCE(SUM(`chats`),0) AS chats, COALESCE(SUM(`messages`),0) AS messages, COALESCE(SUM(`cost`),0) AS cost FROM `" . $p . "aichat_usage` WHERE `store_id` = '" . $sid . "' AND `day` BETWEEN '" . $this->db->escape($from) . "' AND '" . $this->db->escape($to) . "'")->row;
			$handovers = (int)$this->db->query("SELECT COUNT(DISTINCT m.conversation_id) AS n FROM `" . $p . "aichat_message` m JOIN `" . $p . "aichat_conversation` c ON (c.conversation_id = m.conversation_id) WHERE c.store_id = '" . $sid . "' AND c.is_test = 0 AND m.role = 'system' AND m.date_added " . $range)->row['n'];
			$requests = $this->db->query("SELECT `type`, COUNT(*) AS n FROM `" . $p . "aichat_request` WHERE `store_id` = '" . $sid . "' AND `date_added` " . $range . " GROUP BY `type`")->rows;
			$ratings = $this->db->query("SELECT SUM(m.rating = 1) AS up, SUM(m.rating = -1) AS down FROM `" . $p . "aichat_message` m JOIN `" . $p . "aichat_conversation` c ON (c.conversation_id = m.conversation_id) WHERE c.store_id = '" . $sid . "' AND c.is_test = 0 AND m.rating <> 0 AND m.date_added " . $range)->row;
			$flagged = (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_conversation` WHERE `store_id` = '" . $sid . "' AND `is_test` = 0 AND `flagged` = 1 AND `date_modified` " . $range)->row['n'];

			$out[] = "== " . ($info['name'] !== '' ? $info['name'] : 'Store ' . $sid) . " ==";
			$out[] = "Chats: " . (int)$u['chats'] . " · messages: " . (int)$u['messages'] . " · cost: " . sprintf('%.2f', (float)$u['cost']) . " USD";
			$req = array();
			foreach ($requests as $r) {
				$req[] = $r['type'] . ' ' . (int)$r['n'];
			}
			$out[] = "Handovers: " . $handovers . " · requests: " . ($req ? implode(', ', $req) : '0') . " · ratings: +" . (int)$ratings['up'] . " / -" . (int)$ratings['down'] . " · flagged chats: " . $flagged;

			// top questions: the visitor's first message per conversation
			$first = $this->db->query("SELECT m.content FROM `" . $p . "aichat_message` m JOIN `" . $p . "aichat_conversation` c ON (c.conversation_id = m.conversation_id)"
				. " WHERE c.store_id = '" . $sid . "' AND c.is_test = 0 AND c.date_added " . $range . " AND m.role = 'user'"
				. " AND m.message_id = (SELECT MIN(m2.message_id) FROM `" . $p . "aichat_message` m2 WHERE m2.conversation_id = m.conversation_id AND m2.role = 'user') LIMIT 2000")->rows;
			$top = array();
			foreach ($first as $row) {
				$key = self::norm($row['content']);
				if ($key !== '') {
					$top[$key] = isset($top[$key]) ? $top[$key] + 1 : 1;
				}
			}
			arsort($top);
			if ($top) {
				$out[] = "Top questions:";
				foreach (array_slice($top, 0, 10, true) as $q => $n) {
					$out[] = "  " . $n . "× " . $q;
				}
			}

			// topics: tools the assistant used
			$metas = $this->db->query("SELECT m.meta FROM `" . $p . "aichat_message` m JOIN `" . $p . "aichat_conversation` c ON (c.conversation_id = m.conversation_id) WHERE c.store_id = '" . $sid . "' AND c.is_test = 0 AND m.role = 'assistant' AND m.date_added " . $range . " LIMIT 5000")->rows;
			$tools = array();
			foreach ($metas as $row) {
				$meta = json_decode((string)$row['meta'], true);
				foreach (isset($meta['tools']) && is_array($meta['tools']) ? $meta['tools'] : array() as $call) {
					if (isset($call['name'])) {
						$tools[$call['name']] = isset($tools[$call['name']]) ? $tools[$call['name']] + 1 : 1;
					}
				}
			}
			arsort($tools);
			if ($tools) {
				$parts = array();
				foreach (array_slice($tools, 0, 8, true) as $name => $n) {
					$parts[] = $name . ' ' . $n;
				}
				$out[] = "Topics (tools used): " . implode(', ', $parts);
			}

			$misses = $this->db->query("SELECT `query`, `hits` FROM `" . $p . "aichat_search_miss` WHERE `store_id` = '" . $sid . "' AND `date_modified` " . $range . " ORDER BY `hits` DESC LIMIT 10")->rows;
			if ($misses) {
				$out[] = "Searched but not found:";
				foreach ($misses as $m) {
					$out[] = "  " . (int)$m['hits'] . "× " . $m['query'];
				}
			}

			$down = $this->db->query("SELECT m.message_id, m.conversation_id, m.content FROM `" . $p . "aichat_message` m JOIN `" . $p . "aichat_conversation` c ON (c.conversation_id = m.conversation_id) WHERE c.store_id = '" . $sid . "' AND c.is_test = 0 AND m.rating = -1 AND m.date_added " . $range . " ORDER BY m.message_id DESC LIMIT 10")->rows;
			if ($down) {
				$out[] = "Down-rated answers:";
				foreach ($down as $d) {
					$out[] = "  chat #" . (int)$d['conversation_id'] . ", message #" . (int)$d['message_id'] . ": " . self::norm($d['content'], 160);
				}
			}

			$out[] = '';
		}

		return implode("\n", $out);
	}

	private static function norm($text, $max = 120) {
		$text = trim(preg_replace('/\s+/u', ' ', (string)$text));
		$text = function_exists('mb_strtolower') && $max <= 120 ? mb_strtolower($text, 'UTF-8') : $text;

		return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
	}
}
