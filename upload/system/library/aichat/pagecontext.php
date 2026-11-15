<?php
namespace Aichat;

/**
 * Resolves the page URL the visitor is on into what they are looking at.
 *
 * Handles plain route URLs (index.php?route=product/product&product_id=42)
 * and SEO URLs (/toys/plush-bunny) through `seo_url`, bound to the current
 * store and preferring the current language. Only the store's own host is
 * resolved. Entity details come from Tools (store-bound, status = 1).
 *
 * Result: array(type, id?, title?, details?, path?) where type is one of
 * product | category | information | manufacturer | search | cart |
 * checkout | account | home | other.
 */
class PageContext {
	private $db;
	private $config;
	private $tools;
	private $storeId;
	private $languageId;

	public function __construct($registry, Tools $tools = null) {
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
		$this->storeId = (int)$this->config->get('config_store_id');
		$this->languageId = (int)$this->config->get('config_language_id');
		$this->tools = $tools ? $tools : new Tools($registry, $this->storeId, $this->languageId);
	}

	public function resolve($url) {
		$url = trim((string)$url);
		$result = array('type' => 'other');

		if ($url === '' || strlen($url) > 2000) {
			return $result;
		}

		$parts = parse_url($url);

		if ($parts === false) {
			return $result;
		}

		// Only our own store
		if (isset($parts['host']) && !$this->isOwnHost($parts['host'])) {
			return $result;
		}

		$query = array();

		if (isset($parts['query'])) {
			parse_str(str_replace('&amp;', '&', $parts['query']), $query);
		}

		$path = isset($parts['path']) ? $parts['path'] : '/';
		$basePath = $this->basePath();

		if ($basePath !== '/' && strpos($path, $basePath) === 0) {
			$path = substr($path, strlen($basePath) - 1);
		}

		$get = array();

		if (isset($query['route'])) {
			$get = $query;
		} elseif (isset($query['_route_'])) {
			$get = $this->decodeSeo($query['_route_']) + $query;
		} else {
			$trimmed = trim($path, '/');
			if ($trimmed !== '' && $trimmed !== 'index.php') {
				$get = $this->decodeSeo($trimmed) + $query;
			} else {
				$get = $query + array('route' => 'common/home');
			}
		}

		return $this->describe($get);
	}

	/** seo_url keywords -> request params (like catalog/controller/startup/seo_url.php). */
	private function decodeSeo($route) {
		$get = array();

		foreach (explode('/', $route) as $segment) {
			$segment = rawurldecode($segment);
			$segment = preg_replace('/\.html?$/i', '', $segment);

			if ($segment === '' || $segment === 'index.php') {
				continue;
			}

			$row = $this->db->query("SELECT `query` FROM `" . DB_PREFIX . "seo_url` WHERE `keyword` = '" . $this->db->escape($segment) . "' AND `store_id` = '" . (int)$this->storeId . "' ORDER BY (`language_id` = '" . (int)$this->languageId . "') DESC LIMIT 1")->row;

			if (!$row) {
				// unknown segment (language prefix, filter, theme route ...) - skip it
				continue;
			}

			$pair = explode('=', $row['query'], 2);

			if (count($pair) === 2) {
				if ($pair[0] === 'category_id') {
					$get['path'] = isset($get['path']) ? $get['path'] . '_' . (int)$pair[1] : (string)(int)$pair[1];
				} elseif (in_array($pair[0], array('product_id', 'manufacturer_id', 'information_id'), true)) {
					$get[$pair[0]] = (int)$pair[1];
				}
			} else {
				$get['route'] = $row['query'];
			}
		}

		if (!isset($get['route'])) {
			if (isset($get['product_id'])) {
				$get['route'] = 'product/product';
			} elseif (isset($get['path'])) {
				$get['route'] = 'product/category';
			} elseif (isset($get['manufacturer_id'])) {
				$get['route'] = 'product/manufacturer/info';
			} elseif (isset($get['information_id'])) {
				$get['route'] = 'information/information';
			} else {
				$get['route'] = 'other';
			}
		}

		return $get;
	}

