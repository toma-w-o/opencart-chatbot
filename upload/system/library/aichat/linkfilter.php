<?php
namespace Aichat;

/**
 * Removes links to foreign hosts from model output.
 *
 * Allowed: the current store's own host(s) and relative links, plus extra
 * hosts passed in (e.g. courier tracking links from a verified order).
 *
 *  - markdown links  [label](url)  to a foreign host become just "label"
 *  - bare URLs (http://, https://, //, www.) to a foreign host are removed
 *  - bare domain names (shop.com, example.bg/page) of foreign hosts are removed
 *  - e-mail addresses are left alone
 *
 * Streaming: push() returns only text that can no longer change meaning when
 * more text arrives (cut at the last whitespace, never inside an unfinished
 * markdown link); flush() returns the rest at the end of the stream. URLs
 * cannot contain whitespace, so a URL is never split across emitted pieces.
 */
class LinkFilter {
	const MAX_HOLD = 2000;

	const TLDS = 'com|net|org|info|biz|eu|bg|gr|ro|de|at|ch|uk|fr|it|es|pt|nl|be|pl|cz|sk|hu|rs|mk|al|tr|cy|ru|ua|us|ca|io|co|me|tv|app|dev|xyz|shop|store|online|site|top|club|link|ly|to|gl|cn|jp|ai|gg|cc|ws|sh|so|ms|nu|tk|ml|ga|cf|gq|page|zip|mov|live|blog|web|cloud|tech|pro|news|email|click|space|website|fun|icu|vip|win|lol|bid|life|world|today|art|design|im|in|is|la|lt|lv|ee|fi|se|no|dk|ie|si|hr|ba|md|by|kz|ge|am|az|il|ae|sa|eg|za|ng|ke|au|nz|br|ar|mx|cl|kr|tw|hk|sg|my|th|vn|id|ph|pk|bd|lk|ir|iq|su|ac|ag|bz|cx|fm|gs|ht|ki|lc|mn|mu|nf|pw|sc|sx|tl|vc|vg|vu';

	private $hosts = array();
	private $urls = array();
	private $buffer = '';

	public function __construct(array $allowedHosts = array()) {
		foreach ($allowedHosts as $host) {
			$this->allowHost($host);
		}
	}

	public function allowHost($host) {
		$host = strtolower(trim((string)$host));

		if (strpos($host, '://') !== false) {
			$host = (string)parse_url($host, PHP_URL_HOST);
		}

		$host = preg_replace('/^www\./', '', $host);

		if ($host !== '') {
			$this->hosts[$host] = true;
		}
	}

	/**
	 * Allows one exact URL on a foreign host (courier tracking link of a
	 * verified order). Only that URL passes, not the rest of the host.
	 */
	public function allowUrl($url) {
		$key = self::urlKey($url);

		if ($key !== '') {
			$this->urls[$key] = true;
		}
	}

	/** host (lowercase, no www) + path + query; scheme and fragment ignored. */
	public static function urlKey($url) {
		$url = str_replace('&amp;', '&', trim((string)$url));

		if (substr($url, 0, 2) === '//') {
			$url = 'https:' . $url;
		} elseif (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
			$url = 'https://' . $url;
		}

		$parts = parse_url($url);

		if (!$parts || empty($parts['host'])) {
			return '';
		}

		$host = preg_replace('/^www\./', '', strtolower($parts['host']));
		$path = isset($parts['path']) ? $parts['path'] : '';
		$path = $path === '/' ? '' : $path;

		return $host . $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
	}

