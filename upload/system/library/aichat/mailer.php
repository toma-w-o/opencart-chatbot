<?php
namespace Aichat;

/**
 * Plain-text e-mail through OpenCart's Mail class.
 *
 * Test hook: with the constant AICHAT_MAIL_FILE defined the mail is appended
 * to that file ("To:", "Subject:", "Reply-To:", "Attachment:" lines, blank
 * line, body, "----") instead of being sent.
 */
class Mailer {
	/**
	 * @param object $registry
	 * @param string $to          recipient (validated)
	 * @param string $subject
	 * @param string $text        plain text body
	 * @param array  $options     from, sender, reply_to, attachments (absolute paths), store_id (sender settings)
	 * @return bool
	 */
	public static function send($registry, $to, $subject, $text, array $options = array()) {
		$to = trim((string)$to);

		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return false;
		}

		$config = $registry->get('config');
		$from = isset($options['from']) ? trim((string)$options['from']) : trim((string)$config->get('config_email'));
		$sender = isset($options['sender']) ? (string)$options['sender'] : html_entity_decode((string)$config->get('config_name'), ENT_QUOTES, 'UTF-8');
		$replyTo = isset($options['reply_to']) ? trim((string)$options['reply_to']) : '';
		$attachments = isset($options['attachments']) && is_array($options['attachments']) ? $options['attachments'] : array();
		$subject = self::oneLine($subject);

		if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
			$replyTo = '';
		}

		if (defined('AICHAT_MAIL_FILE')) {
			$head = "To: " . $to . "\nSubject: " . $subject . "\n";
			if ($replyTo !== '') {
				$head .= "Reply-To: " . $replyTo . "\n";
			}
			foreach ($attachments as $file) {
				$head .= "Attachment: " . basename((string)$file) . (is_file($file) ? ' (' . filesize($file) . ' bytes)' : ' (missing)') . "\n";
			}
			return @file_put_contents((string)constant('AICHAT_MAIL_FILE'), $head . "\n" . $text . "\n----\n", FILE_APPEND | LOCK_EX) !== false;
		}

		if (!class_exists('Mail')) {
			return false;
		}

		try {
			$mail = new \Mail($config->get('config_mail_engine'));
			$mail->parameter = $config->get('config_mail_parameter');
			$mail->smtp_hostname = $config->get('config_mail_smtp_hostname');
			$mail->smtp_username = $config->get('config_mail_smtp_username');
			$mail->smtp_password = html_entity_decode((string)$config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
			$mail->smtp_port = $config->get('config_mail_smtp_port');
			$mail->smtp_timeout = $config->get('config_mail_smtp_timeout');
			$mail->setTo($to);
			$mail->setFrom($from !== '' ? $from : $to);
			$mail->setSender(self::oneLine($sender));
			if ($replyTo !== '') {
				$mail->setReplyTo($replyTo);
			}
			$mail->setSubject($subject);
			$mail->setText($text);
			foreach ($attachments as $file) {
				if (is_file($file)) {
					$mail->addAttachment($file);
				}
			}
			$mail->send();
		} catch (\Exception $e) {
			$log = $registry->get('log');
			if ($log) {
				$log->write('AI Chat mail: ' . $e->getMessage());
			}
			return false;
		}

		return true;
	}

	/**
	 * The store's e-mail for requests and transcripts: s_contacts.email, else
	 * the store's config_email (read from the database, so it works for any store).
	 */
	public static function storeEmail($registry, Settings $settings, $storeId) {
		$contacts = $settings->getStore($storeId, 's_contacts');

		if (is_array($contacts) && !empty($contacts['email']) && filter_var(trim($contacts['email']), FILTER_VALIDATE_EMAIL)) {
			return trim($contacts['email']);
		}

		$info = self::storeConfig($registry, $storeId);

		return $info['email'];
	}

	/** {name, email, url} of a store from its `config` settings. */
	public static function storeConfig($registry, $storeId) {
		$db = $registry->get('db');
		$out = array('name' => '', 'email' => '', 'url' => '');
		$rows = $db->query("SELECT `store_id`, `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` IN ('0', '" . (int)$storeId . "') AND `code` = 'config' AND `key` IN ('config_email', 'config_name', 'config_url') ORDER BY `store_id` ASC")->rows;

		foreach ($rows as $row) {
			// store-specific values override store 0
			$key = substr($row['key'], 7);
			if ((int)$row['store_id'] === (int)$storeId || $out[$key] === '') {
				$out[$key] = html_entity_decode(trim((string)$row['value']), ENT_QUOTES, 'UTF-8');
			}
		}

		if ((int)$storeId > 0) {
			$store = $db->query("SELECT `name`, `url` FROM `" . DB_PREFIX . "store` WHERE `store_id` = '" . (int)$storeId . "'")->row;
			if ($store) {
				$out['name'] = $out['name'] !== '' && self::hasOwn($rows, $storeId, 'config_name') ? $out['name'] : html_entity_decode((string)$store['name'], ENT_QUOTES, 'UTF-8');
				$out['url'] = self::hasOwn($rows, $storeId, 'config_url') ? $out['url'] : (string)$store['url'];
			}
		} elseif (defined('HTTP_CATALOG') && $out['url'] === '') {
			$out['url'] = HTTP_CATALOG;
		} elseif (defined('HTTP_SERVER') && $out['url'] === '') {
			$out['url'] = HTTP_SERVER;
		}

		return $out;
	}

	private static function hasOwn(array $rows, $storeId, $key) {
		foreach ($rows as $row) {
			if ((int)$row['store_id'] === (int)$storeId && $row['key'] === $key) {
				return true;
			}
		}

		return false;
	}

	public static function oneLine($text) {
		return trim(preg_replace('/[\r\n\t]+/', ' ', (string)$text));
	}
}
