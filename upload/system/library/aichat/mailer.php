<?php
namespace Aichat;

/**
 * Plain-text e-mail through OpenCart's own Mail class, built exactly like
 * OpenCart 3.0.3.6 builds it (catalog/controller/mail/order.php): engine =
 * config_mail_engine, parameter, smtp_* settings, smtp password
 * html-entity-decoded, setFrom / setSender / setSubject / setText.
 *
 * Differences to the old version (round 4, "request recorded but no e-mail"):
 *  - the mail settings are those of the store the mail is about (store 0
 *    rows, overridden by the store's own rows: what the catalog loads for
 *    that store), not whatever store the current request runs under (admin,
 *    Telegram webhook);
 *  - the outcome is returned with an error text (deliver()); exceptions, PHP
 *    errors (\Error) and PHP warnings raised while sending are caught and
 *    reported instead of being lost; every failure is written to the
 *    OpenCart error log;
 *  - engine "mail": mail() being disabled or the sendmail binary missing is
 *    reported as an error (OpenCart ignores mail()'s return value, so these
 *    were invisible before).
 *
 * Test hook: with the constant AICHAT_MAIL_FILE defined the mail is appended
 * to that file ("To:", "Subject:", "Reply-To:", "Attachment:" lines, blank
 * line, body, "----") instead of being sent.
 */
class Mailer {
	/** @return bool */
	public static function send($registry, $to, $subject, $text, array $options = array()) {
		$result = self::deliver($registry, $to, $subject, $text, $options);

		return $result['ok'];
	}

	/**
	 * @param array $options from, sender, reply_to, attachments (absolute paths),
	 *                       store_id (whose mail settings / sender to use; default: current store)
	 * @return array {ok: bool, error: string, engine: string}
	 */
	public static function deliver($registry, $to, $subject, $text, array $options = array()) {
		$to = trim((string)$to);
		$config = $registry->get('config');
		$storeId = isset($options['store_id']) ? (int)$options['store_id'] : ($config ? (int)$config->get('config_store_id') : 0);
		$info = StoreInfo::get($registry, $storeId, isset($options['settings']) && $options['settings'] instanceof Settings ? $options['settings'] : null);
		$mailCfg = $info['mail'];
		$engine = $mailCfg['config_mail_engine'] !== '' ? strtolower($mailCfg['config_mail_engine']) : 'mail';

		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return self::failed($registry, 'no valid recipient address' . ($to !== '' ? ' (' . $to . ')' : ''), $engine);
		}

		$from = isset($options['from']) ? trim((string)$options['from']) : $info['email'];
		$sender = isset($options['sender']) ? (string)$options['sender'] : $info['name'];
		$replyTo = isset($options['reply_to']) ? trim((string)$options['reply_to']) : '';
		$attachments = isset($options['attachments']) && is_array($options['attachments']) ? $options['attachments'] : array();
		$subject = self::oneLine(html_entity_decode((string)$subject, ENT_QUOTES, 'UTF-8'));
		$sender = self::oneLine(html_entity_decode($sender, ENT_QUOTES, 'UTF-8'));

