<?php
namespace Aichat;

/**
 * Personal data (GDPR, round 6):
 *
 *  - data-subject requests for the admin: find(), export() (+ signed
 *    download token), erase(), restrict(), the accountability log (log())
 *    and the admin access log (accessLog());
 *  - text filters: redactText() (everything that identifies a person, for
 *    reports, previews, Telegram masking, anonymised quality data) and
 *    scrubSensitive() (the inbound filter of `send`: card numbers, IBAN,
 *    ЕГН/ЛНЧ, ΑΜΚΑ, passwords; phone numbers, e-mails and order numbers are
 *    NOT touched there, they are needed for order verification);
 *  - minimisation helpers for the order tools (streetOnly()).
 *
 * All methods are static; DB methods take the OpenCart registry.
 */
class Privacy {
	const MASK = '[…]';
	/** Export ZIPs live this long (download token and file). */
	const EXPORT_TTL = 86400;
	/** find(): at most this many conversations / requests. */
	const FIND_LIMIT = 500;

	// ================================================================ redaction

	/**
	 * Text with e-mails, phone numbers, IBAN, card numbers (Luhn), ЕГН/ЛНЧ,
	 * ΑΜΚΑ/ΑΦΜ, passwords and street addresses (after ул./бул./ж.к./οδός/
	 * street ...) replaced by […]. Prices, dates, quantities, order numbers,
	 * tracking numbers and product codes stay.
	 */
	public static function redactText($text) {
		$text = (string)$text;

		if ($text === '') {
			return '';
		}

		$found = array();
		$text = self::maskEmails($text);
		$text = self::maskIban($text, $found);
		$text = self::maskCards($text, $found);
		$text = self::maskIds($text, $found, false);
		$text = self::maskPasswords($text, $found);
		$text = self::maskPhones($text);
		$text = self::maskAddresses($text);

		return $text;
	}

	/**
	 * Inbound filter (send): {text, found:[card|iban|egn|amka|password]}.
	 * Only data nobody should send to a chat is masked; phone numbers,
	 * e-mails, order numbers and addresses stay (verification, delivery).
	 */
	public static function scrubSensitive($text) {
		$text = (string)$text;
		$found = array();

		if ($text !== '') {
			$text = self::maskIban($text, $found);
			$text = self::maskCards($text, $found);
			$text = self::maskIds($text, $found, true);
			$text = self::maskPasswords($text, $found);
		}

		return array('text' => $text, 'found' => array_values(array_unique($found)));
	}

	/** One-line redacted preview (dashboard "live now", lists without the personal-data permission). */
	public static function preview($text, $max = 140) {
		$text = trim((string)preg_replace('/\s+/u', ' ', self::redactText((string)$text)));

		return function_exists('mb_substr') ? mb_substr($text, 0, (int)$max, 'UTF-8') : substr($text, 0, (int)$max);
	}

	/**
	 * " AND <alias>.`restricted` = '0'" for statistics / reports / KB
	 * suggestions (restricted conversations are excluded everywhere).
	 */
	public static function notRestricted($alias = 'c') {
		return " AND " . ($alias !== '' ? $alias . "." : '') . "`restricted` = '0'";
	}

	/**
	 * Minimisation towards the model: street name without house number,
	 * entrance, floor or flat ("ул. Витоша 15, ет. 3" -> "ул. Витоша").
	 */
	public static function streetOnly($line) {
		$line = trim((string)$line);
		$parts = preg_split('/[,;\n]/u', $line);
		$first = trim((string)$parts[0]);
		$words = preg_split('/\s+/u', $first);
		$out = array();

		foreach ($words as $word) {
			if ($word === '' || preg_match('/\d/u', $word)) {
				continue;
			}
			if (preg_match('/^(№|no\.?|nr\.?|бл\.?|блок|вх\.?|ет\.?|ап\.?|апт\.?|apt\.?|flat|floor|αρ\.?)$/iu', $word)) {
				continue;
			}
			$out[] = $word;
		}

		return trim(implode(' ', $out), " \t-/");
	}

	private static function maskEmails($text) {
		return (string)preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/u', self::MASK, $text);
	}

	private static function maskIban($text, array &$found) {
		return (string)preg_replace_callback('/(?<![A-Za-z0-9])[A-Za-z]{2}\d{2}(?:[ ]?[A-Za-z0-9]){11,32}/u', function ($m) use (&$found) {
			$raw = $m[0];
			// the match may have swallowed following words: shorten until the checksum fits
			for ($len = strlen($raw); $len >= 15; $len--) {
				$candidate = rtrim(substr($raw, 0, $len));
				if (strlen($candidate) !== $len) {
					continue;
				}
				$compact = strtoupper(str_replace(' ', '', $candidate));
				if (strlen($compact) >= 15 && strlen($compact) <= 34 && self::ibanValid($compact)) {
					$found[] = 'iban';
					return Privacy::MASK . substr($raw, $len);
				}
			}
			return $raw;
		}, $text);
	}

	public static function ibanValid($iban) {
		$iban = strtoupper((string)$iban);

		if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
			return false;
		}

		$moved = substr($iban, 4) . substr($iban, 0, 4);
		$rest = 0;