	public function isAllowedUrl($url) {
		$url = trim($url);

		if ($url === '') {
			return false;
		}

		// Browsers read "\\" like "/" (https://evil\\@shop/ is evil, /\\evil is //evil) while
		// parse_url() does not: never allow a backslash, control character or whitespace.
		// HTML entities / %-encoded separators would be decoded by some renderers: refuse them too.
		if (preg_match('/[\\\\\x00-\x20\x7F]|&#|&[a-z]+;|%(2f|5c|40|3a|09|0a|0d|00)/i', $url)) {
			return false;
		}

		if ($this->urls && isset($this->urls[self::urlKey($url)]) && preg_match('/^(https?:)?\/\/|^www\./i', $url) && !self::authorityHasUser($url)) {
			return true;
		}

		// Relative link on the same host ("/path", "index.php?...", "#x") - but not "//host",
		// not "scheme:", not a bare domain, and no ':' before the first '/' (e.g. "javascript&colon;")
		if (substr($url, 0, 2) !== '//' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) && !preg_match('/^www\./i', $url) && !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.(' . self::TLDS . ')(\/|$|\?|#|:)/i', $url)) {
			$head = preg_split('~[/?#]~', $url, 2);
			return strpos($head[0], ':') === false;
		}

		if (preg_match('/^(mailto|tel):[^\/]/i', $url)) {
			return true;
		}

		if (substr($url, 0, 2) === '//') {
			$url = 'https:' . $url;
		} elseif (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
			$url = 'https://' . $url;
		}

		if (!preg_match('~^https?://~i', $url) || self::authorityHasUser($url)) {
			return false;
		}

		$host = strtolower((string)parse_url($url, PHP_URL_HOST));
		$host = preg_replace('/^www\./', '', $host);

		return $host !== '' && isset($this->hosts[$host]);
	}

	/** "user@" (or anything with '@') in the authority part of a URL. */
	private static function authorityHasUser($url) {
		$rest = preg_replace('~^([a-z][a-z0-9+.-]*:)?//~i', '', (string)$url);
		$authority = preg_split('~[/?#]~', $rest, 2);

		return strpos($authority[0], '@') !== false;
	}

	/**
	 * Makes links in text that is forwarded to staff (Telegram, e-mail) not
	 * clickable: "https://evil.com/x" -> "hxxps://evil[.]com/x", bare
	 * "evil.com" -> "evil[.]com". Hosts in $allowedHosts stay as they are.
	 */
	public static function defang($text, array $allowedHosts = array()) {
		$allowed = array();

		foreach ($allowedHosts as $h) {
			$h = preg_replace('/^www\./', '', strtolower(trim((string)$h)));
			if ($h !== '') {
				$allowed[$h] = true;
			}
		}

		$isAllowed = function ($host) use ($allowed) {
			$host = preg_replace('/^www\./', '', strtolower((string)$host));
			return $host !== '' && isset($allowed[$host]);
		};

		$text = preg_replace_callback('~\b((?:[a-z][a-z0-9+.-]*:)?//|www\.)([^\s<>"\'`/?#]+)([^\s<>"\'`]*)~iu', function ($m) use ($isAllowed) {
			$authority = $m[2];
			$host = preg_replace('/^.*@/', '', $authority);
			$host = preg_replace('/:\d+$/', '', $host);
			if ($isAllowed(strtolower($m[1]) === 'www.' ? 'www.' . $host : $host) && strpos($m[0], '\\') === false && strpos($authority, '@') === false) {
				return $m[0];
			}
			$scheme = preg_replace('/^http/i', 'hxxp', $m[1]);
			return $scheme . str_replace(array('.', '@'), array('[.]', '[at]'), $authority) . str_replace('.', '[.]', $m[3]);
		}, (string)$text);

		return preg_replace_callback('~(?<![\w@./:\[-])((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:' . self::TLDS . '))(?![\w-]|\.[a-z0-9])~iu', function ($m) use ($isAllowed) {
			return $isAllowed($m[1]) ? $m[1] : str_replace('.', '[.]', $m[1]);
		}, $text);
	}

