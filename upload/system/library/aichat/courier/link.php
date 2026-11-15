<?php
namespace Aichat\Courier;

/** Courier without an API: only the tracking link (track_url template with {number}). */
class Link implements CourierInterface {
	protected $config;

	/** @param array $config {code, name, type, hosts[], track_url} */
	public function __construct(array $config) {
		$this->config = $config;
	}

	public function track($number, $lang) {
		return null;
	}

	public function trackingUrl($number) {
		$template = isset($this->config['track_url']) ? (string)$this->config['track_url'] : '';

		if ($template === '' || !preg_match('#^https?://#i', $template)) {
			return '';
		}

		if (strpos($template, '{number}') === false) {
			return $template;
		}

		return str_replace('{number}', rawurlencode((string)$number), $template);
	}
}
