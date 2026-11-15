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

	const TLDS = 'com|net|org|info|biz|eu|bg|gr|ro|de|at|ch|uk|fr|it|es|pt|nl|be|pl|cz|sk|hu|rs|mk|al|tr|cy|ru|ua|us|ca|io|co|me|tv|app|dev|xyz|shop|store|online|site|top|club|link|ly|to|gl|be|cn|jp';

	private $hosts = array();
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

	public function isAllowedUrl($url) {
		$url = trim($url);

		if ($url === '') {
			return false;
		}

		// Relative link on the same host ("/path", "index.php?...") - but not "//host"
		if (substr($url, 0, 2) !== '//' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) && !preg_match('/^www\./i', $url) && !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.(' . self::TLDS . ')(\/|$|\?|#|:)/i', $url)) {
			return true;
		}

		if (preg_match('/^(mailto|tel):/i', $url)) {
			return true;
		}

		if (substr($url, 0, 2) === '//') {
			$url = 'https:' . $url;
		} elseif (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
			$url = 'https://' . $url;
		}

		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

		if ($scheme !== 'http' && $scheme !== 'https') {
			return false;
		}

		$host = strtolower((string)parse_url($url, PHP_URL_HOST));
		$host = preg_replace('/^www\./', '', $host);

		return $host !== '' && isset($this->hosts[$host]);
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
