<?php
namespace Aichat;

/**
 * Visitor images (stage 4).
 *
 *  - accepted: JPEG, PNG, WebP only, recognised by their magic bytes AND by
 *    getimagesize() (never by the file name or the browser's MIME type);
 *  - decoded and re-encoded with GD as a fresh JPEG (no metadata, no
 *    trailing payload), longest edge <= MAX_EDGE, plus a small thumbnail;
 *  - stored under DIR_STORAGE/aichat/uploads/<yyyymm>/<random>.jpg (outside
 *    the web root on a normal install; the directory also gets a deny-all
 *    .htaccess), file names never come from the visitor;
 *  - served only through the image route to the owning conversation (and the admin).
 */
class Uploads {
	const MAX_BYTES = 5242880;      // 5 MB per file
	const MAX_EDGE = 1568;          // px, longest edge sent to the model
	const THUMB_EDGE = 240;
	const MAX_PIXELS = 16000000;    // decoding guard (16 MP ~ 64 MB of GD memory; the widget sends <= 1568 px)
	const MAX_PER_MESSAGE = 3;
	const MAX_PER_CONVERSATION = 30;
	const QUALITY = 85;

	private $db;

	public function __construct($registry) {
		$this->db = $registry->get('db');
	}

	public static function dir() {
		return rtrim(DIR_STORAGE, '/') . '/aichat/uploads/';
	}

	/**
	 * Validates and stores an uploaded file for a conversation.
	 *
	 * @return array {upload_id} or {error: too_large|bad_type|bad_image|limit|storage}
	 */
	public function store($conversationId, $tmpFile, $declaredSize = 0) {
		if (!is_string($tmpFile) || $tmpFile === '' || !is_file($tmpFile)) {
			return array('error' => 'bad_image');
		}

		$size = (int)filesize($tmpFile);

		if ($size <= 0) {
			return array('error' => 'bad_image');
		}

		if ($size > self::MAX_BYTES || (int)$declaredSize > self::MAX_BYTES) {
			return array('error' => 'too_large');
		}

		if ($this->countFor($conversationId) >= self::MAX_PER_CONVERSATION) {
			return array('error' => 'limit');
		}

		$mime = self::sniff($tmpFile);

		if ($mime === '') {
			return array('error' => 'bad_type');
		}

		$info = @getimagesize($tmpFile);

		if (!$info || empty($info[0]) || empty($info[1]) || !isset($info['mime']) || $info['mime'] !== $mime) {
			return array('error' => 'bad_image');
		}

		if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
			return array('error' => 'too_large');
		}

		if (!function_exists('imagecreatefromstring')) {
			return array('error' => 'storage');
		}

		$src = null;