	private function describe(array $get) {
		$route = isset($get['route']) && is_string($get['route']) ? $get['route'] : 'other';

		// product page (also when reached from a category / search / manufacturer)
		if (isset($get['product_id']) && ($route === 'product/product' || !isset($get['route']))) {
			$product = $this->tools->productSummary((int)$get['product_id']);
			if ($product) {
				return array('type' => 'product', 'id' => (int)$product['product_id'], 'title' => $product['name'], 'details' => $product);
			}
			return array('type' => 'other');
		}

		switch ($route) {
			case 'product/category':
				$path = isset($get['path']) ? (string)$get['path'] : '';
				$ids = array_filter(array_map('intval', explode('_', $path)));
				$id = $ids ? (int)end($ids) : 0;
				$name = $id ? $this->tools->categoryName($id) : null;
				if ($name !== null) {
					$paths = $this->tools->categoryPaths(array($id));
					return array('type' => 'category', 'id' => $id, 'title' => $name, 'path' => isset($paths[$id]) ? $paths[$id] : $name);
				}
				break;

			case 'information/information':
				$id = isset($get['information_id']) ? (int)$get['information_id'] : 0;
				$title = $id ? $this->tools->informationTitle($id) : null;
				if ($title !== null) {
					return array('type' => 'information', 'id' => $id, 'title' => $title);
				}
				break;

			case 'product/manufacturer/info':
				$id = isset($get['manufacturer_id']) ? (int)$get['manufacturer_id'] : 0;
				$name = $id ? $this->tools->manufacturerName($id) : null;
				if ($name !== null) {
					return array('type' => 'manufacturer', 'id' => $id, 'title' => $name);
				}
				break;

			case 'product/search':
				$term = isset($get['search']) && is_string($get['search']) ? $get['search'] : (isset($get['tag']) && is_string($get['tag']) ? $get['tag'] : '');
				$term = trim(preg_replace('/\s+/u', ' ', $term));
				if (Tools::len($term) > 100) {
					$term = function_exists('mb_substr') ? mb_substr($term, 0, 100, 'UTF-8') : substr($term, 0, 100);
				}
				return array('type' => 'search', 'title' => $term);

			case 'checkout/cart':
				return array('type' => 'cart');

			case 'checkout/checkout':
			case 'checkout/success':
				return array('type' => 'checkout');

			case 'common/home':
				return array('type' => 'home');
		}

		if (strpos($route, 'account/') === 0) {
			return array('type' => 'account');
		}

		return array('type' => 'other');
	}

	private function isOwnHost($host) {
		$host = preg_replace('/^www\./', '', strtolower($host));

		foreach (self::storeHosts($this->config) as $own) {
			if ($host === $own) {
				return true;
			}
		}

		return false;
	}

	/** Host names of the current store (config_url / config_ssl), without "www.". */
	public static function storeHosts($config) {
		$hosts = array();

		foreach (array($config->get('config_url'), $config->get('config_ssl'), defined('HTTP_SERVER') && !$config->get('config_store_id') ? HTTP_SERVER : '', defined('HTTPS_SERVER') && !$config->get('config_store_id') ? HTTPS_SERVER : '') as $url) {
			$host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
			$host = preg_replace('/^www\./', '', $host);
			if ($host !== '') {
				$hosts[$host] = $host;
			}
		}

		return array_values($hosts);
	}

	private function basePath() {
		$base = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$path = (string)parse_url((string)$base, PHP_URL_PATH);

		return $path === '' ? '/' : rtrim($path, '/') . '/';
	}

	/** Text block for the prompt: what the visitor is looking at now (data, not instructions). */
	public static function describeForPrompt(array $context) {
		switch ($context['type']) {
			case 'product':
				$d = $context['details'];
				$lines = array('The visitor is viewing a product page:', '- product_id: ' . $d['product_id'], '- name: ' . $d['name']);
				if ($d['model'] !== '') {
					$lines[] = '- model: ' . $d['model'];
				}
				if ($d['price'] !== null) {
					$lines[] = '- price: ' . $d['price'] . ($d['special'] ? ' (special price now: ' . $d['special'] . ')' : '');
				}
				$lines[] = '- in stock: ' . $d['in_stock'] . (isset($d['stock_status']) ? ' (' . $d['stock_status'] . ')' : '');
				if ($d['category'] !== '') {
					$lines[] = '- category: ' . $d['category'];
				}
				$lines[] = 'Use get_product for more details. "This product" / "it" most likely means this product.';
				return implode("\n", $lines);

			case 'category':
				return "The visitor is viewing the category \"" . $context['path'] . "\" (category_id " . $context['id'] . "). When they ask for products without more detail, search within this category first (category_id).";

			case 'information':
				return "The visitor is reading the information page \"" . $context['title'] . "\" (information_id " . $context['id'] . ").";

			case 'manufacturer':
				return "The visitor is viewing the products of the brand \"" . $context['title'] . "\".";

			case 'search':
				return "The visitor is on the store's search results page for: \"" . $context['title'] . "\".";

			case 'cart':
				return 'The visitor is viewing their shopping cart.';

			case 'checkout':
				return 'The visitor is in the checkout.';

			case 'account':
				return 'The visitor is in their customer account pages.';

			case 'home':
				return 'The visitor is on the home page.';
		}

		return 'The visitor is on another page of the store.';
	}
}