	/** Filters a complete text. */
	public function filter($text) {
		$self = $this;

		// 1. markdown links
		$text = preg_replace_callback('/\[([^\[\]\n]*)\]\(\s*<?([^()\s<>]*)>?(?:\s+"[^"\n]*")?\s*\)/u', function ($m) use ($self) {
			if ($self->isAllowedUrl($m[2])) {
				return $m[0];
			}
			return $self->filterBare($m[1]);
		}, $text);

		// 1b. markdown links whose target contains parentheses ("[a](javascript:alert(1))")
		$text = preg_replace_callback('/\[([^\[\]\n]*)\]\(\s*([^\s()]*\([^\s]*?\)[^\s()]*)\s*\)/u', function ($m) use ($self) {
			if ($self->isAllowedUrl($m[2])) {
				return $m[0];
			}
			return $self->filterBare($m[1]);
		}, $text);

		// 2./3. bare URLs and domains outside markdown links
		$parts = preg_split('/(\[[^\[\]\n]*\]\([^()\s]*\))/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		$out = '';

		foreach ($parts as $i => $part) {
			$out .= ($i % 2) ? $part : $this->filterBare($part);
		}

		return $out;
	}

	public function filterBare($text) {
		$self = $this;

		// URLs with a scheme, protocol-relative, or www.
		$text = preg_replace_callback('~(?<![\w@./-])((?:[a-z][a-z0-9+.-]*:)?//[^\s<>"\'`]+|www\.[^\s<>"\'`]+)~iu', function ($m) use ($self) {
			$url = $m[1];
			$trail = '';
			if (preg_match('/[.,;:!?)\]]+$/', $url, $t)) {
				$trail = $t[0];
				$url = substr($url, 0, -strlen($trail));
			}
			return ($self->isAllowedUrl($url) ? $url : '') . $trail;
		}, $text);

		// "http:evil.com" (scheme without //: browsers still open it)
		$text = preg_replace_callback('~(?<![\w@./-])(https?:(?!//)[^\s<>"\'`]+)~i', function ($m) use ($self) {
			return $self->isAllowedUrl($m[1]) ? $m[1] : '';
		}, $text);

		// Bare domain names (no scheme)
		$text = preg_replace_callback('~(?<![\w@./:-])((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:' . self::TLDS . '))(?![\w-]|\.[a-z0-9])((?:[/?#][^\s<>"\'`]*)?)~iu', function ($m) use ($self) {
			$url = $m[1] . $m[2];
			$trail = '';
			if (preg_match('/[.,;:!?)\]]+$/', $url, $t)) {
				$trail = $t[0];
				$url = substr($url, 0, -strlen($trail));
			}
			return ($self->isAllowedUrl('https://' . $url) ? $url : '') . $trail;
		}, $text);

		return $text;
	}

	/** Streaming: add a chunk, get back the part that is safe to emit now. */
	public function push($chunk) {
		$this->buffer .= (string)$chunk;

		$cut = $this->safeCut($this->buffer);

		if ($cut <= 0) {
			if (strlen($this->buffer) > self::MAX_HOLD) {
				return $this->flush();
			}
			return '';
		}

		$ready = substr($this->buffer, 0, $cut);
		$this->buffer = (string)substr($this->buffer, $cut);

		return $this->filter($ready);
	}

	/** Streaming: end of text. */
	public function flush() {
		$rest = $this->buffer;
		$this->buffer = '';

		return $rest === '' ? '' : $this->filter($rest);
	}

	/** Byte offset up to which the buffer can be emitted. */
	private function safeCut($buffer) {
		// last whitespace (ASCII, so the cut is UTF-8 safe)
		if (!preg_match_all('/\s/', $buffer, $m, PREG_OFFSET_CAPTURE)) {
			return 0;
		}

		$last = end($m[0]);
		$cut = $last[1] + 1;

		// Do not cut inside an unfinished markdown link "[label](url)"
		$head = substr($buffer, 0, $cut);
		$open = strrpos($head, '[');

		if ($open !== false) {
			$tail = substr($buffer, $open);

			if (preg_match('/^\[[^\[\]\n]*\]\([^()\s]*\)/u', $tail, $lm)) {
				// finished link: emit it whole or not at all
				if ($open + strlen($lm[0]) > $cut) {
					$cut = $open;
				}
			} elseif (!preg_match('/^\[[^\[\]\n]*\](?!\(|$)/u', $tail) && !preg_match('/^\[[^\]\n]*\n/u', $tail)) {
				// unfinished "[label" / "[label](url" - hold back
				$cut = $open;
			}
		}

		return $cut;
	}
}