		for ($i = 0, $n = strlen($moved); $i < $n; $i++) {
			$c = $moved[$i];
			$value = ctype_digit($c) ? $c : (string)(ord($c) - 55);
			for ($j = 0, $k = strlen($value); $j < $k; $j++) {
				$rest = ($rest * 10 + (int)$value[$j]) % 97;
			}
		}

		return $rest === 1;
	}

	/**
	 * Card numbers: 14-19 digits starting with 2-6 (Mastercard, Visa, Amex,
	 * Diners, Discover, ...), Luhn-valid, written together or in the usual
	 * groups (4-4-4-4[-3], Amex 4-6-5). EAN-13 product codes, tracking and
	 * order numbers do not qualify.
	 */
	private static function maskCards($text, array &$found) {
		return (string)preg_replace_callback('/(?<![\d])\d(?:[ \-]?\d){13,}(?![\d])/u', function ($m) use (&$found) {
			if (Privacy::isCardNumber($m[0])) {
				$found[] = 'card';
				return Privacy::MASK;
			}
			// numbers written next to the card ("9001 4111 1111 …"): any run of whole groups
			$parts = preg_split('/([ \-])/', $m[0], -1, PREG_SPLIT_DELIM_CAPTURE);
			$n = count($parts);
			for ($i = 0; $i < $n; $i += 2) {
				for ($j = $n - 1; $j > $i; $j -= 2) {
					$run = implode('', array_slice($parts, $i, $j - $i + 1));
					$len = strlen(preg_replace('/\D/', '', $run));
					if ($len >= 14 && $len <= 19 && Privacy::isCardNumber($run)) {
						$found[] = 'card';
						return implode('', array_slice($parts, 0, $i)) . Privacy::MASK . implode('', array_slice($parts, $j + 1));
					}
				}
			}
			return $m[0];
		}, $text);
	}

	public static function isCardNumber($written) {
		$digits = preg_replace('/\D/', '', (string)$written);
		$len = strlen($digits);

		if ($len < 14 || $len > 19 || !preg_match('/^[2-6]/', $digits)) {
			return false;
		}

		$groups = preg_split('/[ \-]/', trim((string)$written));

		if (count($groups) > 1) {
			$lengths = array_map('strlen', $groups);
			$amex = $lengths === array(4, 6, 5) || $lengths === array(4, 6, 4);
			$fours = true;
			for ($i = 0, $n = count($lengths) - 1; $i < $n; $i++) {
				if ($lengths[$i] !== 4) {
					$fours = false;
				}
			}
			if (!$amex && !($fours && end($lengths) >= 1 && end($lengths) <= 4)) {
				return false;
			}
		}

		return self::luhn($digits);
	}

	public static function luhn($digits) {
		$digits = (string)$digits;
		$sum = 0;
		$alt = false;

		for ($i = strlen($digits) - 1; $i >= 0; $i--) {
			$d = (int)$digits[$i];
			if ($alt) {
				$d *= 2;
				if ($d > 9) {
					$d -= 9;
				}
			}
			$sum += $d;
			$alt = !$alt;
		}

		return $digits !== '' && $sum % 10 === 0;
	}

	/**
	 * National ids. Automatic (no keyword needed): ЕГН with a valid date and
	 * checksum that is not shaped like a phone number (BG mobile 08/09…,
	 * Greek 69… / 2…), ΑΜΚΑ (11 digits, valid date, Luhn). After a keyword
	 * (ЕГН, ЛНЧ, ΑΜΚΑ, ΑΦΜ …) any number of that length. $scrub: the inbound
	 * filter (no ΑΦΜ, which is not in its list).
	 */
	private static function maskIds($text, array &$found, $scrub) {
		// keyword + number
		$keywords = array(
			'egn'  => array('(?:ЕГН|EGN|ЛНЧ|LNCH|LNC|ЕГН-то)', 10),
			'amka' => array('(?:ΑΜΚΑ|Α\.Μ\.Κ\.Α\.?|AMKA)', 11),
		);
		if (!$scrub) {
			$keywords['afm'] = array('(?:ΑΦΜ|Α\.Φ\.Μ\.?|AFM)', 9);
		}

		foreach ($keywords as $type => $rule) {
			$text = (string)preg_replace_callback('/(?<![\p{L}])' . $rule[0] . '(?![\p{L}])([^\d\n]{0,20})(\d(?:[ \-]?\d){' . ($rule[1] - 1) . '})(?!\d)/iu', function ($m) use (&$found, $type) {
				$found[] = $type === 'afm' ? 'afm' : $type;
				return substr($m[0], 0, strlen($m[0]) - strlen($m[2])) . Privacy::MASK;
			}, $text);
		}

		// ЕГН (10 digits) / ΑΜΚΑ (11 digits) without a keyword
		$text = (string)preg_replace_callback('/(?<![\d\-\/.,])\d{10,11}(?![\d\-\/]|[.,]\d)/u', function ($m) use (&$found) {
			$d = $m[0];
			if (strlen($d) === 10 && Privacy::egnValid($d) && !Privacy::phoneShaped($d)) {
				$found[] = 'egn';
				return Privacy::MASK;
			}
			if (strlen($d) === 11 && Privacy::amkaValid($d)) {
				$found[] = 'amka';
				return Privacy::MASK;
			}
			return $d;
		}, $text);

		return $text;
	}

	/** 10 digits that read as a phone number: BG mobile 08x/09x, Greek mobile 69x, Greek landline 2x. */
	public static function phoneShaped($digits) {
		return (bool)preg_match('/^(?:0[89]\d{8}|69\d{8}|2\d{9})$/', (string)$digits);
	}

	/** Bulgarian ЕГН: date (month +20 for 18xx, +40 for 20xx) and checksum. */
	public static function egnValid($egn) {
		$egn = (string)$egn;

		if (!preg_match('/^\d{10}$/', $egn)) {
			return false;
		}

		$y = (int)substr($egn, 0, 2);
		$m = (int)substr($egn, 2, 2);
		$d = (int)substr($egn, 4, 2);

		if ($m > 40) {
			$m -= 40;
			$y += 2000;
		} elseif ($m > 20) {
			$m -= 20;
			$y += 1800;
		} else {
			$y += 1900;
		}

		if (!checkdate($m, $d, $y)) {
			return false;
		}

		$weights = array(2, 4, 8, 5, 10, 9, 7, 3, 6);
		$sum = 0;

		foreach ($weights as $i => $w) {
			$sum += (int)$egn[$i] * $w;
		}

		$check = $sum % 11;

		return ($check === 10 ? 0 : $check) === (int)$egn[9];
	}

	/** Greek ΑΜΚΑ: DDMMYY + 5 digits, Luhn. */
	public static function amkaValid($amka) {
		$amka = (string)$amka;

		if (!preg_match('/^\d{11}$/', $amka)) {
			return false;
		}

		$d = (int)substr($amka, 0, 2);
		$m = (int)substr($amka, 2, 2);

		return $d >= 1 && $d <= 31 && $m >= 1 && $m <= 12 && checkdate($m, $d, 2000 + (int)substr($amka, 4, 2)) && self::luhn($amka);
	}

	/** Greek ΑΦΜ (9 digits) checksum. */
	public static function afmValid($afm) {
		$afm = (string)$afm;

		if (!preg_match('/^\d{9}$/', $afm) || $afm === '000000000') {
			return false;
		}

		$sum = 0;

		for ($i = 0; $i < 8; $i++) {
			$sum += (int)$afm[$i] << (8 - $i);
		}

		return ($sum % 11) % 10 === (int)$afm[8];
	}

	/** "парола: x", "password is x", "κωδικός: x" -> the value masked. */
	private static function maskPasswords($text, array &$found) {
		return (string)preg_replace_callback('/(?<![\p{L}])(парола(?:та)?|password|passwd|pwd|κωδικός|κωδικό|κωδικος|συνθηματικό)(?![\p{L}])(\s*(?:[:=]|(?<![\p{L}])(?:е|is|είναι)(?![\p{L}]))\s*)(\S{3,})/iu', function ($m) use (&$found) {
			$found[] = 'password';
			return $m[1] . $m[2] . Privacy::MASK;
		}, $text);
	}

	/**
	 * Phone numbers: international (+ / 00 / 359 / 30 prefix), Bulgarian
	 * (mobile 08x/09x 10 digits, landline 0[2-7] 9 digits), Greek (69…, 2…
	 * 10 digits), with spaces, dashes, slashes or brackets inside.
	 */
	private static function maskPhones($text) {
		$sep = '[ \-\/()]{0,2}';
		$patterns = array(
			'/(?<![\w+])(?:\+|00)\s?\(?\d(?:' . $sep . '\d){7,14}(?!\d)/u' => function ($d) {
				$d = ltrim($d, '0');
				return strlen($d) >= 8 && strlen($d) <= 15;
			},
			'/(?<![\d\w])(?:359|30)(?:' . $sep . '\d){8,10}(?!\d)/u' => function ($d) {
				return (bool)preg_match('/^(?:359[2-9]\d{7,8}|30(?:69|2)\d{8})$/', $d);
			},
			'/(?<![\d\w])0(?:' . $sep . '\d){8,9}(?!\d)/u' => function ($d) {
				return (bool)preg_match('/^(?:0[89]\d{8}|0[2-7]\d{7})$/', $d);
			},
			'/(?<![\d\w])(?:69|2)(?:' . $sep . '\d){8,9}(?!\d)/u' => function ($d) {
				return (bool)preg_match('/^(?:69\d{8}|2\d{9})$/', $d);
			},
		);

		foreach ($patterns as $regex => $valid) {
			$text = (string)preg_replace_callback($regex, function ($m) use ($valid) {
				$written = rtrim($m[0], " -/(");
				$digits = preg_replace('/\D/', '', $written);
				if ($valid($digits)) {
					return Privacy::MASK . substr($m[0], strlen($written));
				}
				return $m[0];
			}, $text);
		}

		return $text;
	}

	/** Street part after ул./бул./ж.к./кв./οδός/street …, block / entrance / floor / flat numbers. */
	private static function maskAddresses($text) {
		$street = '(?:ул\.|бул\.|ж\.\s?к\.|кв\.(?!\s*м(?![\p{L}]))|οδ\.|λεωφ\.|str\.|st\.|ave\.|rd\.|(?:улица|булевард|οδός|οδος|λεωφόρος|street|avenue|road)(?![\p{L}]))';
		$text = (string)preg_replace_callback('/(?<![\p{L}\d])(' . $street . ')(\s*)([^,;\n\[]{1,80})/iu', function ($m) {
			if (trim($m[3]) === '') {
				return $m[0];
			}
			// English keywords also start ordinary phrases ("street food"): only with a house number
			if (preg_match('/^(?:str\.|st\.|ave\.|rd\.|street|avenue|road)$/i', $m[1]) && !preg_match('/\d/', $m[3])) {
				return $m[0];
			}
			return $m[1] . ($m[2] !== '' ? $m[2] : ' ') . Privacy::MASK;
		}, $text);

		// "5 Main Street": the house number before the street name
		$text = (string)preg_replace('/(?<![\d\w])\d{1,5}[A-Za-z]?\s+(?=[A-Z][\w\-]*(?:\s+[A-Z][\w\-]*){0,3}\s+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln)\b)/u', self::MASK . ' ', $text);

		return (string)preg_replace('/(?<![\p{L}\d])(бл\.|вх\.|ет\.|ап\.|apt\.)(\s*)([\p{L}\d]{1,6})(?![\p{L}\d])/iu', '$1$2' . self::MASK, $text);
	}

	// ================================================================ find / export / erase / restrict

	/**
	 * Records of a data subject. $q: email, phone, order_id, customer_id (any
	 * subset; empty values are ignored; all given criteria are OR-ed).
	 *
	 * @return array {conversations:[{conversation_id, store_id, date_added, date_modified, message_count, restricted}],
	 *                requests:[{request_id, type, store_id, date_added}], uploads:int}
	 */
	public static function find($registry, array $q) {
		$db = $registry->get('db');
		$p = DB_PREFIX;
		$conv = array();
		$req = array();

		$email = isset($q['email']) ? strtolower(trim((string)$q['email'])) : '';
		$phone = isset($q['phone']) ? substr(preg_replace('/\D/', '', (string)$q['phone']), -9) : '';
		$orderId = isset($q['order_id']) ? (int)preg_replace('/\D/', '', (string)$q['order_id']) : 0;
		$customerId = isset($q['customer_id']) ? (int)$q['customer_id'] : 0;

		if (strlen($phone) < 6) {
			$phone = '';
		}
		if ($email !== '' && strpos($email, '@') === false) {
			$email = '';
		}

		$addConv = function (array $rows) use (&$conv) {
			foreach ($rows as $row) {
				$conv[(int)$row['conversation_id']] = true;
			}
		};
		$addReq = function (array $rows) use (&$req) {
			foreach ($rows as $row) {
				$req[(int)$row['request_id']] = true;
			}
		};
		$limit = " LIMIT " . self::FIND_LIMIT;

		$orders = array();

		if ($customerId > 0) {
			$addConv($db->query("SELECT `conversation_id` FROM `" . $p . "aichat_conversation` WHERE `customer_id` = '" . $customerId . "'" . $limit)->rows);
			foreach ($db->query("SELECT `order_id` FROM `" . $p . "order` WHERE `customer_id` = '" . $customerId . "' AND `order_status_id` > '0'" . $limit)->rows as $row) {
				$orders[(int)$row['order_id']] = true;
			}
		}

		if ($orderId > 0) {
			$orders[$orderId] = true;
		}

		if ($email !== '') {
			$like = "'%" . self::likeEscape($db, $email) . "%'";
			$addConv($db->query("SELECT DISTINCT `conversation_id` FROM `" . $p . "aichat_message` WHERE LOWER(`content`) LIKE " . $like . $limit)->rows);
			$addConv($db->query("SELECT c.`conversation_id` FROM `" . $p . "aichat_conversation` c JOIN `" . $p . "customer` cu ON (cu.customer_id = c.customer_id) WHERE c.`customer_id` > 0 AND LOWER(cu.`email`) = '" . $db->escape($email) . "'" . $limit)->rows);
			$addReq($db->query("SELECT `request_id` FROM `" . $p . "aichat_request` WHERE LOWER(`email`) = '" . $db->escape($email) . "' OR LOWER(`data`) LIKE " . $like . $limit)->rows);
			foreach ($db->query("SELECT `order_id` FROM `" . $p . "order` WHERE LOWER(`email`) = '" . $db->escape($email) . "' AND `order_status_id` > '0'" . $limit)->rows as $row) {
				$orders[(int)$row['order_id']] = true;
			}
		}

		if ($phone !== '') {
			$regex = "'" . $db->escape(self::phoneRegex($phone)) . "'";
			$addConv($db->query("SELECT DISTINCT `conversation_id` FROM `" . $p . "aichat_message` WHERE `content` LIKE '%" . $db->escape(substr($phone, -1)) . "%' AND `content` REGEXP " . $regex . $limit)->rows);
			$addReq($db->query("SELECT `request_id` FROM `" . $p . "aichat_request` WHERE (`phone` <> '' AND `phone` REGEXP " . $regex . ") OR (`data` REGEXP " . $regex . ")" . $limit)->rows);
			foreach ($db->query("SELECT `order_id` FROM `" . $p . "order` WHERE `telephone` REGEXP " . $regex . " AND `order_status_id` > '0'" . $limit)->rows as $row) {
				$orders[(int)$row['order_id']] = true;
			}
		}

		if ($orders) {
			$in = implode(',', array_map('intval', array_slice(array_keys($orders), 0, self::FIND_LIMIT)));
			$addConv($db->query("SELECT `conversation_id` FROM `" . $p . "aichat_conversation` WHERE `verified_order_id` IN (" . $in . ")" . $limit)->rows);
			$addReq($db->query("SELECT `request_id` FROM `" . $p . "aichat_request` WHERE `order_id` IN (" . $in . ")" . $limit)->rows);
		}

		// requests of the conversations found, and the conversations of the requests found
		if ($conv) {
			$addReq($db->query("SELECT `request_id` FROM `" . $p . "aichat_request` WHERE `conversation_id` IN (" . implode(',', array_keys($conv)) . ")" . $limit)->rows);
		}
		if ($req) {
			$addConv($db->query("SELECT `conversation_id` FROM `" . $p . "aichat_request` WHERE `conversation_id` > 0 AND `request_id` IN (" . implode(',', array_keys($req)) . ")")->rows);
		}

		$out = array('conversations' => array(), 'requests' => array(), 'uploads' => 0);

		if ($conv) {
			$ids = implode(',', array_slice(array_keys($conv), 0, self::FIND_LIMIT));
			foreach ($db->query("SELECT `conversation_id`, `store_id`, `date_added`, `date_modified`, `message_count`, `restricted` FROM `" . $p . "aichat_conversation` WHERE `conversation_id` IN (" . $ids . ") ORDER BY `conversation_id` DESC")->rows as $row) {
				$out['conversations'][] = array(
					'conversation_id' => (int)$row['conversation_id'],
					'store_id'        => (int)$row['store_id'],
					'date_added'      => $row['date_added'],
					'date_modified'   => $row['date_modified'],
					'message_count'   => (int)$row['message_count'],
					'restricted'      => (int)$row['restricted'],
				);
			}
			$out['uploads'] = (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_upload` WHERE `conversation_id` IN (" . $ids . ")")->row['n'];
		}

		if ($req) {
			foreach ($db->query("SELECT `request_id`, `type`, `store_id`, `date_added` FROM `" . $p . "aichat_request` WHERE `request_id` IN (" . implode(',', array_slice(array_keys($req), 0, self::FIND_LIMIT)) . ") ORDER BY `request_id` DESC")->rows as $row) {
				$out['requests'][] = array(
					'request_id' => (int)$row['request_id'],
					'type'       => $row['type'],
					'store_id'   => (int)$row['store_id'],
					'date_added' => $row['date_added'],
				);
			}
		}

		return $out;
	}

	/** MySQL / MariaDB REGEXP for the last 9 digits of a phone number with separators allowed between digits. */
	private static function phoneRegex($digits) {
		return implode('[ ./()-]{0,2}', str_split((string)$digits)) . '([^0-9]|$)';
	}

	private static function likeEscape($db, $text) {
		return str_replace(array('%', '_'), array('\\%', '\\_'), $db->escape((string)$text));
	}

	/**
	 * ZIP in DIR_STORAGE/aichat/exports/ with data.json (conversations with
	 * their messages and uploads, requests) and the images. Returns its path
	 * ('' when ZipArchive is missing or nothing could be written).
	 */
	public static function export($registry, array $conversationIds, array $requestIds) {
		if (!class_exists('\\ZipArchive')) {
			return '';
		}

		$db = $registry->get('db');
		$p = DB_PREFIX;
		$conversationIds = self::ids($conversationIds);
		$requestIds = self::ids($requestIds);
		self::cleanExports();

		$dir = self::exportDir();
		if (!self::protect($dir)) {
			return '';
		}

		$data = array(
			'generated'     => date('c'),
			'format'        => 'aichat-export-1',
			'conversations' => array(),
			'requests'      => array(),
		);
		$images = array();

		foreach ($conversationIds as $cid) {
			$row = $db->query("SELECT `conversation_id`, `store_id`, `language_id`, `customer_id`, `ip`, `user_agent`, `mode`, `verified_order_id`, `first_url`, `last_url`, `message_count`, `restricted`, `privacy_accepted_at`, `staff_name`, `date_added`, `date_modified` FROM `" . $p . "aichat_conversation` WHERE `conversation_id` = '" . $cid . "'")->row;
			if (!$row) {
				continue;
			}
			$conversation = $row;
			$conversation['messages'] = array();
			foreach ($db->query("SELECT `message_id`, `role`, `content`, `meta`, `rating`, `date_added` FROM `" . $p . "aichat_message` WHERE `conversation_id` = '" . $cid . "' ORDER BY `message_id`")->rows as $m) {
				$meta = $m['meta'] ? json_decode($m['meta'], true) : array();
				$item = array('message_id' => (int)$m['message_id'], 'role' => $m['role'], 'content' => $m['content'], 'date_added' => $m['date_added']);
				if ((int)$m['rating']) {
					$item['rating'] = (int)$m['rating'];
				}
				if (is_array($meta)) {
					foreach (array('url', 'images', 'staff_name', 'cards') as $k) {
						if (!empty($meta[$k])) {
							$item[$k] = $meta[$k];
						}
					}
					if (!empty($meta['products']) && is_array($meta['products'])) {
						$names = array();
						foreach ($meta['products'] as $group) {
							foreach (isset($group['items']) && is_array($group['items']) ? $group['items'] : array() as $card) {
								if (isset($card['name'])) {
									$names[] = $card['name'];
								}
							}
						}
						if ($names) {
							$item['products'] = $names;
						}
					}
				}
				$conversation['messages'][] = $item;
			}
			$conversation['uploads'] = array();
			foreach ($db->query("SELECT * FROM `" . $p . "aichat_upload` WHERE `conversation_id` = '" . $cid . "' ORDER BY `upload_id`")->rows as $u) {
				$file = Uploads::path($u, false);
				$name = 'images/' . (int)$u['upload_id'] . '.jpg';
				$conversation['uploads'][] = array('upload_id' => (int)$u['upload_id'], 'file' => $file !== '' ? $name : null, 'date_added' => $u['date_added']);
				if ($file !== '') {
					$images[$name] = $file;
				}
			}
			$data['conversations'][] = $conversation;
		}

		foreach ($requestIds as $rid) {
			$row = $db->query("SELECT * FROM `" . $p . "aichat_request` WHERE `request_id` = '" . $rid . "'")->row;
			if ($row) {
				$decoded = $row['data'] ? json_decode($row['data'], true) : null;
				$row['data'] = is_array($decoded) ? $decoded : $row['data'];
				$data['requests'][] = $row;
			}
		}

		$name = 'aichat-export-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.zip';
		$path = $dir . $name;
		$zip = new \ZipArchive();

		if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return '';
		}

		$zip->addFromString('data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)));
		$zip->addFromString('README.txt', "AI Chat - personal data export / експорт на лични данни\n" . date('c') . "\n\ndata.json: conversations (messages, uploads) and requests.\nimages/: the pictures sent in the chat.\n");
		$zip->addFromString('manifest.json', json_encode(array('conversations' => $conversationIds, 'requests' => $requestIds)));
		foreach ($images as $inZip => $file) {
			$zip->addFile($file, $inZip);
		}

		if (!$zip->close() || !is_file($path)) {
			@unlink($path);
			return '';
		}

		@chmod($path, 0600);

		return $path;
	}

	public static function exportDir() {
		return rtrim(DIR_STORAGE, '/') . '/aichat/exports/';
	}

	/** Signed download token for an export path: <file>.<expires>.<hmac>, valid EXPORT_TTL from now (never past the file's 24 h). */
	public static function exportUrlToken($path) {
		$name = basename((string)$path);

		if (!self::validExportName($name) || !is_file(self::exportDir() . $name)) {
			return '';
		}

		$expires = min(time() + self::EXPORT_TTL, (int)filemtime(self::exportDir() . $name) + self::EXPORT_TTL);

		return $name . '.' . $expires . '.' . self::exportSig($name, $expires);
	}

	/** Path of the export a token points to ('' when invalid, expired or gone). */
	public static function readExport($token) {
		if (!is_string($token) || !preg_match('/^(aichat-export-\d{8}-\d{6}-[a-f0-9]{16}\.zip)\.(\d{10})\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
			return '';
		}

		$expires = (int)$m[2];

		if ($expires < time() || !hash_equals(self::exportSig($m[1], $expires), $m[3])) {
			return '';
		}

		$path = self::exportDir() . $m[1];

		if (!is_file($path) || (int)filemtime($path) + self::EXPORT_TTL < time()) {
			return '';
		}

		return $path;
	}

	private static function validExportName($name) {
		return (bool)preg_match('/^aichat-export-\d{8}-\d{6}-[a-f0-9]{16}\.zip$/', (string)$name);
	}

	private static function exportSig($name, $expires) {
		return rtrim(strtr(base64_encode(hash_hmac('sha256', 'aichat|export|' . $name . '|' . (int)$expires, self::exportKey(), true)), '+/', '-_'), '=');
	}

	/** Signing key of the export tokens: a write-only secret file, created on first use. */
	private static function exportKey() {
		$file = Settings::secretFile('export');

		if (is_file($file)) {
			$value = include $file;
			if (is_string($value) && strlen($value) >= 32) {
				return $value;
			}
		}

		self::protect(dirname($file) . '/');
		$value = bin2hex(random_bytes(32));
		$tmp = $file . '.' . bin2hex(random_bytes(4));

		if (@file_put_contents($tmp, "<?php\n// AI Chat export signing key. Do not share.\nreturn " . var_export($value, true) . ";\n", LOCK_EX) !== false) {
			@chmod($tmp, 0600);
			if (!is_file($file)) {
				@rename($tmp, $file);
			} else {
				@unlink($tmp);
			}
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($file, true);
			}
		}

		$again = is_file($file) ? include $file : null;

		return is_string($again) && strlen($again) >= 32 ? $again : $value;
	}

	/** Lazy: export ZIPs older than 24 h. Returns the number deleted. */
	public static function cleanExports($maxAge = self::EXPORT_TTL) {
		$n = 0;

		foreach ((array)glob(self::exportDir() . 'aichat-export-*.zip') as $file) {
			if (is_file($file) && (int)filemtime($file) < time() - (int)$maxAge) {
				@unlink($file);
				$n++;
			}
		}

		return $n;
	}

	/** Uninstall / purge: every export file and the folder. */
	public static function deleteAllExports() {
		$dir = self::exportDir();

		foreach ((array)glob($dir . '*') as $file) {
			@unlink($file);
		}
		@unlink($dir . '.htaccess');
		@rmdir(rtrim($dir, '/'));
		@unlink(Settings::secretFile('export'));
	}

	/**
	 * Deletes the selected records: conversations (messages, uploads with
	 * their files, their requests as in forget, Telegram topics) and requests
	 * (contact / stock rows deleted, order / return rows anonymised), plus
	 * every export ZIP that contains one of them.
	 *
	 * @return array {conversations, messages, uploads, requests_deleted, requests_anonymised, topics, exports}
	 */
	public static function erase($registry, array $conversationIds, array $requestIds) {
		$db = $registry->get('db');
		$p = DB_PREFIX;
		$conversationIds = self::ids($conversationIds);
		$requestIds = self::ids($requestIds);
		$counts = array('conversations' => 0, 'messages' => 0, 'uploads' => 0, 'requests_deleted' => 0, 'requests_anonymised' => 0, 'topics' => 0, 'exports' => 0);
		$settings = new Settings($registry);

		foreach ($conversationIds as $cid) {
			$row = $db->query("SELECT * FROM `" . $p . "aichat_conversation` WHERE `conversation_id` = '" . $cid . "'")->row;
			if (!$row) {
				continue;
			}
			if (self::deleteTopic($registry, $settings, $row)) {
				$counts['topics']++;
			}
			$counts['messages'] += (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_message` WHERE `conversation_id` = '" . $cid . "'")->row['n'];
			$counts['uploads'] += (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_upload` WHERE `conversation_id` = '" . $cid . "'")->row['n'];
			$counts['requests_deleted'] += (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_request` WHERE `conversation_id` = '" . $cid . "' AND `type` NOT IN ('return', 'order')")->row['n'];
			$counts['requests_anonymised'] += (int)$db->query("SELECT COUNT(*) AS n FROM `" . $p . "aichat_request` WHERE `conversation_id` = '" . $cid . "' AND `type` IN ('return', 'order')")->row['n'];
			Maintenance::deleteConversation($registry, $cid, false);
			$counts['conversations']++;
		}

		foreach ($requestIds as $rid) {
			$row = $db->query("SELECT `request_id`, `type` FROM `" . $p . "aichat_request` WHERE `request_id` = '" . $rid . "'")->row;
			if (!$row) {
				continue;
			}
			if (in_array($row['type'], array('return', 'order'), true)) {
				$db->query("UPDATE `" . $p . "aichat_request` SET `email` = '', `phone` = '', `product_id` = '0', `data` = '" . $db->escape(json_encode(array('forgotten' => true))) . "', `conversation_id` = '0' WHERE `request_id` = '" . $rid . "'");
				$counts['requests_anonymised']++;
			} else {
				$db->query("DELETE FROM `" . $p . "aichat_request` WHERE `request_id` = '" . $rid . "'");
				$counts['requests_deleted']++;
			}
		}

		$counts['exports'] = self::deleteExportsWith($conversationIds, $requestIds);

		return $counts;
	}

	/** Export ZIPs whose manifest names one of these records. */
	private static function deleteExportsWith(array $conversationIds, array $requestIds) {
		$n = 0;

		if ((!$conversationIds && !$requestIds) || !class_exists('\\ZipArchive')) {
			return 0;
		}

		foreach ((array)glob(self::exportDir() . 'aichat-export-*.zip') as $file) {
			$zip = new \ZipArchive();
			$hit = true; // unreadable: deleted to be safe
			if ($zip->open($file) === true) {
				$manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
				$zip->close();
				if (is_array($manifest)) {
					$hit = (isset($manifest['conversations']) && array_intersect(self::ids((array)$manifest['conversations']), $conversationIds))
						|| (isset($manifest['requests']) && array_intersect(self::ids((array)$manifest['requests']), $requestIds));
				}
			}
			if ($hit && @unlink($file)) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * Deletes the conversation's Telegram forum topic (forget / erase /
	 * lazy after s_tg_delete_days). Returns true when Telegram confirmed.
	 * The topic columns are cleared either way when the row still exists.
	 */
	public static function deleteTopic($registry, Settings $settings, array $conversation) {
		$chatId = isset($conversation['tg_chat_id']) ? (string)$conversation['tg_chat_id'] : '';
		$topic = isset($conversation['tg_topic_id']) ? (int)$conversation['tg_topic_id'] : 0;

		if ($chatId === '' || !$topic) {
			return false;
		}

		$telegram = new Telegram($settings);
		$ok = $telegram->hasToken() && $telegram->deleteTopic($chatId, $topic);

		if (!$ok) {
			$log = $registry->get('log');
			if ($log) {
				$log->write('AI Chat: Telegram topic ' . $topic . ' of chat ' . (int)$conversation['conversation_id'] . ' not deleted: ' . $telegram->lastError());
			}
		}

		$registry->get('db')->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `tg_topic_id` = '0', `tg_chat_id` = '' WHERE `conversation_id` = '" . (int)$conversation['conversation_id'] . "'");

		return $ok;
	}

	/**
	 * restricted = 1: the conversation is excluded from the model, KB
	 * suggestions, reports and statistics and cannot be continued (boot
	 * starts a fresh one). Returns false when there is no such conversation.
	 */
	public static function restrict($registry, $conversationId, $on) {
		$db = $registry->get('db');
		$row = $db->query("SELECT `conversation_id` FROM `" . DB_PREFIX . "aichat_conversation` WHERE `conversation_id` = '" . (int)$conversationId . "'")->row;

		if (!$row) {
			return false;
		}

		$db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `restricted` = '" . ($on ? 1 : 0) . "'" . ($on ? ", `mode` = IF(`mode` IN ('waiting', 'human'), 'closed', `mode`), `waiting_since` = NULL" : '') . " WHERE `conversation_id` = '" . (int)$conversationId . "'");

		return true;
	}

	// ================================================================ logs

	public static function dsrActions() {
		return array('search', 'export', 'erase', 'restrict', 'unrestrict');
	}

	/**
	 * Accountability log (aichat_dsr). $data: {query: array|string, result: array|string};
	 * without those keys the whole array is the query. Returns dsr_id (0 = unknown action).
	 */
	public static function log($registry, $userId, $action, array $data) {
		if (!in_array($action, self::dsrActions(), true)) {
			return 0;
		}

		$db = $registry->get('db');
		$query = array_key_exists('query', $data) ? $data['query'] : $data;
		$result = array_key_exists('result', $data) ? $data['result'] : array();
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);

		$db->query("INSERT INTO `" . DB_PREFIX . "aichat_dsr` SET `user_id` = '" . (int)$userId . "', `action` = '" . $db->escape($action) . "', `query` = '" . $db->escape((string)json_encode($query, $flags)) . "', `result` = '" . $db->escape((string)json_encode($result, $flags)) . "', `date_added` = NOW()");

		return (int)$db->getLastId();
	}

	/** Admin access log (aichat_access_log): view | export | delete of a conversation. Returns log_id (0 = unknown action). */
	public static function accessLog($registry, $userId, $conversationId, $action) {
		if (!in_array($action, array('view', 'export', 'delete'), true)) {
			return 0;
		}

		$db = $registry->get('db');
		$db->query("INSERT INTO `" . DB_PREFIX . "aichat_access_log` SET `user_id` = '" . (int)$userId . "', `conversation_id` = '" . (int)$conversationId . "', `action` = '" . $db->escape($action) . "', `date_added` = NOW()");

		return (int)$db->getLastId();
	}

	// ================================================================ privacy notice

	/**
	 * Default privacy URL of a store: its enabled information page whose
	 * title contains "поверител" / "privacy" / "απόρρητο" (any language
	 * first matching the given one), else ''. Plain route URL on the
	 * store's own address (the storefront uses its SEO link instead).
	 */
	public static function defaultPrivacyUrl($registry, $storeId, $languageId) {
		$id = self::privacyInformationId($registry, $storeId, $languageId);

		if (!$id) {
			return '';
		}

		$info = StoreInfo::get($registry, (int)$storeId);
		$base = rtrim((string)$info['url'], '/');

		return $base === '' ? '' : $base . '/index.php?route=information/information&information_id=' . $id;
	}

	/** information_id of the store's privacy page (0 = none). */
	public static function privacyInformationId($registry, $storeId, $languageId) {
		$db = $registry->get('db');
		$p = DB_PREFIX;
		$words = array('поверител', 'privacy', 'απόρρητο', 'απορρητο', 'gdpr', 'лични данни', 'προσωπικά δεδομένα');
		$conds = array();

		foreach ($words as $w) {
			$conds[] = "LOWER(id.`title`) LIKE '%" . $db->escape($w) . "%'";
		}

		$row = $db->query("SELECT i.`information_id` FROM `" . $p . "information` i"
			. " JOIN `" . $p . "information_description` id ON (id.`information_id` = i.`information_id`)"
			. " JOIN `" . $p . "information_to_store` s ON (s.`information_id` = i.`information_id` AND s.`store_id` = '" . (int)$storeId . "')"
			. " WHERE i.`status` = '1' AND (" . implode(' OR ', $conds) . ")"
			. " ORDER BY (id.`language_id` = '" . (int)$languageId . "') DESC, (LOWER(id.`title`) LIKE '%поверител%' OR LOWER(id.`title`) LIKE '%privacy%' OR LOWER(id.`title`) LIKE '%απόρρητο%') DESC, i.`sort_order`, i.`information_id` LIMIT 1")->row;

		return $row ? (int)$row['information_id'] : 0;
	}

	/** Owner-entered privacy URL: http(s) or a path on the store; anything else is ignored. */
	public static function cleanUrl($url) {
		$url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));

		if ($url === '' || preg_match('/[\s<>"\']/u', $url)) {
			return '';
		}

		if (preg_match('#^https?://[^/\s]+#i', $url) || (strpos($url, '/') === 0 && strpos($url, '//') !== 0)) {
			return $url;
		}

		return '';
	}

	// ================================================================ helpers

	private static function ids(array $ids) {
		$out = array();

		foreach ($ids as $id) {
			if (is_numeric($id) && (int)$id > 0) {
				$out[(int)$id] = (int)$id;
			}
		}

		return array_values($out);
	}

	private static function protect($dir) {
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
			return false;
		}

		foreach (array(rtrim(dirname(rtrim($dir, '/')), '/') . '/', rtrim($dir, '/') . '/') as $d) {
			if (!is_file($d . '.htaccess')) {
				@file_put_contents($d . '.htaccess', "Require all denied\nDeny from all\n");
			}
			if (!is_file($d . 'index.html')) {
				@file_put_contents($d . 'index.html', '');
			}
		}

		return true;
	}
}
