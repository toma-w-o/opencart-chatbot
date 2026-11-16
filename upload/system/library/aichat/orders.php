<?php
namespace Aichat;

/**
 * Order access for the chat tools (stage 3). Security lives here, not in the prompt:
 *
 *  - guests: an order is shown only after order number + phone match in PHP
 *    (digits only, last 9 compared), same store, one identical failure reply
 *    for every reason, attempt limit per conversation and per IP;
 *  - logged-in customers: identity from the server session only;
 *  - order history comments only where notify = 1;
 *  - tracking numbers only from the verified / own order;
 *  - no e-mail, phone, IP, payment details or other customers' data.
 */
class Orders {
	const MAX_ATTEMPTS = 5;
	const IP_ATTEMPTS_HOUR = 10;
	const MY_ORDERS_MAX = 10;

	private $registry;
	private $db;
	private $config;
	private $settings;
	private $storeId;
	private $languageId;
	private $conversation = null;
	private $ip = '';
	private $cards = array();
	/** tracking links of orders the visitor may see (for the link filter) */
	private $allowedUrls = array();
	private static $viewTable = false;
	private static $trackingColumn = false;

	public function __construct($registry, Settings $settings, $storeId, $languageId) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
		$this->settings = $settings;
		$this->storeId = (int)$storeId;
		$this->languageId = (int)$languageId;
	}

	/** @param array|null $conversation row of aichat_conversation (server side) */
	public function setConversation($conversation, $ip = '') {
		$this->conversation = is_array($conversation) ? $conversation : null;
		$this->ip = (string)$ip;
	}

	public function takeCards() {
		$cards = $this->cards;
		$this->cards = array();

		return $cards;
	}

	/** Exact tracking URLs of the verified / own orders seen in this request. */
	public function allowedUrls() {
		return array_keys($this->allowedUrls);
	}

	/** Tracking URLs of the conversation's verified order (link filter at the start of a reply). */
	public function verifiedUrls() {
		$id = $this->conversation ? (int)$this->conversation['verified_order_id'] : 0;

		if ($id) {
			$order = $this->orderRow($id);
			if ($order && $this->maySee($order)) {
				foreach ($this->tracking($order) as $t) {
					$this->allow($t);
				}
			}
		}

		return $this->allowedUrls();
	}

	// ================================================================ helpers for the rules

	public static function digits($phone) {
		return preg_replace('/\D+/', '', (string)$phone);
	}

	/** Digits only, compare the last 9 (so +359 88..., 0 88..., 88... all match). */
	public static function phoneMatches($given, $stored) {
		$a = self::digits($given);
		$b = self::digits($stored);

		if (strlen($a) < 6 || strlen($b) < 6) {
			return false;
		}

		return substr($a, -9) === substr($b, -9);
	}

	public static function orderNumber($value) {
		$digits = preg_replace('/\D+/', '', (string)$value);

		return ($digits !== '' && strlen($digits) <= 10) ? (int)$digits : 0;
	}

	private function customerId() {
		$customer = $this->registry->get('customer');

		return ($customer && $customer->isLogged()) ? (int)$customer->getId() : 0;
	}

	/** The visitor may see this order: verified in this conversation, or their own (session). */
	private function maySee(array $order) {
		if ((int)$order['store_id'] !== $this->storeId) {
			return false;
		}

		if ($this->conversation && (int)$this->conversation['verified_order_id'] === (int)$order['order_id']) {
			return true;
		}

		$customerId = $this->customerId();

		return $customerId && (int)$order['customer_id'] === $customerId;
	}

	/**
	 * The order row when the visitor may see it (verified in this conversation
	 * or their own, this store), else null - for return requests. Callers give
	 * one generic answer for "not found" and "not yours".
	 */
	public function visibleOrder($orderNumber) {
		$order = $this->orderRow(self::orderNumber($orderNumber));

		return ($order && $this->maySee($order)) ? $order : null;
	}

	/** Products of a visible order: list of {order_product_id, product_id, name, model, quantity}. */
	public function orderProducts(array $order) {
		$out = array();

		foreach ($this->db->query("SELECT `order_product_id`, `product_id`, `name`, `model`, `quantity` FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = '" . (int)$order['order_id'] . "' ORDER BY `order_product_id` LIMIT 50")->rows as $p) {
			$out[] = array('order_product_id' => (int)$p['order_product_id'], 'product_id' => (int)$p['product_id'], 'name' => $this->text($p['name']), 'model' => $this->text($p['model']), 'quantity' => (int)$p['quantity']);
		}

		return $out;
	}

	private function orderRow($orderId) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$orderId . "' AND `store_id` = '" . (int)$this->storeId . "' AND `order_status_id` > '0'");

		return $query->num_rows ? $query->row : null;
	}

	private function genericFailure() {
		return array(
			'found'   => false,
			'message' => 'No order matches this order number and phone number. Ask the customer to check both (the phone number given in the order). Do not guess which one is wrong.',
		);
	}

	private function locked() {
		return array(
			'found'   => false,
			'locked'  => true,
			'message' => 'Too many unsuccessful attempts: no more orders can be checked in this chat. Give the customer the store contacts for order questions.',
		);
	}

	// ================================================================ tools

	public function findOrder($orderNumber, $phone) {
		$orderId = self::orderNumber($orderNumber);

		if (!$this->conversation) {
			return $this->genericFailure();
		}

		// Already verified in this conversation
		if ($orderId && (int)$this->conversation['verified_order_id'] === $orderId) {
			$order = $this->orderRow($orderId);
			if ($order) {
				return $this->details($order, true);
			}
		}

		if ((int)$this->conversation['order_attempts'] >= self::MAX_ATTEMPTS) {
			return $this->locked();
		}

		$guard = new Guard($this->registry, $this->settings);

		if (!$guard->hit('o:i:' . $this->ip, 3600, self::IP_ATTEMPTS_HOUR)) {
			return $this->locked();
		}

		$order = $orderId ? $this->orderRow($orderId) : null;

		if (!$order || !self::phoneMatches($phone, $order['telephone'])) {
			$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `order_attempts` = `order_attempts` + 1 WHERE `conversation_id` = '" . (int)$this->conversation['conversation_id'] . "'");
			$this->conversation['order_attempts'] = (int)$this->conversation['order_attempts'] + 1;

			$result = $this->genericFailure();
			$result['attempts_left'] = max(0, self::MAX_ATTEMPTS - (int)$this->conversation['order_attempts']);

			return $result;
		}

		$this->markVerified($orderId);

		return $this->details($order, true);
	}

	private function markVerified($orderId) {
		if ($this->conversation) {
			$this->db->query("UPDATE `" . DB_PREFIX . "aichat_conversation` SET `verified_order_id` = '" . (int)$orderId . "' WHERE `conversation_id` = '" . (int)$this->conversation['conversation_id'] . "'");
			$this->conversation['verified_order_id'] = (int)$orderId;
		}
	}

	public function myOrders($limit) {
		$customerId = $this->customerId();

		if (!$customerId) {
			return array('error' => 'not_logged_in', 'message' => 'The visitor is not logged in. For a guest order ask for the order number and the phone number and use find_order.');
		}

		$limit = max(1, min(self::MY_ORDERS_MAX, (int)$limit));
		$rows = $this->db->query("SELECT o.order_id, o.date_added, o.total, o.currency_code, o.currency_value, os.name AS status,"
			. " (SELECT SUM(op.quantity) FROM `" . DB_PREFIX . "order_product` op WHERE op.order_id = o.order_id) AS items"
			. " FROM `" . DB_PREFIX . "order` o"
			. " LEFT JOIN `" . DB_PREFIX . "order_status` os ON (os.order_status_id = o.order_status_id AND os.language_id = '" . (int)$this->languageId . "')"
			. " WHERE o.customer_id = '" . (int)$customerId . "' AND o.store_id = '" . (int)$this->storeId . "' AND o.order_status_id > '0'"
			. " ORDER BY o.date_added DESC, o.order_id DESC LIMIT " . (int)$limit)->rows;

		$orders = array();

		foreach ($rows as $row) {
			$orders[] = array(
				'order_id' => (int)$row['order_id'],
				'date'     => date('Y-m-d', strtotime($row['date_added'])),
				'status'   => $this->text($row['status']),
				'total'    => $this->money($row['total'], $row['currency_code'], $row['currency_value']),
				'items'    => (int)$row['items'],
			);
		}

		return array('orders' => $orders, 'note' => $orders ? 'Use my_order for details of one order.' : 'This customer has no orders in this store.');
	}

	public function myOrder($orderNumber) {
		$customerId = $this->customerId();

		if (!$customerId) {
			return array('error' => 'not_logged_in', 'message' => 'The visitor is not logged in. Ask for the order number and the phone number and use find_order.');
		}

		$order = $this->orderRow(self::orderNumber($orderNumber));

		if (!$order || (int)$order['customer_id'] !== $customerId) {
			return array('found' => false, 'message' => 'No order with this number in this customer\'s account in this store. Use my_orders to list them.');
		}

		$this->markVerified((int)$order['order_id']);

		return $this->details($order, true);
	}

	public function trackShipment($number) {
		$number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$number));

		if ($number === '') {
			return array('error' => 'number is required');
		}

		$found = null;

		foreach ($this->visibleOrders() as $order) {
			foreach ($this->tracking($order) as $t) {
				if (strtoupper($t['number']) === $number) {
					$found = $t;
					break 2;
				}
			}
		}

		if (!$found) {
			return array('found' => false, 'message' => 'This number does not belong to an order verified in this chat. Verify the order first (find_order, or my_orders for logged-in customers); only its tracking numbers can be tracked.');
		}

		$this->allow($found);

		return $this->trackOne($found);
	}

	private function trackOne(array $t) {
		$courier = $this->courier($t['courier']);
		$status = null;

		if ($courier) {
			try {
				$status = $courier->track($t['number'], $this->langCode());
			} catch (\Exception $e) {
				$status = null;
			}
		}

		$result = array(
			'found'   => true,
			'number'  => $t['number'],
			'courier' => $t['courier_name'],
		);

		if (is_array($status)) {
			$result['status'] = $status['status'];
			foreach (array('last_event', 'last_time', 'location') as $k) {
				if (!empty($status[$k])) {
					$result[$k] = $status[$k];
				}
			}
			$result['delivered'] = !empty($status['delivered']);
		} else {
			$result['status'] = 'not_available';
			$result['note'] = 'Live status is not available here. The customer can follow the shipment with the tracking link shown in the tracking card.';
		}

		$this->cards[] = $this->trackingCard($t, is_array($status) ? $status : null);
		$result['card'] = 'A tracking card with the courier link is shown under your reply. Do not write the link yourself.';

		return $result;
	}

	// ================================================================ details

	/** Whitelisted details of an order the visitor may see. */
	private function details(array $order, $withCards) {
		if (!$this->maySee($order)) {
			return $this->genericFailure();
		}

		$orderId = (int)$order['order_id'];
		$currency = $order['currency_code'];
		$value = $order['currency_value'];

		$status = $this->db->query("SELECT `name` FROM `" . DB_PREFIX . "order_status` WHERE `order_status_id` = '" . (int)$order['order_status_id'] . "' AND `language_id` = '" . (int)$this->languageId . "'")->row;

		$out = array(
			'found'           => true,
			'order_id'        => $orderId,
			'date'            => date('Y-m-d', strtotime($order['date_added'])),
			'status'          => $status ? $this->text($status['name']) : '',
			'total'           => $this->money($order['total'], $currency, $value),
			'payment_method'  => $this->text($order['payment_method']),
			'shipping_method' => $this->text($order['shipping_method']),
		);

		$address = array();
		foreach (array('shipping_firstname', 'shipping_lastname') as $k) {
			if (trim((string)$order[$k]) !== '') {
				$address[] = $this->text($order[$k]);
			}
		}
		$name = implode(' ', $address);
		$lines = array();
		foreach (array('shipping_company', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_postcode', 'shipping_zone', 'shipping_country') as $k) {
			if (isset($order[$k]) && trim((string)$order[$k]) !== '') {
				$lines[] = $this->text($order[$k]);
			}
		}
		if ($lines) {
			$out['shipping_address'] = ($name !== '' ? $name . ', ' : '') . implode(', ', $lines);
		}

		// products
		$products = $this->db->query("SELECT `order_product_id`, `name`, `model`, `quantity`, `total`, `tax` FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = '" . $orderId . "' ORDER BY `order_product_id` LIMIT 50")->rows;
		foreach ($products as $p) {
			$item = array(
				'name'     => $this->text($p['name']),
				'model'    => $this->text($p['model']),
				'quantity' => (int)$p['quantity'],
				'total'    => $this->money((float)$p['total'] + ($this->config->get('config_tax') ? (float)$p['tax'] * (int)$p['quantity'] : 0), $currency, $value),
			);
			$options = $this->db->query("SELECT `name`, `value` FROM `" . DB_PREFIX . "order_option` WHERE `order_id` = '" . $orderId . "' AND `order_product_id` = '" . (int)$p['order_product_id'] . "' LIMIT 20")->rows;
			foreach ($options as $o) {
				$item['options'][] = $this->text($o['name']) . ': ' . $this->text($o['value']);
			}
			$out['products'][] = $item;
		}

		// totals
		$totals = $this->db->query("SELECT `title`, `value` FROM `" . DB_PREFIX . "order_total` WHERE `order_id` = '" . $orderId . "' ORDER BY `sort_order`")->rows;
		foreach ($totals as $t) {
			$out['totals'][] = array('title' => $this->text($t['title']), 'value' => $this->money($t['value'], $currency, $value));
		}

		// history: status changes the customer was notified about (as OpenCart's own
		// account/order page shows them), with the comment text of those rows only
		foreach ($this->history($orderId) as $h) {
			$entry = array('date' => date('Y-m-d H:i', strtotime($h['date_added'])), 'status' => $this->text($h['status']));
			$comment = $this->cleanComment($h['comment']);
			if ($comment !== '') {
				$entry['comment'] = $comment;
			}
			$out['history'][] = $entry;
		}

		// tracking: numbers with the detected courier and how it was detected
		$tracking = $this->tracking($order);
		foreach ($tracking as $t) {
			$this->allow($t);
			$item = array('number' => $t['number'], 'courier' => $t['courier_name'] !== '' ? $t['courier_name'] : 'unknown', 'courier_detected_by' => $t['detected_by']);
			$out['tracking'][] = $item;
		}
		if ($tracking) {
			$out['tracking_note'] = 'courier_detected_by: link = from the courier link in a comment, number_format = the number matches that courier\'s format, comment = the courier is named in the same comment, status = named in the order status, shipping_method = from the shipping method, store_default = the store\'s usual courier (a guess), none = unknown. Several numbers usually mean several parcels.';
		}

		$stage = $this->stage($order, $tracking);
		$out['stage'] = $stage['stage'];

		$view = $this->viewOrderLink($order);

		if ($withCards) {
			$this->cards[] = $this->orderCard($order, $out, $view);
			foreach ($tracking as $t) {
				$this->cards[] = $this->trackingCard($t, null);
			}
			$out['cards'] = 'An order card' . ($view !== '' ? ' with a "view order" button' : '') . ($tracking ? ' and tracking cards with the courier links' : '') . ' are shown under your reply. Do not write links yourself. Use track_shipment for the live status of a tracking number.';
		}

		return $out;
	}

	/** History rows with notify = 1 (the only ones whose comments the visitor may see). */
	private function history($orderId) {
		return $this->db->query("SELECT oh.date_added, oh.comment, os.name AS status FROM `" . DB_PREFIX . "order_history` oh"
			. " LEFT JOIN `" . DB_PREFIX . "order_status` os ON (os.order_status_id = oh.order_status_id AND os.language_id = '" . (int)$this->languageId . "')"
			. " WHERE oh.order_id = '" . (int)$orderId . "' AND oh.notify = '1' ORDER BY oh.date_added, oh.order_history_id LIMIT 50")->rows;
	}

	/**
	 * Status names of the order (all its history rows + the current status, in
	 * every language). Used only to detect the courier ("Изпратена с
	 * NextLevel"); never sent to the model. No comments are read here.
	 */
	private function statusNames(array $order) {
		$rows = $this->db->query("SELECT DISTINCT os.name FROM `" . DB_PREFIX . "order_status` os WHERE os.order_status_id IN ("
			. "SELECT oh.order_status_id FROM `" . DB_PREFIX . "order_history` oh WHERE oh.order_id = '" . (int)$order['order_id'] . "'"
			. ") OR os.order_status_id = '" . (int)$order['order_status_id'] . "'")->rows;
		$out = array();

		foreach ($rows as $row) {
			$out[] = $this->text($row['name']);
		}

		return implode(' | ', $out);
	}

	/**
	 * Comment text for the model: plain text, links replaced (tracking comes as
	 * cards), payment / transaction lines removed, length-capped.
	 */
	private function cleanComment($comment) {
		$text = html_entity_decode((string)$comment, ENT_QUOTES, 'UTF-8');
		$text = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $text));
		$text = self::stripPayment($text);
		$text = preg_replace('~(?:https?://|www\.)[^\s<>"\']+~i', '[link]', $text);
		$text = trim(preg_replace("/[ \t]+/", ' ', $text));

		return function_exists('mb_substr') ? mb_substr($text, 0, 500, 'UTF-8') : substr($text, 0, 500);
	}

	/** Removes sentences / lines about payments and transaction ids (gateway notes). */
	public static function stripPayment($text) {
		$parts = preg_split('/(\n|(?<=[.!?])\s+)/u', (string)$text);
		$keep = array();

		foreach ($parts as $part) {
			if (preg_match('/(transaction|транзакци|συναλλαγ|payment|плащане|πληρωμ|revolut|paypal|stripe|epay|borica|борика|mypos|card\s*\*|\bID\s*:\s*[0-9a-f]{6,}-)/iu', $part)) {
				continue;
			}
			$keep[] = $part;
		}

		return trim(implode(' ', $keep));
	}

	// ================================================================ order stage (change requests)

	/**
	 * delivered | shipped | open, decided conservatively:
	 *
	 *  - delivered: the current status name says delivered / complete
	 *    ("Доставена", "Delivered", "Complete", "Παραδόθηκε"), or the status is
	 *    one of the store's config_complete_status AND its name does not say
	 *    shipped AND the order has no tracking number. (OpenCart's default
	 *    complete list is Complete + Shipped, so the list alone cannot tell
	 *    "delivered" from "on its way".)
	 *  - shipped: the name says shipped / sent / in transit, or the order has
	 *    any tracking number.
	 *  - open: everything else (pending, processing, preparing ...).
	 *
	 * When unsure we answer "shipped", never "delivered": a request a colleague
	 * then turns down costs nothing, a customer wrongly told to file a return
	 * for an undelivered parcel is a real problem.
	 *
	 * @return array {stage, status}
	 */
	public function stage(array $order, $tracking = null) {
		$names = array();
		foreach ($this->db->query("SELECT `name` FROM `" . DB_PREFIX . "order_status` WHERE `order_status_id` = '" . (int)$order['order_status_id'] . "'")->rows as $row) {
			$names[] = $this->text($row['name']);
		}
		$name = implode(' | ', $names);
		$tracking = is_array($tracking) ? $tracking : $this->tracking($order);
		$complete = $this->config->get('config_complete_status');
		$complete = is_array($complete) ? array_map('intval', $complete) : array();
		$shippedWords = '/(ship|sent|dispatch|transit|out for delivery|изпрат|експедир|предаден|на път|в процес на доставка|απεστάλ|σε μεταφορ|σε διανομ)/iu';
		$deliveredWords = '/(deliver(ed)?\b|complete|достав(ен|ена|ено|ени)\b|получен|завърш|изпълнен|приключ|παραδόθ|παραδοθ|ολοκληρ)/iu';
		$saysShipped = (bool)preg_match($shippedWords, $name);

		if (preg_match($deliveredWords, $name) && !preg_match('/out for delivery/i', $name)) {
			return array('stage' => 'delivered', 'status' => $name);
		}

		if (in_array((int)$order['order_status_id'], $complete, true) && !$saysShipped && !$tracking) {
			return array('stage' => 'delivered', 'status' => $name);
		}

		if ($saysShipped || $tracking) {
			return array('stage' => 'shipped', 'status' => $name);
		}

		return array('stage' => 'open', 'status' => $name);
	}

	/** How the visitor may see this order: "logged_in" (own) or "phone" (number + phone in this chat). */
	public function verifiedBy(array $order) {
		$customerId = $this->customerId();

		if ($customerId && (int)$order['customer_id'] === $customerId) {
			return 'logged_in';
		}

		return ($this->conversation && (int)$this->conversation['verified_order_id'] === (int)$order['order_id']) ? 'phone' : '';
	}

	/** Plain-text order summary for the store's e-mail. */
	public function summaryText(array $order) {
		$lines = array();
		$status = $this->db->query("SELECT `name` FROM `" . DB_PREFIX . "order_status` WHERE `order_status_id` = '" . (int)$order['order_status_id'] . "' AND `language_id` = '" . (int)$this->languageId . "'")->row;
		$lines[] = 'Order #' . (int)$order['order_id'] . ' · ' . date('Y-m-d', strtotime($order['date_added'])) . ' · ' . ($status ? $this->text($status['name']) : '') . ' · ' . $this->money($order['total'], $order['currency_code'], $order['currency_value']);
		$lines[] = 'Customer: ' . $this->text($order['firstname'] . ' ' . $order['lastname']) . ', ' . $order['email'] . ', ' . $order['telephone'];
		$address = array();
		foreach (array('shipping_firstname', 'shipping_lastname', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_postcode', 'shipping_country') as $k) {
			if (isset($order[$k]) && trim((string)$order[$k]) !== '') {
				$address[] = $this->text($order[$k]);
			}
		}
		$lines[] = 'Delivery: ' . $this->text($order['shipping_method']) . ($address ? ' – ' . implode(', ', $address) : '');
		foreach ($this->orderProducts($order) as $p) {
			$lines[] = '  - ' . $p['name'] . ($p['model'] !== '' ? ' (' . $p['model'] . ')' : '') . ' x' . $p['quantity'];
		}
		foreach ($this->tracking($order) as $t) {
			$lines[] = 'Tracking: ' . $t['number'] . ($t['courier_name'] !== '' ? ' (' . $t['courier_name'] . ')' : '');
		}

		return implode("\n", $lines);
	}

	// ================================================================ tracking numbers

	/** Orders the visitor may see right now (verified in this chat + own recent ones). */
	private function visibleOrders() {
		$out = array();

		if ($this->conversation && (int)$this->conversation['verified_order_id']) {
			$order = $this->orderRow((int)$this->conversation['verified_order_id']);
			if ($order && $this->maySee($order)) {
				$out[(int)$order['order_id']] = $order;
			}
		}

		$customerId = $this->customerId();

		if ($customerId) {
			$rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `customer_id` = '" . (int)$customerId . "' AND `store_id` = '" . (int)$this->storeId . "' AND `order_status_id` > '0' ORDER BY `date_added` DESC LIMIT " . self::MY_ORDERS_MAX)->rows;
			foreach ($rows as $row) {
				$out[(int)$row['order_id']] = $row;
			}
		}

		return $out;
	}

	/**
	 * Tracking numbers of an order: the order's own tracking-number column
	 * (when the shop has one) and links / numbers in history comments with
	 * notify = 1 (comments with notify = 0 are never read). Deduplicated, in
	 * order of appearance (oldest comment first, so the newest is last).
	 *
	 * @return array of {number, courier, courier_name, url, source, detected_by}
	 */
	public function tracking(array $order) {
		$found = array();
		// only the couriers enabled for this store (s_couriers, empty = all), default first
		$couriers = $this->settings->storeCouriers($this->storeId);
		$default = (string)$this->settings->getStore($this->storeId, 's_courier');
		$context = array(
			'order_id' => (string)(int)$order['order_id'],
			'phone'    => isset($order['telephone']) ? (string)$order['telephone'] : '',
			'statuses' => $this->statusNames($order),
			'shipping' => isset($order['shipping_method']) ? $this->text($order['shipping_method']) : '',
		);

		$column = $this->trackingColumn();
		if ($column && !empty($order[$column])) {
			foreach (self::extract((string)$order[$column], $couriers, $default, true, $context) as $t) {
				$found[] = $t;
			}
		}

		foreach ($this->history((int)$order['order_id']) as $h) {
			foreach (self::extract((string)$h['comment'], $couriers, $default, false, $context) as $t) {
				$found[] = $t;
			}
		}

		$out = array();

		foreach ($found as $t) {
			$key = strtoupper($t['number']);
			if (!isset($out[$key])) {
				$out[$key] = $t;
			} elseif (self::rank($t['detected_by']) > self::rank($out[$key]['detected_by'])) {
				$out[$key]['courier'] = $t['courier'];
				$out[$key]['detected_by'] = $t['detected_by'];
				if ($t['source'] !== '') {
					$out[$key]['source'] = $t['source'];
				}
			} elseif ($out[$key]['source'] === '' && $t['source'] !== '' && $t['courier'] === $out[$key]['courier']) {
				$out[$key]['source'] = $t['source'];
			}
		}

		foreach ($out as $key => $t) {
			$out[$key]['courier_name'] = ($t['courier'] !== '' && isset($couriers[$t['courier']])) ? $couriers[$t['courier']]['name'] : '';
			$courier = $t['courier'] !== '' && isset($couriers[$t['courier']]) ? new Courier\Link($couriers[$t['courier']]) : null;
			$url = $courier ? $courier->trackingUrl($t['number']) : '';
			$out[$key]['url'] = $url !== '' ? $url : $t['source'];
		}

		return array_values($out);
	}

	/** Strength of a courier detection (higher wins when a number is found twice). */
	private static function rank($by) {
		$order = array('none' => 0, 'store_default' => 1, 'shipping_method' => 2, 'status' => 3, 'comment' => 4, 'number_format' => 5, 'link' => 6);

		return isset($order[$by]) ? $order[$by] : 0;
	}

	/** Words around a bare number that make it a tracking number (bg, en, el). */
	public static function trackingWords() {
		return array('товарителниц', 'проследи', 'проследяване', 'пратк', 'куриер', 'tracking', 'track', 'awb', 'waybill', 'shipment', 'parcel', 'consignment', 'courier',
			'αποστολ', 'παρακολούθ', 'παρακολουθ', 'δέμα', 'δεμα', 'κωδικός αποστολ', 'voucher', 'βάουτσερ', 'κούριερ', 'courier');
	}

	/**
	 * Tracking numbers in a text. Public static for tests.
	 *
	 *  - URLs: courier from the host (g_couriers[].hosts), numbers from query
	 *    values (several comma separated: shipmentNumber=1,2) and long digit
	 *    path segments. Links to other hosts are ignored.
	 *  - bare numbers (6-20 digits, optional short letter prefix/suffix):
	 *    accepted when they match a courier `pattern`, or when the text right
	 *    before them speaks of a shipment (trackingWords(), courier names), or
	 *    always in a dedicated tracking field. Never: the order id, the
	 *    customer's / a Bulgarian phone number, dates, decimal amounts,
	 *    anything in a payment / transaction sentence.
	 *  - courier: link host -> number pattern -> courier named in the same
	 *    text -> courier named in the order's status names -> shipping method
	 *    -> store default -> none. `detected_by` says which one.
	 *
	 * @param array $context order_id, phone, statuses (status names), shipping (method text)
	 */
	public static function extract($text, array $couriers, $defaultCourier, $isField, array $context = array()) {
		$text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
		$text = str_replace('&amp;', '&', strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $text)));
		$text = self::stripPayment($text);
		$out = array();

		if (preg_match_all('~(?:https?://|www\.)[^\s<>"\']+~i', $text, $m)) {
			foreach ($m[0] as $url) {
				$url = rtrim($url, '.,;:!?)]');
				$full = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
				$host = preg_replace('/^www\./', '', strtolower((string)parse_url($full, PHP_URL_HOST)));
				$code = self::courierForHost($host, $couriers);

				if ($code === '') {
					continue; // not a known courier link: not a tracking link
				}

				$numbers = array();
				$query = (string)parse_url($full, PHP_URL_QUERY);
				if ($query !== '') {
					foreach (explode('&', $query) as $pair) {
						$kv = explode('=', $pair, 2);
						$value = isset($kv[1]) ? rawurldecode($kv[1]) : '';
						foreach (preg_split('/[\s,;|]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
							if (preg_match('/^[A-Za-z]{0,4}\d{6,20}[A-Za-z]{0,4}$/', $part)) {
								$numbers[] = $part;
							}
						}
					}
				}
				foreach (explode('/', (string)parse_url($full, PHP_URL_PATH)) as $segment) {
					foreach (preg_split('/[\s,;|]+/', rawurldecode($segment), -1, PREG_SPLIT_NO_EMPTY) as $part) {
						if (preg_match('/^\d{8,20}$/', $part)) {
							$numbers[] = $part;
						}
					}
				}

				foreach ($numbers as $n) {
					$out[] = array('number' => $n, 'courier' => $code, 'source' => $full, 'detected_by' => 'link');
				}
			}

			$text = preg_replace('~(?:https?://|www\.)[^\s<>"\']+~i', ' ', $text);
		}

		$words = self::trackingWords();
		foreach ($couriers as $c) {
			$words[] = $c['name'];
			foreach ($c['aliases'] as $a) {
				$words[] = $a;
			}
		}
		$quoted = array();
		foreach ($words as $w) {
			if (trim((string)$w) !== '') {
				$quoted[] = preg_quote(trim((string)$w), '/');
			}
		}
		$keywords = '/(' . implode('|', $quoted) . ')/iu';
		$orderId = isset($context['order_id']) ? (string)$context['order_id'] : '';
		$phone = isset($context['phone']) ? self::digits($context['phone']) : '';

		if (preg_match_all('/(?<![\w+\-\/.])([A-Za-z]{0,4}\d{6,20}[A-Za-z]{0,4})(?![\w\-\/]|[.,]\d{1,2}(?!\d))/u', $text, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[1] as $hit) {
				$n = $hit[0];
				$pos = $hit[1];
				$digits = self::digits($n);

				if ($digits === $orderId || ($orderId !== '' && $n === $orderId)) {
					continue;
				}
				if ($phone !== '' && strlen($digits) >= 9 && strlen($digits) <= 13 && substr($digits, -9) === substr($phone, -9)) {
					continue;
				}
				if ($digits === $n && preg_match('/^(0|359)8\d{8}$/', $n)) {
					continue; // a Bulgarian mobile number
				}
				if ($digits === $n && strlen($n) === 8 && (self::isDate(substr($n, 0, 4), substr($n, 4, 2), substr($n, 6, 2)) || self::isDate(substr($n, 4, 4), substr($n, 2, 2), substr($n, 0, 2)))) {
					continue; // 20261022 / 22102026
				}

				$code = '';
				$by = '';
				$matching = array();
				foreach ($couriers as $cc => $c) {
					if ($c['regex'] !== '' && preg_match($c['regex'], $n)) {
						$matching[] = $cc;
					}
				}
				if ($matching) {
					$code = in_array($defaultCourier, $matching, true) ? $defaultCourier : $matching[0];
					$by = 'number_format';
				} else {
					$before = function_exists('mb_strcut') ? mb_strcut($text, max(0, $pos - 120), min($pos, 120) + strlen($n) + 20, 'UTF-8') : substr($text, max(0, $pos - 120), 140);
					if (!$isField && !preg_match($keywords, $before)) {
						continue;
					}
				}

				$out[] = array('number' => $n, 'courier' => $code, 'source' => '', 'detected_by' => $by);
			}
		}

		// courier of the bare numbers without a pattern match
		foreach ($out as $i => $t) {
			if ($t['detected_by'] !== '') {
				continue;
			}
			$sources = array(
				'comment'         => $text,
				'status'          => isset($context['statuses']) ? (string)$context['statuses'] : '',
				'shipping_method' => isset($context['shipping']) ? (string)$context['shipping'] : '',
			);
			foreach ($sources as $by => $haystack) {
				$code = self::courierNamedIn($haystack, $couriers, $defaultCourier);
				if ($code !== '') {
					$out[$i]['courier'] = $code;
					$out[$i]['detected_by'] = $by;
					continue 2;
				}
			}
			if ($defaultCourier !== '' && isset($couriers[$defaultCourier])) {
				$out[$i]['courier'] = $defaultCourier;
				$out[$i]['detected_by'] = 'store_default';
			} else {
				$out[$i]['detected_by'] = 'none';
			}
		}

		return $out;
	}

	private static function isDate($y, $mo, $d) {
		return (int)$y >= 2000 && (int)$y <= 2099 && checkdate((int)$mo, (int)$d, (int)$y);
	}

	/**
	 * The courier whose name, code or alias appears in a text (whole words,
	 * case-insensitive). Several: the store default when it is among them,
	 * else the first. '' when none.
	 */
	public static function courierNamedIn($text, array $couriers, $defaultCourier = '') {
		$text = (string)$text;

		if (trim($text) === '') {
			return '';
		}

		$hits = array();

		foreach ($couriers as $code => $c) {
			$names = array_merge(array($c['name'], $code), $c['aliases']);
			foreach ($names as $name) {
				$name = trim((string)$name);
				if (self::len($name) < 3) {
					continue;
				}
				if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $text)) {
					$hits[] = $code;
					break;
				}
			}
		}

		if (!$hits) {
			return '';
		}

		return in_array($defaultCourier, $hits, true) ? $defaultCourier : $hits[0];
	}

	private static function len($s) {
		return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : strlen((string)$s);
	}

	public static function courierForHost($host, array $couriers) {
		$host = preg_replace('/^www\./', '', strtolower((string)$host));

		if ($host === '') {
			return '';
		}

		foreach ($couriers as $code => $c) {
			foreach ($c['hosts'] as $h) {
				if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) {
					return $code;
				}
			}
		}

		return '';
	}

	/** Courier adapter: Speedy with credentials, otherwise the link-only adapter. */
	public function courier($code) {
		$couriers = $this->settings->couriers();

		if ($code === '' || !isset($couriers[$code])) {
			return null;
		}

		$c = $couriers[$code];

		if ($c['type'] === 'speedy' && $this->settings->hasCourierSecret($code)) {
			return new Courier\Speedy($c, $this->settings->getCourierSecret($code), $this->registry->get('cache'));
		}

		return new Courier\Link($c);
	}

	private function trackingColumn() {
		if (self::$trackingColumn === false) {
			self::$trackingColumn = '';
			// `tracking` in OpenCart's order table is the affiliate tracking code, not a shipment number
			foreach (array('tracking_number', 'shipment_number', 'tracking_no', 'shipping_tracking', 'tracking_code') as $column) {
				if ($this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "order` LIKE '" . $column . "'")->num_rows) {
					self::$trackingColumn = $column;
					break;
				}
			}
		}

		return self::$trackingColumn;
	}

	private function allow(array $t) {
		if (!empty($t['url'])) {
			$this->allowedUrls[$t['url']] = true;
		}
		if (!empty($t['source'])) {
			$this->allowedUrls[$t['source']] = true;
		}
	}

	// ================================================================ "view order" link

	/** Name of the order_view_keys table, or '' when the shop does not have the module. */
	private function viewTable() {
		if (self::$viewTable === false) {
			self::$viewTable = '';
			foreach (array_unique(array(DB_PREFIX . 'order_view_keys', 'order_view_keys')) as $table) {
				if ($this->db->query("SHOW TABLES LIKE '" . $this->db->escape(addcslashes($table, '%_')) . "'")->num_rows) {
					self::$viewTable = $table;
					break;
				}
			}
		}

		return self::$viewTable;
	}

	/**
	 * store_url + index.php?route=extension/module/order_view&order_id=..&key=..
	 * The existing key is reused; a key is created only when the order has
	 * none, and never overwritten (old e-mailed links keep working).
	 */
	public function viewOrderLink(array $order) {
		if (!(int)$this->settings->getStore($this->storeId, 's_order_link_button') || !$this->maySee($order)) {
			return '';
		}

		$table = $this->viewTable();

		if ($table === '') {
			return '';
		}

		$orderId = (int)$order['order_id'];

		try {
			$key = $this->readViewKey($table, $orderId);

			if ($key === '') {
				$new = uniqid() . md5((string)rand());
				$this->db->query("INSERT INTO `" . $table . "` (`order_id`, `view_key`) SELECT '" . $orderId . "', '" . $this->db->escape($new) . "' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `" . $table . "` WHERE `order_id` = '" . $orderId . "' AND `view_key` <> '')");
				$key = $this->readViewKey($table, $orderId);
			}
		} catch (\Exception $e) {
			return '';
		}

		if ($key === '') {
			return '';
		}

		$base = trim((string)$order['store_url']);
		if ($base === '') {
			$base = (string)$this->config->get('config_url');
		}

		return rtrim($base, '/') . '/index.php?route=extension/module/order_view&order_id=' . $orderId . '&key=' . rawurlencode($key);
	}

	private function readViewKey($table, $orderId) {
		$row = $this->db->query("SELECT `view_key` FROM `" . $table . "` WHERE `order_id` = '" . (int)$orderId . "' AND `view_key` <> '' ORDER BY 1 LIMIT 1")->row;

		return $row ? (string)$row['view_key'] : '';
	}

	// ================================================================ cards

	private function orderCard(array $order, array $details, $viewLink) {
		$href = $viewLink;

		if ($href === '' && $this->customerId() && (int)$order['customer_id'] === $this->customerId()) {
			$url = $this->registry->get('url');
			$href = $url ? str_replace('&amp;', '&', $url->link('account/order/info', 'order_id=' . (int)$order['order_id'], true)) : '';
		}

		$card = array(
			'type'     => 'order',
			'title'    => sprintf($this->lang('card_order', 'Order #%s'), (int)$order['order_id']),
			'subtitle' => implode(' · ', array_filter(array($details['status'], $details['total'], $details['date']))),
			'href'     => $href,
			'number'   => (string)(int)$order['order_id'],
			'status'   => $details['status'],
			'total'    => $details['total'],
		);

		if ($viewLink !== '') {
			$card['button'] = $this->lang('card_view_order', 'View order');
		}

		return $card;
	}

	private function trackingCard(array $t, $status) {
		$subtitle = '';

		if (is_array($status)) {
			$subtitle = !empty($status['delivered']) ? $this->lang('card_delivered', 'Delivered') : (string)$status['last_event'];
			if (!empty($status['last_time'])) {
				$subtitle .= ($subtitle !== '' ? ' · ' : '') . $status['last_time'];
			}
		}

		$card = array(
			'type'     => 'tracking',
			'title'    => trim(($t['courier_name'] !== '' ? $t['courier_name'] . ' ' : '') . $t['number']),
			'subtitle' => $subtitle !== '' ? $subtitle : $this->lang('card_track', 'Track shipment'),
			'href'     => $t['url'],
			'courier'  => $t['courier_name'],
			'number'   => $t['number'],
		);

		if ($subtitle !== '') {
			$card['status'] = $subtitle;
		}

		return $card;
	}

	// ================================================================ text

	private function lang($key, $fallback) {
		$language = $this->registry->get('language');
		$text = $language ? $language->get($key) : $key;

		return ($text === $key || $text === '') ? $fallback : $text;
	}

	private function langCode() {
		$code = strtolower((string)$this->config->get('config_language'));

		return substr($code, 0, 2);
	}

	private function money($amount, $currency, $value) {
		$c = $this->registry->get('currency');

		return $c ? $c->format((float)$amount, $currency, $value) : sprintf('%.2f %s', (float)$amount * (float)$value, $currency);
	}

	private function text($value) {
		return trim(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'));
	}
}