		if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
			$from = $to;
		}
		if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
			$replyTo = '';
		}
		if ($sender === '') {
			$sender = $info['name'];
		}

		if (defined('AICHAT_MAIL_FILE')) {
			$head = "To: " . $to . "\nFrom: " . $from . "\nSender: " . $sender . "\nSubject: " . $subject . "\n";
			if ($replyTo !== '') {
				$head .= "Reply-To: " . $replyTo . "\n";
			}
			foreach ($attachments as $file) {
				$head .= "Attachment: " . basename((string)$file) . (is_file($file) ? ' (' . filesize($file) . ' bytes)' : ' (missing)') . "\n";
			}
			$ok = @file_put_contents((string)constant('AICHAT_MAIL_FILE'), $head . "\n" . $text . "\n----\n", FILE_APPEND | LOCK_EX) !== false;

			return $ok ? array('ok' => true, 'error' => '', 'engine' => 'file') : self::failed($registry, 'test mail file not writable', 'file');
		}

		if (!class_exists('Mail')) {
			return self::failed($registry, 'OpenCart Mail class not available', $engine);
		}

		// OpenCart's Mail constructor calls exit() for an unknown engine: check first
		if (!preg_match('/^[a-z0-9_]+$/', $engine) || !class_exists('Mail\\' . $engine)) {
			return self::failed($registry, 'unknown mail engine "' . $engine . '" (System > Settings > Mail)', $engine);
		}

		if ($engine === 'mail') {
			$problem = self::mailFunctionProblem();
			if ($problem !== '') {
				return self::failed($registry, $problem, $engine);
			}
		}

		if ($engine === 'smtp' && trim($mailCfg['config_mail_smtp_hostname']) === '') {
			return self::failed($registry, 'SMTP engine without SMTP hostname (System > Settings > Mail)', $engine);
		}

		$warnings = array();
		set_error_handler(function ($code, $message) use (&$warnings) {
			if (error_reporting() !== 0 && !in_array($code, array(E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE), true)) {
				$warnings[] = $message;
			}
			return true;
		});

		$error = '';

		try {
			$mail = new \Mail($engine);
			$mail->parameter = $mailCfg['config_mail_parameter'];
			$mail->smtp_hostname = $mailCfg['config_mail_smtp_hostname'];
			$mail->smtp_username = $mailCfg['config_mail_smtp_username'];
			$mail->smtp_password = html_entity_decode($mailCfg['config_mail_smtp_password'], ENT_QUOTES, 'UTF-8');
			$mail->smtp_port = $mailCfg['config_mail_smtp_port'] !== '' ? $mailCfg['config_mail_smtp_port'] : 25;
			$mail->smtp_timeout = $mailCfg['config_mail_smtp_timeout'] !== '' ? $mailCfg['config_mail_smtp_timeout'] : 5;
			$mail->setTo($to);
			$mail->setFrom($from);
			$mail->setSender($sender);
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
			$error = $e->getMessage();
		} catch (\Error $e) {
			$error = get_class($e) . ': ' . $e->getMessage();
		}

		restore_error_handler();

		if ($error === '' && $warnings) {
			$error = implode('; ', array_unique($warnings));
		}

		if ($error !== '') {
			return self::failed($registry, $error, $engine);
		}

		return array('ok' => true, 'error' => '', 'engine' => $engine);
	}

	/** Why PHP mail() cannot work here, or '' (OpenCart itself never checks). */
	private static function mailFunctionProblem() {
		$disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));

		if (!function_exists('mail') || in_array('mail', $disabled, true)) {
			return 'PHP mail() is disabled on this server: choose SMTP in System > Settings > Mail';
		}

		if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
			$path = trim((string)ini_get('sendmail_path'));
			$bin = $path !== '' ? strtok($path, ' ') : '/usr/sbin/sendmail';
			if ($bin !== false && strpos($bin, '/') === 0 && !@is_executable($bin)) {
				return 'PHP mail(): sendmail program not found (' . $bin . '): choose SMTP in System > Settings > Mail';
			}
		}

		return '';
	}

	private static function failed($registry, $error, $engine) {
		$log = $registry->get('log');

		if ($log) {
			// no recipient / sender addresses in the OpenCart error log (it may be web-readable)
			$log->write('AI Chat mail not sent (' . $engine . '): ' . self::maskAddresses($error));
		}

		return array('ok' => false, 'error' => (string)$error, 'engine' => (string)$engine);
	}

	/** Request recipient: s_notify_email -> the store's config_email (see StoreInfo). */
	public static function storeEmail($registry, Settings $settings, $storeId) {
		return StoreInfo::notifyEmail($registry, $settings, $storeId);
	}

	/** {name, email, url} of a store (name resolved, never empty). */
	public static function storeConfig($registry, $storeId, Settings $settings = null) {
		$info = StoreInfo::get($registry, $storeId, $settings ? $settings : new Settings($registry));

		return array('name' => $info['name'], 'email' => $info['email'], 'url' => $info['url'], 'host' => $info['host']);
	}

	/** "jane@example.com" -> "[address]" (for log lines). */
	public static function maskAddresses($text) {
		return preg_replace('/[^\s<>()"\',;:]*@[^\s<>()"\',;:]*/u', '[address]', (string)$text);
	}

	/** "jane.doe@example.com" -> "j***@e***.com" (shown to the visitor before a transcript is sent). */
	public static function maskEmail($email) {
		$email = (string)$email;
		$at = strrpos($email, '@');

		if ($at === false) {
			return '';
		}

		$local = substr($email, 0, $at);
		$domain = substr($email, $at + 1);
		$dot = strrpos($domain, '.');
		$name = $dot === false ? $domain : substr($domain, 0, $dot);
		$tld = $dot === false ? '' : substr($domain, $dot);
		$first = function ($s) {
			return function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);
		};

		return $first($local) . '***@' . $first($name) . '***' . $tld;
	}

	public static function oneLine($text) {
		return trim(preg_replace('/[\r\n\t]+/', ' ', (string)$text));
	}
}
