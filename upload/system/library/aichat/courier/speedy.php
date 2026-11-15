<?php
namespace Aichat\Courier;

/**
 * Speedy (speedy.bg) over the public REST API v1:
 *   POST https://api.speedy.bg/v1/track
 *   {"userName", "password", "language": "BG"|"EN", "parcels": [{"id": "<number>"}]}
 *   -> {"parcels": [{"parcelId", "operations": [{"dateTime", "operationCode",
 *        "description", "comment", "place"?: {...}}], "error"?: {...}}], "error"?: {...}}
 *
 * NOTE: written from the published documentation as remembered; the API
 * host was not reachable while this was written, so the mapping is
 * deliberately defensive (unknown shapes -> null -> tracking link only).
 *
 * Base URL can be overridden for tests with the constant AICHAT_SPEEDY_BASE.
 * Results are cached for 10 minutes (OpenCart cache) to keep load low.
 */
class Speedy extends Link {
	const BASE = 'https://api.speedy.bg/v1';
	const CACHE_TTL = 600;
	/** Operation codes that mean "delivered" in Speedy's nomenclature. */
	const DELIVERED = array(-14);
	/** Returned to sender. */
	const RETURNED = array(-15, 111);

	private $username;
	private $password;
	private $cache;

	/**
	 * @param array $config  courier config (track_url for the link)
	 * @param array $secret  {username, password}
	 * @param object|null $cache OpenCart cache
	 */
	public function __construct(array $config, array $secret, $cache = null) {
		parent::__construct($config);
		$this->username = isset($secret['username']) ? (string)$secret['username'] : '';
		$this->password = isset($secret['password']) ? (string)$secret['password'] : '';
		$this->cache = $cache;
	}

	public static function base() {
		return defined('AICHAT_SPEEDY_BASE') ? rtrim((string)constant('AICHAT_SPEEDY_BASE'), '/') : self::BASE;
	}

	public function track($number, $lang) {
		$number = preg_replace('/[^A-Za-z0-9]/', '', (string)$number);

		if ($number === '' || $this->username === '' || $this->password === '' || !function_exists('curl_init')) {
			return null;
		}

		$language = strtolower((string)$lang) === 'bg' ? 'BG' : 'EN';
		$key = 'aichat.speedy.' . md5($number . '|' . $language);

		if ($this->cache) {
			$cached = $this->cache->get($key);
			if (is_array($cached) && isset($cached['status'])) {
				return $cached;
			}
		}

		$body = json_encode(array(
			'userName'          => $this->username,
			'password'          => $this->password,
			'language'          => $language,
			'parcels'           => array(array('id' => $number)),
			'lastOperationOnly' => false,
		));

		$ch = curl_init(self::base() . '/track');
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_TIMEOUT        => 8,
		));
		$raw = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false || $status < 200 || $status >= 300) {
			return null;
		}

		$result = self::map(json_decode((string)$raw, true));

		if ($result && $this->cache) {
			$this->cache->set($key, $result);
		}

		return $result;
	}

	/** Response -> normalised status, or null for errors / unknown shapes. Public for tests. */
	public static function map($json) {
		if (!is_array($json) || !empty($json['error'])) {
			return null;
		}

		$parcel = null;

		if (isset($json['parcels'][0]) && is_array($json['parcels'][0])) {
			$parcel = $json['parcels'][0];
		} elseif (isset($json['operations'])) {
			$parcel = $json;
		}

		if (!$parcel || !empty($parcel['error'])) {
			return null;
		}

		$ops = isset($parcel['operations']) && is_array($parcel['operations']) ? $parcel['operations'] : array();
		$ops = array_values(array_filter($ops, 'is_array'));

		if (!$ops) {
			return array('status' => 'unknown', 'last_event' => '', 'last_time' => '', 'location' => '', 'delivered' => false);
		}

		// newest last (sort by time when the API gives one)
		usort($ops, function ($a, $b) {
			$ta = isset($a['dateTime']) ? strtotime((string)$a['dateTime']) : 0;
			$tb = isset($b['dateTime']) ? strtotime((string)$b['dateTime']) : 0;
			return $ta - $tb;
		});

		$delivered = false;
		$returned = false;

		foreach ($ops as $op) {
			$code = isset($op['operationCode']) ? (int)$op['operationCode'] : 0;
			if (in_array($code, self::DELIVERED, true)) {
				$delivered = true;
			}
			if (in_array($code, self::RETURNED, true)) {
				$returned = true;
			}
		}

		$last = end($ops);
		$event = '';
		foreach (array('description', 'operationDescription', 'comment') as $field) {
			if (!empty($last[$field]) && is_string($last[$field])) {
				$event = $last[$field];
				break;
			}
		}

		$time = '';
		if (!empty($last['dateTime']) && strtotime((string)$last['dateTime'])) {
			$time = date('Y-m-d H:i', strtotime((string)$last['dateTime']));
		}

		$location = '';
		if (isset($last['place'])) {
			$place = $last['place'];
			if (is_string($place)) {
				$location = $place;
			} elseif (is_array($place)) {
				foreach (array('name', 'siteName', 'city', 'officeName') as $field) {
					if (!empty($place[$field]) && is_string($place[$field])) {
						$location = $place[$field];
						break;
					}
				}
			}
		}

		return array(
			'status'     => $delivered ? 'delivered' : ($returned ? 'returned' : 'in_transit'),
			'last_event' => self::clip($event, 200),
			'last_time'  => $time,
			'location'   => self::clip($location, 100),
			'delivered'  => $delivered,
		);
	}

	private static function clip($text, $max) {
		$text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));

		return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
	}
}
