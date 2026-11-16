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

	const MAX_LINKS = 6;

	/** product cards collected by show_products during one reply */
	private $cards = array();
	/** link / order / tracking cards collected during one reply */
	private $linkCards = array();
	private $settings = null;
	private $orders = null;
	private $conversation = null;
	private $ip = '';
	private $hasOptions = array();
	private static $mainCategoryColumn = null;

	const MAX_REQUESTS = 5;

	public function __construct($registry, $storeId = null, $languageId = null) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
		$this->storeId = $storeId === null ? (int)$this->config->get('config_store_id') : (int)$storeId;
		$this->languageId = $languageId === null ? (int)$this->config->get('config_language_id') : (int)$languageId;
		$this->customerGroupId = (int)$this->config->get('config_customer_group_id');
	}

	/**
	 * Server-side context for the order tools: settings, the conversation row
	 * (verification state) and the visitor's IP (attempt limit).
	 */
	public function setContext(Settings $settings, $conversation, $ip) {
		$this->settings = $settings;
		$this->conversation = is_array($conversation) ? $conversation : null;
		$this->ip = (string)$ip;
		$this->orders = new Orders($this->registry, $settings, $this->storeId, $this->languageId);
		$this->orders->setConversation($conversation, $ip);
	}

	/** @return Orders|null */
	public function orders() {
		return $this->orders;
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
						'query'         => array('type' => 'string', 'description' => 'Keywords or a product code. 1-6 words. Optional when similar_to is given.'),
						'category_id'   => array('type' => 'integer', 'description' => 'Optional: only products in this category (and its subcategories). Ids come from the category tree or list_categories.'),
						'limit'         => array('type' => 'integer', 'description' => 'Max results, 1-10. Default 5.'),
						'in_stock_only' => array('type' => 'boolean', 'description' => 'Only products that are in stock now.'),
						'similar_to'    => array('type' => 'integer', 'description' => 'product_id: find the closest alternatives to this product (same categories, similar price), e.g. when it is out of stock. Combine with in_stock_only.'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'get_product',
				'description' => 'Full details of a product of this store: price, special price, availability, a plain-text description excerpt, attributes, options (with availability per value), manufacturer, minimum quantity. Call it before answering detailed questions about a specific product (size, material, age, compatibility, options). For a comparison pass 2-3 ids in product_ids and write the comparison yourself in sentences.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'  => array('type' => 'integer', 'description' => 'Product id from search_products or the page context.'),
						'product_ids' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Instead of product_id: 2-3 product ids to compare.'),
					),
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
			array(
				'name'        => 'get_specials',
				'description' => 'Products of this store that are discounted right now (special price), biggest discount first. Use it when the customer asks for discounts, promotions, sales, a cheaper price or a discount code: you never give codes or promise discounts, you point to these products instead, and attach the specials page with show_links type "special".',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'category_id' => array('type' => 'integer', 'description' => 'Optional: only specials in this category (and its subcategories).'),
						'limit'       => array('type' => 'integer', 'description' => 'Max results, 1-10. Default 6.'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'list_manufacturers',
				'description' => 'All brands / manufacturers of this store (id and name). Use it when the customer asks which brands you carry.',
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'search_manufacturers',
				'description' => 'Find a brand / manufacturer of this store by name. Returns ids for show_links and for telling the customer whether the brand is carried.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array('type' => 'string', 'description' => 'Brand name or part of it.'),
					),
					'required'             => array('query'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'show_links',
				'description' => 'Show link cards under your reply (title, picture when there is one, link), built by the store. Call it whenever you name a category, brand or information page, when you point to the discounted products (type "special") or to the contact page (type "contact"). Ids come from the category tree, the information page list, list_manufacturers / search_manufacturers. Never write these URLs yourself.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'        => 'array',
							'description' => 'Up to 6 links, in the order to show them.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'type' => array('type' => 'string', 'enum' => array('category', 'manufacturer', 'information', 'special', 'contact', 'return')),
									'id'   => array('type' => 'integer', 'description' => 'category_id / manufacturer_id / information_id; order_id for return (optional); not used for special and contact.'),
								),
								'required'             => array('type'),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array('items'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'find_order',
				'description' => 'Look up an order for a visitor (guest or logged in) by order number AND the phone number given in the order. Both are required: if one is missing, ask the customer for it in a natural way first (no forms). The store checks them; on success you get status, products, totals, delivery address, history and tracking numbers, and the customer sees an order card. On failure the reply is always the same and does not say which part was wrong - never guess, just ask to check both. Attempts are limited.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array('type' => 'string', 'description' => 'Order number as the customer wrote it, e.g. "1234" or "#1234".'),
						'phone'    => array('type' => 'string', 'description' => 'Phone number as the customer wrote it, any format.'),
					),
					'required'             => array('order_id', 'phone'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'my_orders',
				'description' => 'Recent orders of the logged-in customer in this store (number, date, status, total). Only for logged-in visitors; the store knows who they are, never ask them for their identity. For guests use find_order.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array('type' => 'integer', 'description' => '1-10, default 5.'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'my_order',
				'description' => 'Details of one order of the logged-in customer: status, products, totals, delivery address, history, tracking numbers. Shows an order card.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array('type' => 'string', 'description' => 'Order number.'),
					),
					'required'             => array('order_id'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'my_wishlist',
				'description' => 'Products in the logged-in customer\'s wishlist in this store (with prices and availability).',
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'track_shipment',
				'description' => 'Live status of a shipment from the courier. Only tracking numbers of an order verified in this chat (find_order) or of the logged-in customer\'s own order (my_order) work. Shows a tracking card with the courier link. If live status is not available, give the customer the tracking card.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'number' => array('type' => 'string', 'description' => 'Tracking number from the order details.'),
					),
					'required'             => array('number'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'search_kb',
				'description' => 'Search the store\'s approved answers (knowledge base) for a question that is not covered by the approved answers in your instructions. Use before saying you do not know a store-specific fact.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array('type' => 'string', 'description' => 'Keywords of the question.'),
					),
					'required'             => array('query'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'stock_alert',
				'description' => 'Notify-me for an out-of-stock product: the store e-mails the customer when it is back. Ask for the customer\'s e-mail first (and confirm the product).',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array('type' => 'integer', 'description' => 'The out-of-stock product.'),
						'email'      => array('type' => 'string', 'description' => 'Customer\'s e-mail as they wrote it.'),
					),
					'required'             => array('product_id', 'email'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'return_request',
				'description' => 'Register a return / complaint for a product of an order that was verified in this chat (find_order) or belongs to the logged-in customer. Gather the details conversationally first. The store receives it by e-mail and contacts the customer.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id'  => array('type' => 'string', 'description' => 'Order number.'),
						'product'   => array('type' => 'string', 'description' => 'Which product of the order (name or model as in the order).'),
						'reason'    => array('type' => 'string', 'enum' => array('damaged', 'wrong_item', 'defective', 'not_as_described', 'changed_mind', 'other')),
						'details'   => array('type' => 'string', 'description' => 'What happened, in the customer\'s words (short).'),
						'image_ids' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Ids of photos the customer attached in this chat (shown as [Attached images: #id]).'),
					),
					'required'             => array('order_id', 'product', 'reason', 'details'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'order_change_request',
				'description' => 'Pass a customer\'s request to cancel or change an existing order (items, quantities, delivery address, other) to the team. Only for an order verified in this chat (find_order) or the logged-in customer\'s own order (my_order). You never change the order yourself: this records the request and e-mails it to a colleague, who confirms or declines it with the customer. Collect what exactly should change in the customer\'s words first. The result tells you whether it was registered or, for a delivered order, that the returns form applies instead.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array('type' => 'string', 'description' => 'Order number.'),
						'kind'     => array('type' => 'string', 'enum' => array('cancel', 'items', 'address', 'other'), 'description' => 'cancel the order, change items / quantities, change the delivery address, or something else.'),
						'details'  => array('type' => 'string', 'description' => 'What the customer wants, in their own words (short): e.g. new address, which product to add or remove, reason for cancelling.'),
					),
					'required'             => array('order_id', 'kind', 'details'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'request_human',
				'description' => 'Ask a colleague from the team to join this chat (live, through the store\'s staff chat). Use it when the customer asks for a person, or when you cannot help and a person is needed. The result says whether someone was notified or nobody is available (then collect a contact with leave_contact).',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'reason' => array('type' => 'string', 'description' => 'One short sentence for the staff: what the customer needs.'),
					),
					'required'             => array('reason'),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'leave_contact',
				'description' => 'Pass the customer\'s contact to the team (e-mail and/or phone, with a short note of what it is about). The store receives it with the whole conversation by e-mail and gets back to the customer. Use when nobody is available live or the customer prefers to be contacted.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'email' => array('type' => 'string'),
						'phone' => array('type' => 'string'),
						'note'  => array('type' => 'string', 'description' => 'What the customer needs, one or two sentences.'),
					),
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
					$query = isset($input['query']) && is_string($input['query']) ? $input['query'] : '';
					$similar = isset($input['similar_to']) && is_numeric($input['similar_to']) ? (int)$input['similar_to'] : 0;
					if (trim($query) === '' && !$similar) {
						return $this->error('query (string) or similar_to (product_id) is required');
					}
					$category = isset($input['category_id']) && is_numeric($input['category_id']) ? (int)$input['category_id'] : 0;
					$limit = isset($input['limit']) && is_numeric($input['limit']) ? (int)$input['limit'] : 5;
					$inStock = !empty($input['in_stock_only']) && $input['in_stock_only'] !== 'false';
					if ($similar) {
						$result = $this->similarProducts($similar, $query, $category, $limit, $inStock);
						return $result === null ? $this->error('similar_to: product not found in this store') : $this->ok($result);
					}
					return $this->ok($this->searchProducts($query, $category, $limit, $inStock));

				case 'get_product':
					if (isset($input['product_ids']) && is_array($input['product_ids']) && $input['product_ids']) {
						$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $input['product_ids'])))), 0, 3);
						$list = array();
						$missing = array();
						foreach ($ids as $id) {
							$p = $this->getProduct($id, count($ids) > 1 ? 700 : 1500);
							if ($p) {
								$list[] = $p;
							} else {
								$missing[] = $id;
							}
						}
						return $list ? $this->ok(array('products' => $list, 'not_found' => $missing, 'note' => count($list) > 1 ? 'Compare in a few sentences (no table), from these details only.' : '')) : $this->error('products not found in this store');
					}
					if (!isset($input['product_id']) || !is_numeric($input['product_id'])) {
						return $this->error('product_id (integer) or product_ids (array) is required');
					}
					$product = $this->getProduct((int)$input['product_id']);
					return $product ? $this->ok($product) : $this->error('product not found in this store');

				case 'search_kb':
					if (!isset($input['query']) || !is_string($input['query']) || trim($input['query']) === '') {
						return $this->error('query (string) is required');
					}
					return $this->ok($this->searchKb($input['query']));

				case 'stock_alert':
					return $this->ok($this->stockAlert(isset($input['product_id']) ? $input['product_id'] : 0, isset($input['email']) ? $input['email'] : ''));

				case 'return_request':
					return $this->ok($this->returnRequest($input));

				case 'request_human':
					if (!$this->conversation || !$this->settings) {
						return $this->error('not available');
					}
					$handover = new Handover($this->registry, $this->settings);
					$result = $handover->request($this->conversation, isset($input['reason']) && is_string($input['reason']) ? $input['reason'] : '');
					if ($result['status'] === 'waiting') {
						$this->conversation['mode'] = 'waiting';
					}
					return $this->ok($result);

				case 'leave_contact':
					return $this->ok($this->leaveContact($input));

				case 'order_change_request':
					$result = $this->orderChangeRequest($input);
					return $this->ok($result);

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

				case 'get_specials':
					$category = isset($input['category_id']) && is_numeric($input['category_id']) ? (int)$input['category_id'] : 0;
					$limit = isset($input['limit']) && is_numeric($input['limit']) ? (int)$input['limit'] : 6;
					return $this->ok($this->getSpecials($category, $limit));

				case 'list_manufacturers':
					return $this->ok(array('manufacturers' => $this->listManufacturers('')));

				case 'search_manufacturers':
					if (!isset($input['query']) || !is_string($input['query']) || trim($input['query']) === '') {
						return $this->error('query (string) is required');
					}
					return $this->ok(array('manufacturers' => $this->listManufacturers($input['query'])));

				case 'show_links':
					if (!isset($input['items']) || !is_array($input['items'])) {
						return $this->error('items (array) is required');
					}
					return $this->ok($this->showLinks($input['items']));

				case 'find_order':
					if (!$this->orders) {
						return $this->error('orders are not available');
					}
					$orderId = isset($input['order_id']) && is_scalar($input['order_id']) ? (string)$input['order_id'] : '';
					$phone = isset($input['phone']) && is_scalar($input['phone']) ? (string)$input['phone'] : '';
					if (trim($orderId) === '' || trim($phone) === '') {
						return $this->error('order_id and phone are both required; ask the customer for the missing one');
					}
					$result = $this->orders->findOrder($orderId, $phone);
					$this->collectOrderCards();
					return $this->ok($result);

				case 'my_orders':
					if (!$this->orders) {
						return $this->error('orders are not available');
					}
					$limit = isset($input['limit']) && is_numeric($input['limit']) ? (int)$input['limit'] : 5;
					return $this->ok($this->orders->myOrders($limit));

				case 'my_order':
					if (!$this->orders) {
						return $this->error('orders are not available');
					}
					if (!isset($input['order_id']) || !is_scalar($input['order_id'])) {
						return $this->error('order_id is required');
					}
					$result = $this->orders->myOrder((string)$input['order_id']);
					$this->collectOrderCards();
					return $this->ok($result);

				case 'my_wishlist':
					return $this->ok($this->myWishlist());

				case 'track_shipment':
					if (!$this->orders) {
						return $this->error('orders are not available');
					}
					if (!isset($input['number']) || !is_scalar($input['number'])) {
						return $this->error('number is required');
					}
					$result = $this->orders->trackShipment((string)$input['number']);
					$this->collectOrderCards();
					return $this->ok($result);
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

	/**
	 * Link / order / tracking cards collected since the last call (and clears
	 * them): list of {type, title, subtitle?, href, image?}.
	 */
	public function takeLinkCards() {
		$cards = $this->linkCards;
		$this->linkCards = array();

		return $cards;
	}

	private function collectOrderCards() {
		foreach ($this->orders->takeCards() as $card) {
			$this->addLinkCard($card);
		}
	}

	private function addLinkCard(array $card) {
		foreach ($this->linkCards as $have) {
			if ($have['type'] === $card['type'] && $have['href'] === $card['href'] && $have['title'] === $card['title']) {
				return;
			}
		}

		$this->linkCards[] = $card;
	}

	private function ok($data) {
		return array('content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_error' => false);
	}

	private function error($message) {
		return array('content' => json_encode(array('error' => $message)), 'is_error' => true);
	}

	// ================================================================ products

	public function searchProducts($query, $categoryId = 0, $limit = 5, $inStockOnly = false) {
		$limit = max(1, min(self::MAX_SEARCH, (int)$limit));
		$words = self::words($query);

		if (!$words) {
			return array('query' => $query, 'products' => array());
		}

		$rows = $this->searchRows($words, $query, $categoryId, $limit + 1, true, $inStockOnly);

		// No product matches all words: rank by how many words match
		if (!$rows && count($words) > 1) {
			$rows = $this->searchRows($words, $query, $categoryId, $limit + 1, false, $inStockOnly);
		}

		// a miss of the whole catalog (not of a category / in-stock subset)
		if (!$rows && !$inStockOnly && !$categoryId) {
			$this->logMiss($query);
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
	private function searchRows(array $words, $query, $categoryId, $limit, $all, $inStockOnly = false) {
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

		if ($inStockOnly) {
			$sql .= " AND p.quantity > 0";
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

		// ancestors only when they are enabled and in this store (audit I3)
		$rows = $this->db->query("SELECT cp.category_id, cd.name FROM `" . DB_PREFIX . "category_path` cp"
			. " JOIN `" . DB_PREFIX . "category` pc ON (pc.category_id = cp.path_id AND pc.status = '1')"
			. " JOIN `" . DB_PREFIX . "category_to_store` pcs ON (pcs.category_id = cp.path_id AND pcs.store_id = '" . (int)$this->storeId . "')"
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

	public function getProduct($productId, $descriptionMax = 1500) {
		$rows = $this->productRows(array($productId));

		if (!$rows) {
			return null;
		}

		$row = $rows[0];
		$items = $this->present(array($row), false);
		$product = $items[0];

		$extra = $this->db->query("SELECT pd.description, m.name AS manufacturer FROM `" . DB_PREFIX . "product` p"
			. " JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->languageId . "')"
			. " LEFT JOIN `" . DB_PREFIX . "manufacturer_to_store` m2s ON (m2s.manufacturer_id = p.manufacturer_id AND m2s.store_id = '" . (int)$this->storeId . "')"
			. " LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (m.manufacturer_id = m2s.manufacturer_id)"
			. " WHERE p.product_id = '" . (int)$productId . "'")->row;

		if (!empty($extra['manufacturer'])) {
			$product['manufacturer'] = $this->text($extra['manufacturer']);
		}

		if ((int)$row['minimum'] > 1) {
			$product['minimum_quantity'] = (int)$row['minimum'];
		}

		$product['description'] = $this->plain(isset($extra['description']) ? $extra['description'] : '', (int)$descriptionMax);

		if ($product['in_stock'] === 'no') {
			$product['note'] = 'Out of stock: offer the closest in-stock alternatives (search_products with similar_to ' . (int)$productId . ' and in_stock_only true) and a notify-me (stock_alert).';
		}

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

		$hasOptions = $this->hasOptions((int)$row['product_id']);
		$settings = $this->settings ? $this->settings : new Settings($this->registry);
		$canBuy = (int)$settings->getStore($this->storeId, 's_feat_cart') === 1
			&& $prices['price'] !== null
			&& ((int)$row['quantity'] > 0 || (int)$this->config->get('config_stock_checkout') === 1);

		return array(
			'product_id'  => (int)$row['product_id'],
			'name'        => $this->text($row['name']),
			'href'        => $url ? str_replace('&amp;', '&', $url->link('product/product', 'product_id=' . (int)$row['product_id'])) : '',
			'image'       => $image,
			'price'       => $prices['price'] === null ? '' : $prices['price'],
			'special'     => $prices['special'],
			'can_buy'     => $canBuy,
			'has_options' => $hasOptions,
		);
	}

	private function hasOptions($productId) {
		if (!isset($this->hasOptions[$productId])) {
			$this->hasOptions[$productId] = (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "product_option` WHERE `product_id` = '" . (int)$productId . "'")->row['n'] > 0;
		}

		return $this->hasOptions[$productId];
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

	// ================================================================ specials, brands, wishlist

	public function getSpecials($categoryId = 0, $limit = 6) {
		$limit = max(1, min(self::MAX_SEARCH, (int)$limit));
		$group = (int)$this->customerGroupId;

		$sql = $this->productSelect() . $this->productFrom()
			. " AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id AND ps.customer_group_id = '" . $group . "' AND ((ps.date_start = '0000-00-00' OR ps.date_start < NOW()) AND (ps.date_end = '0000-00-00' OR ps.date_end > NOW())))";

		if ($categoryId > 0) {
			$sql .= " AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_path` cp ON (cp.category_id = p2c.category_id) WHERE p2c.product_id = p.product_id AND cp.path_id = '" . (int)$categoryId . "')";
		}

		$sql .= " ORDER BY (p.quantity > 0) DESC, (p.price - special) / GREATEST(p.price, 0.01) DESC, p.sort_order ASC LIMIT " . ($limit + 1);

		$rows = $this->db->query($sql)->rows;
		$more = count($rows) > $limit;

		return array(
			'products'       => $this->present(array_slice($rows, 0, $limit), false),
			'more_available' => $more,
			'note'           => 'Show the best fitting ones with show_products and attach the specials page with show_links type "special". Never give discount codes or promise other discounts.',
		);
	}

	public function listManufacturers($query = '') {
		$sql = "SELECT m.manufacturer_id, m.name FROM `" . DB_PREFIX . "manufacturer` m"
			. " JOIN `" . DB_PREFIX . "manufacturer_to_store` m2s ON (m2s.manufacturer_id = m.manufacturer_id AND m2s.store_id = '" . (int)$this->storeId . "')";

		$words = self::words($query);

		if ($words) {
			$conds = array();
			foreach ($words as $word) {
				$conds[] = "m.name LIKE " . $this->like($word);
			}
			$sql .= " WHERE " . implode(' AND ', $conds);
		}

		$rows = $this->db->query($sql . " ORDER BY m.sort_order, m.name LIMIT 200")->rows;
		$out = array();

		foreach ($rows as $row) {
			$out[] = array('manufacturer_id' => (int)$row['manufacturer_id'], 'name' => $this->text($row['name']));
		}

		return $out;
	}

	public function myWishlist() {
		$customer = $this->registry->get('customer');

		if (!$customer || !$customer->isLogged()) {
			return array('error' => 'not_logged_in', 'message' => 'The wishlist is available to logged-in customers only.');
		}

		$ids = array();
		$rows = $this->db->query("SELECT `product_id` FROM `" . DB_PREFIX . "customer_wishlist` WHERE `customer_id` = '" . (int)$customer->getId() . "' ORDER BY `date_added` DESC LIMIT 30")->rows;

		foreach ($rows as $row) {
			$ids[] = (int)$row['product_id'];
		}

		return array('products' => $this->present($this->productRows($ids), false), 'note' => $ids ? 'Use show_products to show them.' : 'The wishlist is empty.');
	}

	// ================================================================ link cards

	public function showLinks(array $items) {
		$shown = array();
		$missing = array();

		foreach (array_slice($items, 0, self::MAX_LINKS) as $item) {
			if (!is_array($item) || empty($item['type']) || !is_string($item['type'])) {
				continue;
			}
			$id = isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : 0;
			$card = $this->linkCard($item['type'], $id);
			if ($card) {
				$this->addLinkCard($card);
				$shown[] = array('type' => $item['type'], 'title' => $card['title']);
			} else {
				$missing[] = array('type' => $item['type'], 'id' => $id);
			}
		}

		return array('shown' => $shown, 'not_found' => $missing, 'note' => 'Link cards are displayed under your reply. Do not repeat the links.');
	}

	/** {type, title, subtitle?, href, image?} built from store-bound data, or null. */
	public function linkCard($type, $id) {
		$url = $this->registry->get('url');

		if (!$url) {
			return null;
		}

		switch ($type) {
			case 'category':
				$row = $this->db->query("SELECT c.category_id, c.image, cd.name FROM `" . DB_PREFIX . "category` c"
					. " JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->languageId . "')"
					. " JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = c.category_id AND c2s.store_id = '" . (int)$this->storeId . "')"
					. " WHERE c.category_id = '" . (int)$id . "' AND c.status = '1'")->row;
				if (!$row) {
					return null;
				}
				$path = array();
				foreach ($this->db->query("SELECT path_id FROM `" . DB_PREFIX . "category_path` WHERE category_id = '" . (int)$id . "' ORDER BY level")->rows as $p) {
					$path[] = (int)$p['path_id'];
				}
				$paths = $this->categoryPaths(array($id));
				$card = array('type' => 'category', 'title' => $this->text($row['name']), 'href' => $this->href('product/category', 'path=' . ($path ? implode('_', $path) : (int)$id)));
				if (isset($paths[$id]) && $paths[$id] !== $card['title']) {
					$card['subtitle'] = $paths[$id];
				}
				return $this->withImage($card, $row['image']);

			case 'manufacturer':
				$row = $this->db->query("SELECT m.manufacturer_id, m.name, m.image FROM `" . DB_PREFIX . "manufacturer` m"
					. " JOIN `" . DB_PREFIX . "manufacturer_to_store` m2s ON (m2s.manufacturer_id = m.manufacturer_id AND m2s.store_id = '" . (int)$this->storeId . "')"
					. " WHERE m.manufacturer_id = '" . (int)$id . "'")->row;
				if (!$row) {
					return null;
				}
				return $this->withImage(array('type' => 'manufacturer', 'title' => $this->text($row['name']), 'href' => $this->href('product/manufacturer/info', 'manufacturer_id=' . (int)$id)), $row['image']);

			case 'information':
				$title = $this->informationTitle($id);
				if ($title === null) {
					return null;
				}
				return array('type' => 'information', 'title' => $title, 'href' => $this->href('information/information', 'information_id=' . (int)$id));

			case 'special':
				return array('type' => 'special', 'title' => $this->lang('card_special', 'Special offers'), 'href' => $this->href('product/special', ''));

			case 'return':
				// OpenCart's returns form; order_id pre-fills it for the logged-in owner of the order
				return array('type' => 'return', 'title' => $this->lang('card_return', 'Returns form'), 'subtitle' => $id ? sprintf($this->lang('card_return_order', 'Order #%s'), (int)$id) : '', 'href' => $this->href('account/return/add', $id ? 'order_id=' . (int)$id : ''));

			case 'contact':
				$card = array('type' => 'contact', 'title' => $this->lang('card_contact', 'Contact us'), 'href' => $this->href('information/contact', ''));
				$parts = array();
				$contacts = $this->settings ? $this->settings->getStore($this->storeId, 's_contacts') : array();
				foreach (array('phone', 'email') as $k) {
					if (!empty($contacts[$k])) {
						$parts[] = $this->text($contacts[$k]);
					}
				}
				if (!$parts) {
					foreach (array('config_telephone', 'config_email') as $k) {
						if (trim((string)$this->config->get($k)) !== '') {
							$parts[] = trim((string)$this->config->get($k));
						}
					}
				}
				if ($parts) {
					$card['subtitle'] = implode(' · ', $parts);
				}
				return $card;
		}

		return null;
	}

	private function href($route, $args) {
		return str_replace('&amp;', '&', $this->registry->get('url')->link($route, $args));
	}

	/** Category / brand picture through OpenCart's image tool (resized + cached). */
	private function withImage(array $card, $file) {
		$file = (string)$file;
		$load = $this->registry->get('load');

		if ($file !== '' && $load && defined('DIR_IMAGE') && is_file(DIR_IMAGE . $file)) {
			$load->model('tool/image');
			$model = $this->registry->get('model_tool_image');
			if ($model) {
				$card['image'] = (string)$model->resize($file, 100, 100);
			}
		}

		return $card;
	}

	private function lang($key, $fallback) {
		$language = $this->registry->get('language');
		$text = $language ? $language->get($key) : $key;

		return ($text === $key || $text === '') ? $fallback : $text;
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

	// ================================================================ alternatives, search misses

	/**
	 * Closest alternatives to a product: same categories (deepest shared
	 * first), similar price, optionally in stock and matching words.
	 */
	public function similarProducts($productId, $query = '', $categoryId = 0, $limit = 5, $inStockOnly = true) {
		$base = $this->productRows(array($productId));

		if (!$base) {
			return null;
		}

		$base = $base[0];
		$limit = max(1, min(self::MAX_SEARCH, (int)$limit));
		$cats = array();

		foreach ($this->db->query("SELECT p2c.category_id FROM `" . DB_PREFIX . "product_to_category` p2c"
			. " JOIN `" . DB_PREFIX . "category` c ON (c.category_id = p2c.category_id AND c.status = '1')"
			. " JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = p2c.category_id AND c2s.store_id = '" . (int)$this->storeId . "')"
			. " WHERE p2c.product_id = '" . (int)$productId . "'")->rows as $row) {
			$cats[] = (int)$row['category_id'];
		}

		$score = array();

		if ($cats) {
			// a shared small category says more than a shared catch-all one: weight = 1000 / size
			$cases = array();
			foreach ($this->db->query("SELECT p2c.category_id, COUNT(*) AS n FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p2c.product_id AND p2s.store_id = '" . (int)$this->storeId . "') WHERE p2c.category_id IN (" . implode(',', $cats) . ") GROUP BY p2c.category_id")->rows as $row) {
				$cases[] = "WHEN " . (int)$row['category_id'] . " THEN " . max(1, (int)round(1000 / max(1, (int)$row['n'])));
			}
			$score[] = "COALESCE((SELECT SUM(CASE x.category_id " . implode(' ', $cases) . " ELSE 0 END) FROM `" . DB_PREFIX . "product_to_category` x WHERE x.product_id = p.product_id AND x.category_id IN (" . implode(',', $cats) . ")), 0)";
		}

		foreach (self::words($query) as $word) {
			$score[] = "(pd.name LIKE " . $this->like(self::stem($word)) . ") * 3";
		}

		$sql = $this->productSelect() . ", " . ($score ? implode(' + ', $score) : '0') . " AS score" . $this->productFrom()
			. " AND p.product_id <> '" . (int)$productId . "'";

		if ($cats) {
			$sql .= " AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "product_to_category` y WHERE y.product_id = p.product_id AND y.category_id IN (" . implode(',', $cats) . "))";
		} elseif (!self::words($query)) {
			return array('similar_to' => (int)$productId, 'products' => array(), 'note' => 'No category to compare with; try search_products with keywords.');
		}

		if ($categoryId > 0) {
			$sql .= " AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_path` cp ON (cp.category_id = p2c.category_id) WHERE p2c.product_id = p.product_id AND cp.path_id = '" . (int)$categoryId . "')";
		}

		if ($inStockOnly) {
			$sql .= " AND p.quantity > 0";
		}

		$sql .= " ORDER BY score DESC, ABS(p.price - " . sprintf('%.4F', (float)$base['price']) . ") ASC, p.sort_order ASC LIMIT " . (int)$limit;

		return array(
			'similar_to' => (int)$productId,
			'products'   => $this->present($this->db->query($sql)->rows, false),
			'note'       => 'Closest alternatives by category and price. Recommend only those that really fit what the customer wanted.',
		);
	}

	/** Searches with zero results: aichat_search_miss (store, language, query, hits, last date). */
	private function logMiss($query) {
		if ($this->conversation && !empty($this->conversation['is_test'])) {
			return;
		}

		$q = function_exists('mb_strtolower') ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$query)), 'UTF-8') : strtolower(trim((string)$query));
		$q = function_exists('mb_substr') ? mb_substr($q, 0, 190, 'UTF-8') : substr($q, 0, 190);

		if ($q === '') {
			return;
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aichat_search_miss` (`store_id`, `language_id`, `query`, `hits`, `date_modified`) VALUES ('" . (int)$this->storeId . "', '" . (int)$this->languageId . "', '" . $this->db->escape($q) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `date_modified` = NOW()");
	}

	// ================================================================ knowledge base

	private function kbWhere() {
		return " WHERE `status` = '1' AND `store_id` IN ('-1', '" . (int)$this->storeId . "') AND `language_id` IN ('0', '" . (int)$this->languageId . "')";
	}

	/** Newest active approved answers of this store + language (prompt). */
	public function kbRows($limit) {
		return $this->db->query("SELECT `kb_id`, `question`, `answer` FROM `" . DB_PREFIX . "aichat_kb`" . $this->kbWhere() . " ORDER BY `date_added` DESC, `kb_id` DESC LIMIT " . (int)$limit)->rows;
	}

	public function searchKb($query) {
		$words = self::words($query);
		$out = array();

		if ($words) {
			$conds = array();
			$score = array();
			foreach ($words as $word) {
				$like = $this->like(self::stem($word));
				$conds[] = "(`question` LIKE " . $like . " OR `answer` LIKE " . $like . ")";
				$score[] = "(`question` LIKE " . $like . ") * 2 + (`answer` LIKE " . $like . ")";
			}
			$rows = $this->db->query("SELECT `kb_id`, `question`, `answer`, (" . implode(' + ', $score) . ") AS score FROM `" . DB_PREFIX . "aichat_kb`" . $this->kbWhere() . " AND (" . implode(' OR ', $conds) . ") ORDER BY score DESC, `date_added` DESC LIMIT 5")->rows;
			foreach ($rows as $row) {
				$out[] = array('question' => $this->text($row['question']), 'answer' => $this->plain($row['answer'], 2000));
			}
		}

		return array('answers' => $out, 'note' => $out ? 'Approved answers of the store: answer from them.' : 'No approved answer found; use the information pages or say you do not know.');
	}

	// ================================================================ requests (stock alert, return, contact)

	private function requests() {
		return new Requests($this->registry, $this->settings ? $this->settings : new Settings($this->registry));
	}

	private function requestGate() {
		if (!$this->conversation) {
			return array('ok' => false, 'message' => 'Not available in this chat.');
		}

		if ($this->requests()->countForConversation((int)$this->conversation['conversation_id']) >= self::MAX_REQUESTS) {
			return array('ok' => false, 'message' => 'Too many requests in this chat; give the customer the store contacts instead.');
		}

		return null;
	}

	public function stockAlert($productId, $email) {
		$gate = $this->requestGate();

		if ($gate) {
			return $gate;
		}

		$email = Requests::validEmail(is_scalar($email) ? $email : '');

		if ($email === '') {
			return array('ok' => false, 'message' => 'That does not look like a valid e-mail address; ask the customer to check it.');
		}

		$rows = $this->productRows(array(is_numeric($productId) ? (int)$productId : 0));

		if (!$rows) {
			return array('ok' => false, 'message' => 'Product not found in this store.');
		}

		$row = $rows[0];

		if ((int)$row['quantity'] > 0) {
			return array('ok' => false, 'in_stock' => true, 'message' => 'This product is in stock now; no notification needed.');
		}

		$requests = $this->requests();

		if ($requests->openStockAlertExists($this->storeId, (int)$row['product_id'], $email)) {
			return array('ok' => true, 'message' => 'This e-mail is already registered for this product.');
		}

		$id = $requests->add(array(
			'store_id'        => $this->storeId,
			'conversation_id' => (int)$this->conversation['conversation_id'],
			'type'            => 'stock',
			'product_id'      => (int)$row['product_id'],
			'email'           => $email,
			'data'            => array('product' => $this->text($row['name']), 'model' => $this->text($row['model']), 'language_id' => $this->languageId),
		));

		$requests->mailStore($this->storeId, $this->testPrefix() . 'AI Chat: stock alert #' . $id . ' – ' . $this->text($row['name']),
			"A customer wants to be notified when this product is back in stock.\n\n"
			. "Product: " . $this->text($row['name']) . " (#" . (int)$row['product_id'] . ", " . $this->text($row['model']) . ")\n"
			. "E-mail: " . $email . "\n"
			. "Chat: #" . (int)$this->conversation['conversation_id'] . "\n\n"
			. "Клиент иска известие, когато продуктът е наличен.\n", $email, array(), $id);

		return array('ok' => true, 'request_id' => $id, 'message' => 'Registered: the store will e-mail the customer when the product is back in stock. Do not promise a date.');
	}

	public function returnRequest(array $input) {
		$gate = $this->requestGate();

		if ($gate) {
			return $gate;
		}

		$generic = array('ok' => false, 'message' => 'Returns can be registered only for an order verified in this chat: ask for the order number and the phone number of the order and call find_order first (logged-in customers: my_orders). Say nothing about whether such an order exists.');

		if (!$this->orders) {
			return $generic;
		}

		$order = $this->orders->visibleOrder(isset($input['order_id']) && is_scalar($input['order_id']) ? (string)$input['order_id'] : '');

		if (!$order) {
			return $generic;
		}

		if (!$this->orders->changeAllowed($order)) {
			return $this->reconfirm();
		}

		$wanted = isset($input['product']) && is_string($input['product']) ? trim($input['product']) : '';
		$lower = function ($s) {
			return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
		};
		$products = $this->orders->orderProducts($order);
		$match = null;

		foreach ($products as $p) {
			if ($wanted !== '' && (strpos($lower($p['name']), $lower($wanted)) !== false || strpos($lower($wanted), $lower($p['name'])) !== false || ($p['model'] !== '' && strpos($lower($wanted), $lower($p['model'])) !== false))) {
				$match = $p;
				break;
			}
		}

		if (!$match && count($products) === 1) {
			$match = $products[0];
		}

		if (!$match) {
			$names = array();
			foreach ($products as $p) {
				$names[] = $p['name'];
			}
			return array('ok' => false, 'message' => 'Which product of the order? Ask the customer to choose one of: ' . implode('; ', $names));
		}

		$reasons = array('damaged', 'wrong_item', 'defective', 'not_as_described', 'changed_mind', 'other');
		$reason = isset($input['reason']) && in_array($input['reason'], $reasons, true) ? $input['reason'] : 'other';
		$details = isset($input['details']) && is_string($input['details']) ? $this->cut(trim($input['details']), 2000) : '';
		$uploads = new Uploads($this->registry);
		$imageIds = $uploads->filterOwned(isset($input['image_ids']) && is_array($input['image_ids']) ? $input['image_ids'] : array(), (int)$this->conversation['conversation_id'], 6);
		$files = array();

		foreach ($imageIds as $imageId) {
			$row = $uploads->get($imageId, (int)$this->conversation['conversation_id']);
			$file = $row ? Uploads::path($row) : '';
			if ($file !== '') {
				$files[] = $file;
			}
		}

		$requests = $this->requests();
		$id = $requests->add(array(
			'store_id'        => $this->storeId,
			'conversation_id' => (int)$this->conversation['conversation_id'],
			'type'            => 'return',
			'order_id'        => (int)$order['order_id'],
			'product_id'      => $match['product_id'],
			'email'           => (string)$order['email'],
			'phone'           => (string)$order['telephone'],
			'data'            => array('product' => $match['name'], 'model' => $match['model'], 'order_product_id' => $match['order_product_id'], 'reason' => $reason, 'details' => $details, 'images' => $imageIds),
		));

		$requests->mailStore($this->storeId, $this->testPrefix() . 'AI Chat: return request #' . $id . ' – order #' . (int)$order['order_id'],
			"Return / complaint registered in the chat (order verified in the chat).\n\n"
			. "Order: #" . (int)$order['order_id'] . " (" . $this->text($order['firstname'] . ' ' . $order['lastname']) . ", " . $order['email'] . ", " . $order['telephone'] . ")\n"
			. "Product: " . $match['name'] . ($match['model'] !== '' ? ' (' . $match['model'] . ')' : '') . " x" . $match['quantity'] . "\n"
			. "Reason: " . $reason . "\n"
			. "Details: " . $details . "\n"
			. "Photos: " . count($files) . " attached\n"
			. "Chat: #" . (int)$this->conversation['conversation_id'] . "\n", (string)$order['email'], $files, $id);

		return array('ok' => true, 'request_id' => $id, 'message' => 'The return request was registered and sent to the team; they will contact the customer by e-mail or phone. Do not promise refunds, deadlines or the outcome.');
	}

	public function leaveContact(array $input) {
		$gate = $this->requestGate();

		if ($gate) {
			return $gate;
		}

		$email = Requests::validEmail(isset($input['email']) && is_scalar($input['email']) ? $input['email'] : '');
		$phone = Requests::validPhone(isset($input['phone']) && is_scalar($input['phone']) ? $input['phone'] : '');
		$note = isset($input['note']) && is_string($input['note']) ? $this->cut(trim($input['note']), 1000) : '';

		if ($email === '' && $phone === '') {
			return array('ok' => false, 'message' => 'A valid e-mail address or phone number is needed; ask the customer for one (or to check it).');
		}

		$cid = (int)$this->conversation['conversation_id'];
		$requests = $this->requests();
		$id = $requests->add(array('store_id' => $this->storeId, 'conversation_id' => $cid, 'type' => 'contact', 'email' => $email, 'phone' => $phone, 'data' => array('note' => $note, 'page' => $this->conversation['last_url'])));

		$requests->mailStore($this->storeId, $this->testPrefix() . 'AI Chat: please contact the customer – chat #' . $cid,
			"A visitor left a contact in the chat. Please get back to them.\n"
			. "Клиент остави контакт в чата. Моля, свържете се с него.\n\n"
			. "E-mail: " . ($email !== '' ? $email : '-') . "\n"
			. "Phone: " . ($phone !== '' ? $phone : '-') . "\n"
			. "Note: " . ($note !== '' ? $note : '-') . "\n"
			. "Page: " . $this->conversation['last_url'] . "\n\n"
			. "--- Conversation #" . $cid . " ---\n" . Handover::transcript($this->db, $cid, 200) . "\n", $email, array(), $id);

		return array('ok' => true, 'request_id' => $id, 'message' => 'The contact and the conversation were sent to the team; they will get back to the customer. Confirm this briefly; do not promise a time.');
	}

	/**
	 * order_change_request: verified / own order only (one generic reply
	 * otherwise). Delivered -> no request, the returns form link card.
	 * Shipped or open -> aichat_request type "order" + e-mail to the store
	 * stating how the customer was verified. The order itself is never touched.
	 */
	public function orderChangeRequest(array $input) {
		$gate = $this->requestGate();

		if ($gate) {
			return $gate;
		}

		$generic = array('ok' => false, 'message' => 'Order changes can be requested only for an order verified in this chat: ask for the order number and the phone number of the order and call find_order first (logged-in customers: my_orders). Say nothing about whether such an order exists.');

		if (!$this->orders) {
			return $generic;
		}

		$order = $this->orders->visibleOrder(isset($input['order_id']) && is_scalar($input['order_id']) ? (string)$input['order_id'] : '');

		if (!$order) {
			return $generic;
		}

		if (!$this->orders->changeAllowed($order)) {
			return $this->reconfirm();
		}

		$kinds = array('cancel', 'items', 'address', 'other');
		$kind = isset($input['kind']) && in_array($input['kind'], $kinds, true) ? $input['kind'] : 'other';
		$details = isset($input['details']) && is_string($input['details']) ? $this->cut(trim($input['details']), 2000) : '';

		if ($details === '' && $kind !== 'cancel') {
			return array('ok' => false, 'message' => 'Ask the customer what exactly should change first, then call again with details.');
		}

		$stage = $this->orders->stage($order);

		if ($stage['stage'] === 'delivered') {
			$card = $this->linkCard('return', (int)$order['order_id']);
			if ($card) {
				$this->addLinkCard($card);
			}
			return array('ok' => false, 'stage' => 'delivered', 'message' => 'This order has already been delivered, so it can no longer be cancelled or changed. Explain this kindly; for returning or exchanging products the customer can use the returns form (a link card is shown under your reply), or tell you about the problem so you can register a return request with return_request.');
		}

		$verifiedBy = $this->orders->verifiedBy($order);
		$verifiedText = $verifiedBy === 'logged_in'
			? "Verified: logged-in customer (account of the order) / Потвърдено: логнат клиент (профилът на поръчката)"
			: "Verified: the order number and the phone number match / Потвърдено: номерът на поръчката и телефонът съвпадат";
		$kindText = array('cancel' => 'Cancel the order / Отказ от поръчката', 'items' => 'Change products / Промяна на продукти', 'address' => 'Change delivery address / Промяна на адреса', 'other' => 'Other change / Друга промяна');
		$stageText = $stage['stage'] === 'shipped' ? 'already shipped / вече е изпратена – please check with the courier' : 'not shipped yet / още не е изпратена';
		$cid = (int)$this->conversation['conversation_id'];

		$requests = $this->requests();
		$id = $requests->add(array(
			'store_id'        => $this->storeId,
			'conversation_id' => $cid,
			'type'            => 'order',
			'order_id'        => (int)$order['order_id'],
			'email'           => (string)$order['email'],
			'phone'           => (string)$order['telephone'],
			'data'            => array('kind' => $kind, 'details' => $details, 'stage' => $stage['stage'], 'status' => $stage['status'], 'verified' => $verifiedBy),
		));

		$requests->mailStore($this->storeId, $this->testPrefix() . 'AI Chat: order change request #' . $id . ' – order #' . (int)$order['order_id'] . ' (' . $kind . ')',
			"A customer asked in the chat to change an order. Nothing has been changed yet: please confirm or decline it with the customer.\n"
			. "Клиент поиска в чата промяна на поръчка. Нищо не е променено: моля, потвърдете или откажете на клиента.\n\n"
			. $verifiedText . "\n"
			. "Request: " . $kindText[$kind] . "\n"
			. "Order status: " . $stage['status'] . " (" . $stageText . ")\n"
			. "Customer's words / Думите на клиента:\n" . ($details !== '' ? $details : '-') . "\n\n"
			. $this->orders->summaryText($order) . "\n\n"
			. "--- Conversation #" . $cid . " ---\n" . Handover::transcript($this->db, $cid, 60) . "\n", (string)$order['email'], array(), $id);

		return array(
			'ok'         => true,
			'request_id' => $id,
			'stage'      => $stage['stage'],
			'message'    => 'The request was registered and sent to a colleague. Tell the customer warmly that you passed it on and a colleague will confirm it (by e-mail or phone). Do not say the order was changed or cancelled, and do not promise that it will be.' . ($stage['stage'] === 'shipped' ? ' The order has already been shipped, so say honestly that a change may no longer be possible; the colleague will check.' : ''),
		);
	}

	/** A guest's phone must have been confirmed within Orders::VERIFY_IDLE before returns / order changes (audit L3). */
	private function reconfirm() {
		return array('ok' => false, 'reconfirm_phone' => true, 'message' => 'For the customer\'s safety the phone number of the order must be confirmed again before a request about this order: ask the customer for the phone number given in the order, call find_order with the order number and that phone, then call this tool again.');
	}

	private function testPrefix() {
		return $this->conversation && !empty($this->conversation['is_test']) ? '[TEST] ' : '';
	}

	private function cut($text, $max) {
		return self::len($text) > $max ? self::sub($text, 0, $max) : $text;
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
