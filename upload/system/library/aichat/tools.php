<?php
namespace Aichat;

/**
 * The only way the model reaches shop data. Every query is bound to the
 * server-side store_id / language_id / customer group, requires status = 1
 * and joins the *_to_store tables. Only whitelisted fields are returned:
 * never cost price, exact quantities, suppliers, other customers, settings,
 * SQL or file paths.
 */
class Tools {
	const MAX_SEARCH = 10;
	const MAX_CARDS = 6;
	const MAX_WORDS = 6;

	private $registry;
	private $db;
	private $config;
	private $storeId;
	private $languageId;
	private $customerGroupId;

	/** product cards collected by show_products during one reply */
	private $cards = array();
	private static $mainCategoryColumn = null;

	public function __construct($registry, $storeId = null, $languageId = null) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
		$this->storeId = $storeId === null ? (int)$this->config->get('config_store_id') : (int)$storeId;
		$this->languageId = $languageId === null ? (int)$this->config->get('config_language_id') : (int)$languageId;
		$this->customerGroupId = (int)$this->config->get('config_customer_group_id');
	}

	// ================================================================ definitions

	/**
	 * Tool definitions for the Messages API. Deterministic order and content
	 * (they sit at the very start of the cached prefix).
	 */
	public static function definitions($eagerStreaming = false) {
		$tools = array(
			array(
				'name'        => 'search_products',
				'description' => 'Search this store\'s catalog by words from the product name, model/product code, SKU or tags. Call it whenever the customer asks for a kind of product, a brand, a product code, or whether something is available, and before recommending anything. Use short keyword queries in the store\'s language (e.g. "плюшено зайче", "yoga mat 6mm", a product code). If nothing is found, try once more with fewer or different words (singular form, synonym). Returns name, model, price, special price, in stock yes/no and main category for each match.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'       => array('type' => 'string', 'description' => 'Keywords or a product code. 1-6 words.'),
						'category_id' => array('type' => 'integer', 'description' => 'Optional: only products in this category (and its subcategories). Ids come from the category tree or list_categories.'),
						'limit'       => array('type' => 'integer', 'description' => 'Max results, 1-10. Default 5.'),
					),
					'required'             => array('query'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'get_product',
				'description' => 'Full details of one product of this store: price, special price, availability, description, attributes, options, manufacturer, minimum quantity. Call it before answering detailed questions about a specific product (size, material, age, compatibility, options).',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array('type' => 'integer', 'description' => 'Product id from search_products or the page context.'),
					),
					'required'             => array('product_id'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'list_categories',
				'description' => 'List the subcategories of a category of this store (or the top-level categories when parent_id is omitted or 0). Use it when the category tree in the instructions is not detailed enough.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'parent_id' => array('type' => 'integer', 'description' => 'Parent category id; 0 or omitted = top level.'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'get_information',
				'description' => 'Read one of the store\'s information pages (delivery, payment, returns, warranty, terms, about us, contacts). Call it before answering questions about delivery times and prices, payment methods, returns or other store policies. Pass information_id if known, otherwise a keyword such as "delivery" / "доставка" / "αποστολή".',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'information_id' => array('type' => 'integer', 'description' => 'Information page id.'),
						'keyword'        => array('type' => 'string', 'description' => 'Word to look for in page titles and texts.'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'show_products',
				'description' => 'Show product cards (image, name, price, link) to the customer under your reply. Call it whenever you recommend or mention specific products - usually 2-4 - instead of writing product links yourself. Only ids returned by search_products / get_product / the page context are valid. Only products that match what the customer asked for go in the main call (label "matches"). An optional related accessory goes in a separate call with label "suggestion", after the matches, at most once per reply - never mixed into the matches.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_ids' => array(
							'type'        => 'array',
							'items'       => array('type' => 'integer'),
							'description' => 'Up to 6 product ids, in the order to show them.',
						),
						'label' => array(
							'type'        => 'string',
							'enum'        => array('matches', 'suggestion'),
							'description' => '"matches" (default): products that match the request. "suggestion": one optional related accessory, shown separately.',
						),
					),
					'required'             => array('product_ids'),
					'additionalProperties' => false,
				),
			),
		);

		if ($eagerStreaming) {
			foreach ($tools as $i => $tool) {
				$tools[$i]['eager_input_streaming'] = true;
			}
		}

		return $tools;
	}

	// ================================================================ execution

	/**
	 * Runs one tool call. Input is validated here (the client does not trust
	 * the model's JSON). Returns array('content' => string, 'is_error' => bool).
	 */
	public function execute($name, $input) {
		if (!is_array($input)) {
			return $this->error('invalid input');
		}

		try {
			switch ($name) {
				case 'search_products':
					if (!isset($input['query']) || !is_string($input['query']) || trim($input['query']) === '') {
						return $this->error('query (string) is required');
					}
					$category = isset($input['category_id']) && is_numeric($input['category_id']) ? (int)$input['category_id'] : 0;
					$limit = isset($input['limit']) && is_numeric($input['limit']) ? (int)$input['limit'] : 5;
					return $this->ok($this->searchProducts($input['query'], $category, $limit));

				case 'get_product':
					if (!isset($input['product_id']) || !is_numeric($input['product_id'])) {
						return $this->error('product_id (integer) is required');
					}
					$product = $this->getProduct((int)$input['product_id']);
					return $product ? $this->ok($product) : $this->error('product not found in this store');

				case 'list_categories':
					$parent = isset($input['parent_id']) && is_numeric($input['parent_id']) ? (int)$input['parent_id'] : 0;
					return $this->ok(array('categories' => $this->listCategories($parent)));

				case 'get_information':
					$id = isset($input['information_id']) && is_numeric($input['information_id']) ? (int)$input['information_id'] : 0;
					$keyword = isset($input['keyword']) && is_string($input['keyword']) ? $input['keyword'] : '';
					if (!$id && trim($keyword) === '') {
						return $this->error('information_id or keyword is required');
					}
					return $this->ok($this->getInformation($id, $keyword));

				case 'show_products':
					if (!isset($input['product_ids']) || !is_array($input['product_ids'])) {
						return $this->error('product_ids (array of integers) is required');
					}
					$label = isset($input['label']) && $input['label'] === 'suggestion' ? 'suggestion' : 'matches';
					return $this->ok($this->showProducts($input['product_ids'], $label));
			}
		} catch (\Exception $e) {
			return $this->error('tool failed');
		}

		return $this->error('unknown tool');
	}

	/**
	 * Card groups collected by show_products since the last call (and clears
	 * them): array of array('label' => 'matches'|'suggestion', 'items' => cards).
	 */
	public function takeCards() {
		$groups = $this->cards;
		$this->cards = array();

		return $groups;
	}

	private function ok($data) {
		return array('content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_error' => false);
	}

	private function error($message) {
		return array('content' => json_encode(array('error' => $message)), 'is_error' => true);
	}

	// ================================================================ products

	public function searchProducts($query, $categoryId = 0, $limit = 5) {
		$limit = max(1, min(self::MAX_SEARCH, (int)$limit));
		$words = self::words($query);

		if (!$words) {
			return array('query' => $query, 'products' => array());
		}

		$rows = $this->searchRows($words, $query, $categoryId, $limit + 1, true);

		// No product matches all words: rank by how many words match
		if (!$rows && count($words) > 1) {
			$rows = $this->searchRows($words, $query, $categoryId, $limit + 1, false);
		}

		$more = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);

		return array(
			'query'          => $query,
			'products'       => $this->present($rows, false),
			'more_available' => $more,
			'matched_all_words' => $rows ? (bool)$rows[0]['all_words'] : false,
		);
	}

	/** Splits a query into lowercase search words (max 6), longest first. */
	public static function words($query) {
		$query = function_exists('mb_strtolower') ? mb_strtolower((string)$query, 'UTF-8') : strtolower((string)$query);
		$parts = preg_split('/[\s,;"\'()\[\]{}!?+*|]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
		$words = array();

		foreach ($parts as $part) {
			$part = trim($part, '.-_/:#');
			$len = self::len($part);
			if ($len >= 2 || ($len === 1 && ctype_digit($part))) {
				$words[$part] = true;
			}
		}

		$words = array_keys($words);
		usort($words, function ($a, $b) {
			return Tools::len($b) - Tools::len($a);
		});

		return array_slice($words, 0, self::MAX_WORDS);
	}

	/** Crude stem for inflected languages (bg/el): "зайчета" -> "зайче", "toys" -> "toy". */
	public static function stem($word) {
		$len = self::len($word);

		if (preg_match('/\d/', $word)) {
			return $word;
		}

		if ($len >= 7) {
			return self::sub($word, 0, $len - 2);
		}

		if ($len >= 5) {
			return self::sub($word, 0, $len - 1);
		}

		return $word;
	}

	public static function len($s) {
		return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
	}

	private static function sub($s, $start, $len) {
		return function_exists('mb_substr') ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len);
	}

	private function like($word) {
		return "'%" . $this->db->escape(addcslashes($word, '%_\\')) . "%'";
	}

	/**
	 * One indexed-join query: name, model, sku and tag LIKE per word. No
	 * description scans. $all = every word must match (else any word).
	 */
	private function searchRows(array $words, $query, $categoryId, $limit, $all) {
		$conds = array();
		$scores = array();

		foreach ($words as $word) {
			$like = $this->like(self::stem($word));
			$conds[] = "(pd.name LIKE " . $like . " OR p.model LIKE " . $like . " OR p.sku LIKE " . $like . " OR pd.tag LIKE " . $like . ")";
			$scores[] = "(pd.name LIKE " . $like . ") * 3 + (pd.name LIKE " . $this->like($word) . ") + (pd.tag LIKE " . $like . ")";
		}

		$exact = $this->db->escape(trim((string)$query));
		$score = "(" . implode(' + ', $scores) . ") + (p.model = '" . $exact . "' OR p.sku = '" . $exact . "') * 20";
		$allWords = "(" . implode(' AND ', $conds) . ")";

		$sql = $this->productSelect() . ", " . $score . " AS score, " . $allWords . " AS all_words"
			. $this->productFrom()
			. " AND (" . implode($all ? ' AND ' : ' OR ', $conds) . ")";

		if ($categoryId > 0) {
			$sql .= " AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_path` cp ON (cp.category_id = p2c.category_id) WHERE p2c.product_id = p.product_id AND cp.path_id = '" . (int)$categoryId . "')";
		}

		$sql .= " ORDER BY score DESC, (p.quantity > 0) DESC, p.sort_order ASC, pd.name ASC LIMIT " . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	private function productSelect() {
		$group = (int)$this->customerGroupId;

		return "SELECT p.product_id, pd.name, p.model, p.sku, p.price, p.tax_class_id, p.quantity, p.image, p.minimum, ss.name AS stock_status,"
			. " (SELECT ps.price FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id AND ps.customer_group_id = '" . $group . "' AND ((ps.date_start = '0000-00-00' OR ps.date_start < NOW()) AND (ps.date_end = '0000-00-00' OR ps.date_end > NOW())) ORDER BY ps.priority ASC, ps.price ASC LIMIT 1) AS special,"
			. " (SELECT pd2.price FROM `" . DB_PREFIX . "product_discount` pd2 WHERE pd2.product_id = p.product_id AND pd2.customer_group_id = '" . $group . "' AND pd2.quantity = '1' AND ((pd2.date_start = '0000-00-00' OR pd2.date_start < NOW()) AND (pd2.date_end = '0000-00-00' OR pd2.date_end > NOW())) ORDER BY pd2.priority ASC, pd2.price ASC LIMIT 1) AS discount";
	}

	private function productFrom() {
		return " FROM `" . DB_PREFIX . "product` p"
			. " JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->languageId . "')"
			. " JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . (int)$this->storeId . "')"
			. " LEFT JOIN `" . DB_PREFIX . "stock_status` ss ON (ss.stock_status_id = p.stock_status_id AND ss.language_id = '" . (int)$this->languageId . "')"
			. " WHERE p.status = '1' AND p.date_available <= NOW()";
	}

	/** Store-bound product rows by id, in the given order. */
	private function productRows(array $ids) {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if (!$ids) {
			return array();
		}

		$rows = $this->db->query($this->productSelect() . $this->productFrom() . " AND p.product_id IN (" . implode(',', $ids) . ")")->rows;
		$byId = array();

		foreach ($rows as $row) {
			$byId[(int)$row['product_id']] = $row;
		}

		$out = array();

		foreach ($ids as $id) {
			if (isset($byId[$id])) {
				$out[] = $byId[$id];
			}
		}

		return $out;
	}

	/** Whitelisted product fields for the model. */
	private function present(array $rows, $withCards) {
		$paths = $this->mainCategoryPaths(array_map(function ($r) {
			return (int)$r['product_id'];
		}, $rows));

		$out = array();

		foreach ($rows as $row) {
			$prices = $this->prices($row);
			$item = array(
				'product_id' => (int)$row['product_id'],
				'name'       => $this->text($row['name']),
				'model'      => $this->text($row['model']),
				'price'      => $prices['price'],
				'special'    => $prices['special'],
				'in_stock'   => (int)$row['quantity'] > 0 ? 'yes' : 'no',
				'category'   => isset($paths[(int)$row['product_id']]) ? $paths[(int)$row['product_id']] : '',
			);

			if ((int)$row['quantity'] <= 0 && !empty($row['stock_status'])) {
				$item['stock_status'] = $this->text($row['stock_status']);
			}

			if ($prices['price'] === null) {
				$item['price_note'] = 'prices are shown to logged-in customers only';
			}

			if (!empty($row['sku']) && $row['sku'] !== $row['model']) {
				$item['sku'] = $this->text($row['sku']);
			}

			$out[] = $item;
		}

		return $out;
	}

	/** Price/special formatted like the storefront: tax per config_tax, session currency. */
	public function prices(array $row) {
		$customer = $this->registry->get('customer');

		if ($this->config->get('config_customer_price') && !($customer && $customer->isLogged())) {
			return array('price' => null, 'special' => null);
		}

		$base = (isset($row['discount']) && $row['discount'] !== null && $row['discount'] !== '') ? $row['discount'] : $row['price'];
		$special = (isset($row['special']) && (float)$row['special'] > 0) ? $this->money($row['special'], $row['tax_class_id']) : null;

		return array('price' => $this->money($base, $row['tax_class_id']), 'special' => $special);
	}

	private function money($amount, $taxClassId) {
		$tax = $this->registry->get('tax');
		$currency = $this->registry->get('currency');
		$session = $this->registry->get('session');

		$code = ($session && isset($session->data['currency'])) ? $session->data['currency'] : $this->config->get('config_currency');
		$value = $tax ? $tax->calculate($amount, $taxClassId, $this->config->get('config_tax')) : $amount;

		return $currency ? $currency->format($value, $code) : (string)round($value, 2);
	}

	/**
	 * Main category path ("Toys > Plush toys") per product: the
	 * `main_category` flag when the shop has that column (ocStore and some
	 * themes), otherwise the deepest enabled category of this store.
	 */
	public function mainCategoryPaths(array $productIds) {
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

		if (!$productIds) {
			return array();
		}

		$main = $this->hasMainCategoryColumn() ? ", p2c.main_category" : ", 0 AS main_category";

		$rows = $this->db->query("SELECT p2c.product_id, p2c.category_id" . $main . ", (SELECT MAX(cp.level) FROM `" . DB_PREFIX . "category_path` cp WHERE cp.category_id = p2c.category_id) AS depth"
			. " FROM `" . DB_PREFIX . "product_to_category` p2c"
			. " JOIN `" . DB_PREFIX . "category` c ON (c.category_id = p2c.category_id AND c.status = '1')"
			. " JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = p2c.category_id AND c2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE p2c.product_id IN (" . implode(',', $productIds) . ")")->rows;

		$best = array();

		foreach ($rows as $row) {
			$pid = (int)$row['product_id'];
			$rank = ((int)$row['main_category'] ? 1000 : 0) + (int)$row['depth'];
			if (!isset($best[$pid]) || $rank > $best[$pid][1]) {
				$best[$pid] = array((int)$row['category_id'], $rank);
			}
		}

		$names = $this->categoryPaths(array_map(function ($b) {
			return $b[0];
		}, $best));

		$out = array();

		foreach ($best as $pid => $b) {
			$out[$pid] = isset($names[$b[0]]) ? $names[$b[0]] : '';
		}

		return $out;
	}

	/** "Parent > Child" names for category ids. */
	public function categoryPaths(array $categoryIds) {
		$categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));

		if (!$categoryIds) {
			return array();
		}

		$rows = $this->db->query("SELECT cp.category_id, cd.name FROM `" . DB_PREFIX . "category_path` cp"
			. " JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = cp.path_id AND cd.language_id = '" . (int)$this->languageId . "')"
			. " WHERE cp.category_id IN (" . implode(',', $categoryIds) . ") ORDER BY cp.category_id, cp.level ASC")->rows;

		$out = array();

		foreach ($rows as $row) {
			$id = (int)$row['category_id'];
			$out[$id] = (isset($out[$id]) ? $out[$id] . ' > ' : '') . $this->text($row['name']);
		}

		return $out;
	}

	private function hasMainCategoryColumn() {
		if (self::$mainCategoryColumn === null) {
			$query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product_to_category` LIKE 'main_category'");
			self::$mainCategoryColumn = (bool)$query->num_rows;
		}

		return self::$mainCategoryColumn;
	}

	public function getProduct($productId) {
		$rows = $this->productRows(array($productId));

		if (!$rows) {
			return null;
		}

		$row = $rows[0];
		$items = $this->present(array($row), false);
		$product = $items[0];

		$extra = $this->db->query("SELECT pd.description, m.name AS manufacturer FROM `" . DB_PREFIX . "product` p"
			. " JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->languageId . "')"
			. " LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (m.manufacturer_id = p.manufacturer_id)"
			. " WHERE p.product_id = '" . (int)$productId . "'")->row;

		if (!empty($extra['manufacturer'])) {
			$product['manufacturer'] = $this->text($extra['manufacturer']);
		}

		if ((int)$row['minimum'] > 1) {
			$product['minimum_quantity'] = (int)$row['minimum'];
		}

		$product['description'] = $this->plain(isset($extra['description']) ? $extra['description'] : '', 1500);

		// Attributes
		$attributes = $this->db->query("SELECT agd.name AS grp, ad.name, pa.text FROM `" . DB_PREFIX . "product_attribute` pa"
			. " JOIN `" . DB_PREFIX . "attribute` a ON (a.attribute_id = pa.attribute_id)"
			. " JOIN `" . DB_PREFIX . "attribute_description` ad ON (ad.attribute_id = a.attribute_id AND ad.language_id = '" . (int)$this->languageId . "')"
			. " LEFT JOIN `" . DB_PREFIX . "attribute_group_description` agd ON (agd.attribute_group_id = a.attribute_group_id AND agd.language_id = '" . (int)$this->languageId . "')"
			. " WHERE pa.product_id = '" . (int)$productId . "' AND pa.language_id = '" . (int)$this->languageId . "'"
			. " ORDER BY a.sort_order, ad.name LIMIT 40")->rows;

		foreach ($attributes as $a) {
			$product['attributes'][] = array('name' => $this->text($a['name']), 'value' => $this->text($a['text']));
		}

		// Options: names and value names only (no quantities, no weights)
		$options = $this->db->query("SELECT po.product_option_id, od.name, o.type, po.required FROM `" . DB_PREFIX . "product_option` po"
			. " JOIN `" . DB_PREFIX . "option` o ON (o.option_id = po.option_id)"
			. " JOIN `" . DB_PREFIX . "option_description` od ON (od.option_id = o.option_id AND od.language_id = '" . (int)$this->languageId . "')"
			. " WHERE po.product_id = '" . (int)$productId . "' ORDER BY o.sort_order LIMIT 20")->rows;

		foreach ($options as $o) {
			$values = $this->db->query("SELECT ovd.name, pov.quantity, pov.subtract, pov.price, pov.price_prefix FROM `" . DB_PREFIX . "product_option_value` pov"
				. " JOIN `" . DB_PREFIX . "option_value` ov ON (ov.option_value_id = pov.option_value_id)"
				. " JOIN `" . DB_PREFIX . "option_value_description` ovd ON (ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . (int)$this->languageId . "')"
				. " WHERE pov.product_option_id = '" . (int)$o['product_option_id'] . "' ORDER BY ov.sort_order LIMIT 50")->rows;

			$list = array();

			foreach ($values as $v) {
				$value = array('name' => $this->text($v['name']));
				if ((int)$v['subtract'] && (int)$v['quantity'] <= 0) {
					$value['in_stock'] = 'no';
				}
				if ((float)$v['price'] > 0 && $product['price'] !== null) {
					$value['price_change'] = $v['price_prefix'] . $this->money($v['price'], $row['tax_class_id']);
				}
				$list[] = $value;
			}

			$product['options'][] = array('name' => $this->text($o['name']), 'required' => (bool)$o['required'], 'values' => $list);
		}

		return $product;
	}

	/** Short summary for the page context. */
	public function productSummary($productId) {
		$rows = $this->productRows(array($productId));

		if (!$rows) {
			return null;
		}

		$items = $this->present($rows, false);

		return $items[0];
	}

	// ================================================================ cards

	public function showProducts(array $ids, $label = 'matches') {
		$ids = array_slice(array_values(array_filter(array_map('intval', $ids))), 0, self::MAX_CARDS);
		$rows = $this->productRows($ids);
		$shown = array();
		$items = array();

		foreach ($rows as $row) {
			$items[] = $this->card($row);
			$shown[] = (int)$row['product_id'];
		}

		if ($items) {
			$this->cards[] = array('label' => $label === 'suggestion' ? 'suggestion' : 'matches', 'items' => $items);
		}

		return array(
			'shown'     => $shown,
			'not_found' => array_values(array_diff($ids, $shown)),
			'note'      => 'Cards with picture, price and link are displayed under your reply. Do not repeat links.',
		);
	}

	/** Card for the browser: {product_id, name, href, image, price, special|null} */
	public function card(array $row) {
		$url = $this->registry->get('url');
		$prices = $this->prices($row);
		$theme = $this->config->get('config_theme');
		$width = (int)$this->config->get('theme_' . $theme . '_image_product_width');
		$height = (int)$this->config->get('theme_' . $theme . '_image_product_height');
		$width = $width > 0 ? $width : 228;
		$height = $height > 0 ? $height : 228;

		$image = '';
		$load = $this->registry->get('load');

		if ($load && defined('DIR_IMAGE')) {
			$load->model('tool/image');
			$model = $this->registry->get('model_tool_image');
			$file = (!empty($row['image']) && is_file(DIR_IMAGE . $row['image'])) ? $row['image'] : 'placeholder.png';
			$image = $model ? (string)$model->resize($file, $width, $height) : '';
		}

		return array(
			'product_id' => (int)$row['product_id'],
			'name'       => $this->text($row['name']),
			'href'       => $url ? str_replace('&amp;', '&', $url->link('product/product', 'product_id=' . (int)$row['product_id'])) : '',
			'image'      => $image,
			'price'      => $prices['price'] === null ? '' : $prices['price'],
			'special'    => $prices['special'],
		);
	}

	/** Cards for stored product ids (history replay). */
	public function cards(array $ids) {
		$out = array();

		foreach ($this->productRows($ids) as $row) {
			$out[] = $this->card($row);
		}

		return $out;
	}

	// ================================================================ categories

	public function listCategories($parentId = 0) {
		$rows = $this->db->query("SELECT c.category_id, cd.name, (SELECT COUNT(*) FROM `" . DB_PREFIX . "category` c3 JOIN `" . DB_PREFIX . "category_to_store` c3s ON (c3s.category_id = c3.category_id AND c3s.store_id = '" . (int)$this->storeId . "') WHERE c3.parent_id = c.category_id AND c3.status = '1') AS children"
			. " FROM `" . DB_PREFIX . "category` c"
			. " JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->languageId . "')"
			. " JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = c.category_id AND c2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE c.parent_id = '" . (int)$parentId . "' AND c.status = '1' ORDER BY c.sort_order, cd.name LIMIT 200")->rows;

		$out = array();

		foreach ($rows as $row) {
			$out[] = array('category_id' => (int)$row['category_id'], 'name' => $this->text($row['name']), 'has_subcategories' => (int)$row['children'] > 0);
		}

		return $out;
	}

	/** All enabled categories of the store: id => array(parent_id, name). */
	public function allCategories() {
		$rows = $this->db->query("SELECT c.category_id, c.parent_id, cd.name FROM `" . DB_PREFIX . "category` c"
			. " JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->languageId . "')"
			. " JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = c.category_id AND c2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE c.status = '1' ORDER BY c.parent_id, c.sort_order, cd.name")->rows;

		$out = array();

		foreach ($rows as $row) {
			$out[(int)$row['category_id']] = array('parent_id' => (int)$row['parent_id'], 'name' => $this->text($row['name']));
		}

		return $out;
	}

	public function categoryName($categoryId) {
		$row = $this->db->query("SELECT cd.name FROM `" . DB_PREFIX . "category` c"
			. " JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->languageId . "')"
			. " JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = c.category_id AND c2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE c.category_id = '" . (int)$categoryId . "' AND c.status = '1'")->row;

		return $row ? $this->text($row['name']) : null;
	}

	public function manufacturerName($manufacturerId) {
		$row = $this->db->query("SELECT m.name FROM `" . DB_PREFIX . "manufacturer` m"
			. " JOIN `" . DB_PREFIX . "manufacturer_to_store` m2s ON (m2s.manufacturer_id = m.manufacturer_id AND m2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE m.manufacturer_id = '" . (int)$manufacturerId . "'")->row;

		return $row ? $this->text($row['name']) : null;
	}

	// ================================================================ information

	public function getInformation($informationId, $keyword = '') {
		$base = "SELECT i.information_id, id.title, id.description FROM `" . DB_PREFIX . "information` i"
			. " JOIN `" . DB_PREFIX . "information_description` id ON (id.information_id = i.information_id AND id.language_id = '" . (int)$this->languageId . "')"
			. " JOIN `" . DB_PREFIX . "information_to_store` i2s ON (i2s.information_id = i.information_id AND i2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE i.status = '1'";

		if ($informationId > 0) {
			$row = $this->db->query($base . " AND i.information_id = '" . (int)$informationId . "'")->row;

			if (!$row) {
				return array('found' => false, 'pages' => $this->informationTitles());
			}

			return array('found' => true, 'information_id' => (int)$row['information_id'], 'title' => $this->text($row['title']), 'content' => $this->plain($row['description'], 4000));
		}

		// Information pages are few (tens), so a text search here is cheap.
		$words = self::words($keyword);
		$rows = array();

		if ($words) {
			$conds = array();
			$score = array();
			foreach ($words as $word) {
				$like = $this->like(self::stem($word));
				$conds[] = "(id.title LIKE " . $like . " OR id.description LIKE " . $like . ")";
				$score[] = "(id.title LIKE " . $like . ") * 3 + (id.description LIKE " . $like . ")";
			}
			$rows = $this->db->query(str_replace('SELECT i.information_id,', 'SELECT (' . implode(' + ', $score) . ') AS score, i.information_id,', $base) . " AND (" . implode(' OR ', $conds) . ") ORDER BY score DESC, i.sort_order LIMIT 5")->rows;
		}

		if (!$rows) {
			return array('found' => false, 'pages' => $this->informationTitles());
		}

		$first = $rows[0];
		$others = array();

		foreach (array_slice($rows, 1) as $row) {
			$others[] = array('information_id' => (int)$row['information_id'], 'title' => $this->text($row['title']));
		}

		return array(
			'found'          => true,
			'information_id' => (int)$first['information_id'],
			'title'          => $this->text($first['title']),
			'content'        => $this->plain($first['description'], 4000),
			'other_matches'  => $others,
		);
	}

	public function informationTitles() {
		$rows = $this->db->query("SELECT i.information_id, id.title FROM `" . DB_PREFIX . "information` i"
			. " JOIN `" . DB_PREFIX . "information_description` id ON (id.information_id = i.information_id AND id.language_id = '" . (int)$this->languageId . "')"
			. " JOIN `" . DB_PREFIX . "information_to_store` i2s ON (i2s.information_id = i.information_id AND i2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE i.status = '1' ORDER BY i.sort_order, id.title LIMIT 50")->rows;

		$out = array();

		foreach ($rows as $row) {
			$out[] = array('information_id' => (int)$row['information_id'], 'title' => $this->text($row['title']));
		}

		return $out;
	}

	public function informationTitle($informationId) {
		$result = $this->getInformation((int)$informationId);

		return !empty($result['found']) ? $result['title'] : null;
	}

	// ================================================================ text helpers

	/** DB text (OpenCart stores it HTML-escaped) -> plain text. */
	public function text($value) {
		return trim(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'));
	}

	/** HTML description -> plain text, truncated. */
	public function plain($html, $max) {
		$text = html_entity_decode((string)$html, ENT_QUOTES, 'UTF-8');
		$text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)[^>]*>/i', "\n", $text);
		$text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
		$text = preg_replace("/\s*\n\s*/", "\n", $text);
		$text = trim($text);

		if (self::len($text) > $max) {
			$text = self::sub($text, 0, $max) . '…';
		}

		return $text;
	}
}