		if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
			$src = @imagecreatefromjpeg($tmpFile);
		} elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
			$src = @imagecreatefrompng($tmpFile);
		} elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
			$src = @imagecreatefromwebp($tmpFile);
		}

		if (!$src) {
			return array('error' => $mime === 'image/webp' ? 'bad_type' : 'bad_image');
		}

		if ($mime === 'image/jpeg') {
			$src = self::orient($src, $tmpFile);
		}

		$name = date('Ym') . '/' . bin2hex(random_bytes(16));
		$dir = self::dir() . date('Ym');

		if (!self::protect(self::dir()) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
			imagedestroy($src);
			return array('error' => 'storage');
		}

		$full = self::dir() . $name . '.jpg';
		$thumb = self::dir() . $name . '_t.jpg';
		$ok = self::writeJpeg($src, $full, self::MAX_EDGE) && self::writeJpeg($src, $thumb, self::THUMB_EDGE);
		imagedestroy($src);

		if (!$ok) {
			@unlink($full);
			@unlink($thumb);
			return array('error' => 'storage');
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_upload` SET `conversation_id` = '" . (int)$conversationId . "', `file` = '" . $this->db->escape($name) . "', `mime` = 'image/jpeg', `bytes` = '" . (int)filesize($full) . "', `date_added` = NOW()");

		return array('upload_id' => (int)$this->db->getLastId());
	}

	/** JPEG FF D8 FF, PNG 89 50 4E 47 0D 0A 1A 0A, WebP RIFF....WEBP; anything else -> ''. */
	public static function sniff($file) {
		$head = (string)@file_get_contents($file, false, null, 0, 16);

		if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
			return 'image/jpeg';
		}

		if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
			return 'image/png';
		}

		if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
			return 'image/webp';
		}

		return '';
	}

	/** Applies the EXIF orientation of phone photos (the re-encoded file has no EXIF). */
	private static function orient($img, $file) {
		if (!function_exists('exif_read_data')) {
			return $img;
		}

		$exif = @exif_read_data($file);
		$o = is_array($exif) && isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
		$angle = $o === 3 ? 180 : ($o === 6 ? -90 : ($o === 8 ? 90 : 0));

		if ($angle) {
			$rotated = @imagerotate($img, $angle, 0);
			if ($rotated) {
				imagedestroy($img);
				return $rotated;
			}
		}

		return $img;
	}

	/** Resizes onto a white canvas (transparent PNG/WebP) and writes a JPEG. */
	private static function writeJpeg($src, $file, $edge) {
		$w = imagesx($src);
		$h = imagesy($src);
		$scale = min(1, $edge / max($w, $h));
		$nw = max(1, (int)round($w * $scale));
		$nh = max(1, (int)round($h * $scale));

		$dst = imagecreatetruecolor($nw, $nh);
		imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
		$ok = @imagejpeg($dst, $file, self::QUALITY);
		imagedestroy($dst);

		if ($ok) {
			@chmod($file, 0600);
		}

		return $ok;
	}

	private static function protect($dir) {
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
			return false;
		}

		foreach (array(dirname($dir), $dir) as $d) {
			if (!is_file($d . '/.htaccess')) {
				@file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
			}
			if (!is_file($d . '/index.html')) {
				@file_put_contents($d . '/index.html', '');
			}
		}

		return true;
	}

	// ---------------------------------------------------------------- lookup

	/** Upload row of THIS conversation, or null (another conversation's id is simply not found). */
	public function get($uploadId, $conversationId) {
		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aichat_upload` WHERE `upload_id` = '" . (int)$uploadId . "' AND `conversation_id` = '" . (int)$conversationId . "'")->row;

		return $row && self::validName($row['file']) ? $row : null;
	}

	/** Admin use: any upload by id (the admin controller does its own permission check). */
	public function getAny($uploadId) {
		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aichat_upload` WHERE `upload_id` = '" . (int)$uploadId . "'")->row;

		return $row && self::validName($row['file']) ? $row : null;
	}

	/** Only ids that belong to the conversation, unique, in order, at most $max. */
	public function filterOwned(array $ids, $conversationId, $max = self::MAX_PER_MESSAGE) {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if (!$ids) {
			return array();
		}

		$rows = $this->db->query("SELECT `upload_id` FROM `" . DB_PREFIX . "aichat_upload` WHERE `conversation_id` = '" . (int)$conversationId . "' AND `upload_id` IN (" . implode(',', $ids) . ")")->rows;
		$have = array();

		foreach ($rows as $row) {
			$have[(int)$row['upload_id']] = true;
		}

		$out = array();

		foreach ($ids as $id) {
			if (isset($have[$id])) {
				$out[] = $id;
			}
		}

		return array_slice($out, 0, $max);
	}

	public static function validName($name) {
		return (bool)preg_match('#^\d{6}/[a-f0-9]{32}$#', (string)$name);
	}

	/** Absolute path of an upload row's file (full or thumbnail), or '' when missing. */
	public static function path(array $row, $thumb = false) {
		if (!self::validName($row['file'])) {
			return '';
		}

		$file = self::dir() . $row['file'] . ($thumb ? '_t' : '') . '.jpg';

		return is_file($file) ? $file : '';
	}

	/** Base64 image content block for the Messages API, or null. */
	public static function contentBlock(array $row) {
		$file = self::path($row, false);

		if ($file === '') {
			return null;
		}

		return array('type' => 'image', 'source' => array('type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode((string)file_get_contents($file))));
	}

	public function countFor($conversationId) {
		return (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "aichat_upload` WHERE `conversation_id` = '" . (int)$conversationId . "'")->row['n'];
	}

	// ---------------------------------------------------------------- deletion

	public function deleteRow(array $row) {
		foreach (array(false, true) as $thumb) {
			$file = self::path($row, $thumb);
			if ($file !== '') {
				@unlink($file);
			}
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "aichat_upload` WHERE `upload_id` = '" . (int)$row['upload_id'] . "'");
	}

	public function deleteForConversation($conversationId) {
		foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "aichat_upload` WHERE `conversation_id` = '" . (int)$conversationId . "'")->rows as $row) {
			$this->deleteRow($row);
		}
	}

	/** Lazy retention: uploads older than $days (0 = keep). Returns the number deleted. */
	public function deleteOlderThan($days, $limit = 500) {
		if ((int)$days <= 0) {
			return 0;
		}

		$rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aichat_upload` WHERE `date_added` < NOW() - INTERVAL " . (int)$days . " DAY ORDER BY `upload_id` LIMIT " . (int)$limit)->rows;

		foreach ($rows as $row) {
			$this->deleteRow($row);
		}

		return count($rows);
	}

	/** Uninstall: every stored file. */
	public static function deleteAllFiles() {
		$dir = self::dir();

		foreach ((array)glob($dir . '*', GLOB_ONLYDIR) as $month) {
			foreach ((array)glob($month . '/*.jpg') as $file) {
				@unlink($file);
			}
			@rmdir($month);
		}
	}
}
