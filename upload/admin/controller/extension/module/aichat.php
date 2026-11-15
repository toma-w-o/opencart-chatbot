<?php
class ControllerExtensionModuleAichat extends Controller {
	private $error = array();
	private $days = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');
	private $strings = null;

	// ------------------------------------------------------------------
	// helpers
	// ------------------------------------------------------------------

	private function settings() {
		return new \Aichat\Settings($this->registry);
	}

	/** Module strings (en-gb first, then the admin language) without touching the shared language registry. */
	private function strings() {
		if ($this->strings === null) {
			$this->strings = array();
			$code = $this->config->get('config_admin_language');
			foreach (array('en-gb', $code) as $dir) {
				$file = DIR_LANGUAGE . $dir . '/extension/module/aichat.php';
				if ($dir && is_file($file)) {
					$_ = array();
					include $file;
					$this->strings = array_merge($this->strings, $_);
				}
			}
		}

		return $this->strings;
	}

	private function str($key) {
		$s = $this->strings();

		return isset($s[$key]) ? $s[$key] : $key;
	}

	/** Version/upgrade hook of the library plus the menu event; safe to call on every page. */
	private function boot() {
		if (class_exists('\Aichat\Installer') && method_exists('\Aichat\Installer', 'upgrade')) {
			\Aichat\Installer::upgrade($this->registry);
		}

		$this->ensureMenuEvent();
	}

	private function ensureMenuEvent() {
		$q = $this->db->query("SELECT `event_id` FROM `" . DB_PREFIX . "event` WHERE `action` = 'extension/module/aichat/menu' LIMIT 1");

		if (!$q->num_rows) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET `code` = 'aichat_menu', `trigger` = 'admin/view/common/column_left/before', `action` = 'extension/module/aichat/menu', `status` = '1', `sort_order` = '0'");
		}
	}

	private function pageData($active) {
		$this->load->language('extension/module/aichat');

		$data = array();
		foreach (array_keys($this->strings()) as $k) {
			$data[$k] = $this->language->get($k);
		}
		foreach (array('text_home', 'text_enabled', 'text_disabled', 'text_yes', 'text_no', 'text_no_results', 'button_save', 'button_cancel', 'button_filter', 'button_clear', 'button_back') as $k) {
			$data[$k] = $this->language->get($k);
		}

		$token = $this->session->data['user_token'];
		$data['user_token'] = $token;
		$data['subnav'] = $this->subnav($active, $token);
		$this->load->model('extension/module/aichat');
		$data['upgrade_pending'] = !$this->model_extension_module_aichat->hasTable('request') || !$this->model_extension_module_aichat->hasTable('kb');
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $token, true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/aichat/dashboard', 'user_token=' . $token, true))
		);

		return $data;
	}

	private function subnav($active, $token) {
		$items = array(
			'dashboard'     => array('extension/module/aichat/dashboard', 'fa-tachometer', 'menu_dashboard'),
			'conversations' => array('extension/module/aichat/conversations', 'fa-comments-o', 'menu_conversations'),
			'requests'      => array('extension/module/aichat/requests', 'fa-inbox', 'menu_requests'),
			'kb'            => array('extension/module/aichat/kb', 'fa-book', 'menu_kb'),
			'testchat'      => array('extension/module/aichat/testchat', 'fa-flask', 'menu_testchat'),
			'settings'      => array('extension/module/aichat', 'fa-cog', 'menu_settings')
		);
		$this->load->model('extension/module/aichat');
		$open = $this->model_extension_module_aichat->countOpenRequests();
		$html = '<ul class="nav nav-pills aichat-subnav">';
		foreach ($items as $key => $item) {
			$badge = ($key === 'requests' && $open > 0) ? ' <span class="label label-danger aichat-badge">' . (int)$open . '</span>' : '';
			$html .= '<li' . ($key === $active ? ' class="active"' : '') . '><a href="' . $this->url->link($item[0], 'user_token=' . $token, true) . '"><i class="fa ' . $item[1] . '"></i> ' . htmlspecialchars($this->str($item[2]), ENT_QUOTES, 'UTF-8') . $badge . '</a></li>';
		}

		return $html . '</ul>';
	}

	private function storeList() {
		$this->load->model('setting/store');
		$stores = array(array('store_id' => 0, 'name' => $this->config->get('config_name') . ' ' . $this->str('text_default')));
		foreach ($this->model_setting_store->getStores() as $store) {
			$stores[] = array('store_id' => (int)$store['store_id'], 'name' => $store['name']);
		}

		return $stores;
	}

	/** OpenCart HTML-escapes all request input; the module stores and escapes on output itself. */
	private function post() {
		return $this->decode($this->request->post);
	}

	private function decode($v) {
		if (is_array($v)) {
			return array_map(array($this, 'decode'), $v);
		}

		return html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
	}

	private function json($payload) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP));
	}

	private function link($route, $args = '') {
		return str_replace('&amp;', '&', $this->url->link($route, 'user_token=' . $this->session->data['user_token'] . $args, true));
	}

	private function safeUrl($url) {
		return preg_match('#^https?://#i', (string)$url) ? (string)$url : '';
	}

	// ------------------------------------------------------------------
	// left menu (event admin/view/common/column_left/before)
	// ------------------------------------------------------------------

	public function menu(&$route, &$data) {
		if (!isset($data['menus']) || !is_array($data['menus']) || !$this->user->hasPermission('access', 'extension/module/aichat') || !isset($this->session->data['user_token'])) {
			return;
		}

		foreach ($data['menus'] as $m) {
			if (isset($m['id']) && $m['id'] === 'menu-aichat') {
				return;
			}
		}

		$token = 'user_token=' . $this->session->data['user_token'];
		$entry = array(
			'id'       => 'menu-aichat',
			'icon'     => 'fa-comments',
			'name'     => $this->str('menu_title'),
			'href'     => '',
			'children' => array(
				array('name' => $this->str('menu_dashboard'), 'href' => $this->url->link('extension/module/aichat/dashboard', $token, true), 'children' => array()),
				array('name' => $this->str('menu_conversations'), 'href' => $this->url->link('extension/module/aichat/conversations', $token, true), 'children' => array()),
				array('name' => $this->str('menu_requests'), 'href' => $this->url->link('extension/module/aichat/requests', $token, true), 'children' => array()),
				array('name' => $this->str('menu_kb'), 'href' => $this->url->link('extension/module/aichat/kb', $token, true), 'children' => array()),
				array('name' => $this->str('menu_testchat'), 'href' => $this->url->link('extension/module/aichat/testchat', $token, true), 'children' => array()),
				array('name' => $this->str('menu_settings'), 'href' => $this->url->link('extension/module/aichat', $token, true), 'children' => array())
			)
		);

		// before "Extensions" when present, else at the end
		$out = array();
		$placed = false;
		foreach ($data['menus'] as $m) {
			if (!$placed && isset($m['id']) && $m['id'] === 'menu-extension') {
				$out[] = $entry;
				$placed = true;
			}
			$out[] = $m;
		}
		if (!$placed) {
			$out[] = $entry;
		}
		$data['menus'] = $out;
	}

	// ------------------------------------------------------------------
	// factory defaults
	// ------------------------------------------------------------------

	private function langKey($code) {
		$c = strtolower($code);
		if (strpos($c, 'bg') === 0) {
			return 'bg';
		}
		if (strpos($c, 'el') === 0 || strpos($c, 'gr') === 0) {
			return 'el';
		}

		return 'en';
	}

	private function factory($languages) {
		$text = array(
			'greeting' => array(
				'bg' => 'Здравейте! Аз съм AI асистентът на магазина. С какво мога да ви помогна? 🙂',
				'en' => 'Hello! I am the store\'s AI assistant. How can I help you today? 🙂',
				'el' => 'Γειά σας! Είμαι ο βοηθός AI του καταστήματος. Πώς μπορώ να βοηθήσω; 🙂'
			),
			'welcome_back' => array(
				'bg' => 'Мога ли да помогна с още нещо?',
				'en' => 'Can I help you with anything else?',
				'el' => 'Μπορώ να βοηθήσω με κάτι ακόμα;'
			),
			'proactive' => array(
				'bg' => 'Здравейте! Мога ли да ви помогна да намерите нещо?',
				'en' => 'Hi! Can I help you find something?',
				'el' => 'Γειά σας! Μπορώ να σας βοηθήσω να βρείτε κάτι;'
			),
			'quick' => array(
				'bg' => "Къде е моята поръчка?\nПрепоръчайте ми продукт\nКакви са начините за доставка?",
				'en' => "Where is my order?\nRecommend a product\nWhat are the delivery options?",
				'el' => "Πού είναι η παραγγελία μου;\nΠροτείνετέ μου ένα προϊόν\nΠοιοι είναι οι τρόποι αποστολής;"
			)
		);

		$f = array(
			's_color_accent'         => '#2563eb',
			's_color_header_bg'      => '#1e3a8a',
			's_color_header_text'    => '#ffffff',
			's_color_user_bg'        => '#2563eb',
			's_color_user_text'      => '#ffffff',
			's_launcher_style'       => 'avatar',
			's_launcher_shape'       => 'circle',
			's_launcher_size'        => 56,
			's_launcher_avatar_size' => 45,
			's_pos_desktop_side'     => 'right',
			's_pos_desktop_x'        => 20,
			's_pos_desktop_y'        => 20,
			's_pos_mobile_side'      => 'right',
			's_pos_mobile_x'         => 20,
			's_pos_mobile_y'         => 20,
			's_zindex'               => 9999,
			's_custom_css'           => '',
			's_emoji'                => 1,
			's_resume_days'          => 7,
			's_handover_timeout'     => 60,
			's_proactive'            => 0,
			's_proactive_delay'      => 20,
			's_sound'                => 1,
			's_feat_images'          => 1,
			's_feat_rating'          => 1,
			's_feat_cart'            => 1,
			's_proactive_text'       => array(),
			's_greeting'             => array(),
			's_welcome_back'         => array(),
			's_quick_replies'        => array(),
			's_launcher_text'        => array()
		);

		// scalar defaults of the server library win, so the form and the widget agree
		if (class_exists('\\Aichat\\Settings') && method_exists('\\Aichat\\Settings', 'storeDefaults')) {
			foreach (\Aichat\Settings::storeDefaults() as $key => $value) {
				if (array_key_exists($key, $f) && !is_array($f[$key]) && is_scalar($value) && $value !== '') {
					$f[$key] = $value;
				}
			}
		}

		foreach ($languages as $language) {
			$lid = (int)$language['language_id'];
			$k = $this->langKey($language['code']);
			$f['s_greeting'][$lid] = $text['greeting'][$k];
			$f['s_welcome_back'][$lid] = $text['welcome_back'][$k];
			$f['s_quick_replies'][$lid] = $text['quick'][$k];
			$f['s_launcher_text'][$lid] = '';
			$f['s_proactive_text'][$lid] = $text['proactive'][$k];
		}

		return $f;
	}

	/** Fills empty stored values with the factory defaults. */
	private function fill($s, $factory) {
		foreach ($factory as $key => $def) {
			if (is_array($def)) {
				$cur = isset($s[$key]) && is_array($s[$key]) ? $s[$key] : array();
				foreach ($def as $lid => $v) {
					if (!isset($cur[$lid]) || ($cur[$lid] === '' && $key !== 's_launcher_text')) {
						$cur[$lid] = $v;
					}
				}
				$s[$key] = $cur;
			} elseif (!isset($s[$key]) || ($s[$key] === '' && $key !== 's_custom_css')) {
				$s[$key] = $def;
			}
		}

		return $s;
	}

	private function avatars() {
		$list = array();
		$file = DIR_CATALOG . 'view/javascript/aichat/avatars/manifest.json';
		if (is_file($file)) {
			$json = json_decode((string)file_get_contents($file), true);
			if (is_array($json)) {
				foreach ($json as $item) {
					if (is_array($item) && !empty($item['id']) && preg_match('/^[A-Za-z0-9_-]+$/', $item['id'])) {
						$list[] = array('id' => $item['id'], 'name' => isset($item['name']) ? (string)$item['name'] : $item['id']);
					}
				}
			}
		}

		if (!$list) {
			$list = array(
				array('id' => 'default', 'name' => $this->str('text_avatar_default')),
				array('id' => 'robot', 'name' => 'Robot'),
				array('id' => 'fox', 'name' => 'Fox'),
				array('id' => 'owl', 'name' => 'Owl'),
				array('id' => 'cat', 'name' => 'Cat')
			);
		}

		return $list;
	}

	// ------------------------------------------------------------------
	// settings page
	// ------------------------------------------------------------------

	public function index() {
		$this->boot();
		$data = $this->pageData('settings');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->document->addStyle('view/stylesheet/aichat.css');
		$this->document->addScript('view/javascript/aichat/settings.js');

		$this->load->model('localisation/language');

		$settings = $this->settings();
		$languages = array_values($this->model_localisation_language->getLanguages());
		$stores = $this->storeList();
		$factory = $this->factory($languages);
		$hasCourierSecret = method_exists($settings, 'hasCourierSecret');

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate($stores, $languages, $factory)) {
			$post = $this->post();

			$settings->saveGlobal($this->cleanGlobal(isset($post['g']) ? $post['g'] : array()));

			// write-only secrets: empty keeps the existing ones
			$key = isset($post['api_key']) ? trim($post['api_key']) : '';
			if ($key !== '') {
				$settings->setApiKey($key);
			}
			if (method_exists($settings, 'setSecret')) {
				$tgToken = isset($post['tg_token']) ? trim($post['tg_token']) : '';
				if ($tgToken !== '') {
					$settings->setSecret('tg_token', $tgToken);
				} elseif (!empty($post['tg_token_clear'])) {
					$settings->setSecret('tg_token', '');
				}
			}
			if (method_exists($settings, 'setCourierSecret')) {
				foreach ($this->cleanCouriers(isset($post['couriers']) ? $post['couriers'] : array()) as $i => $courier) {
					$u = isset($post['courier_secret'][$i]['username']) ? trim($post['courier_secret'][$i]['username']) : '';
					$p = isset($post['courier_secret'][$i]['password']) ? (string)$post['courier_secret'][$i]['password'] : '';
					if ($courier['type'] === 'speedy' && $u !== '' && $p !== '') {
						$settings->setCourierSecret($courier['code'], array('username' => $u, 'password' => $p));
					}
				}
			}

			foreach ($stores as $store) {
				$sid = $store['store_id'];
				if (isset($post['s'][$sid])) {
					$settings->saveStore($sid, $this->cleanStore($post['s'][$sid], $languages, $factory));
				}
			}

			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('extension/module/aichat', 'user_token=' . $this->session->data['user_token'], true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['errors'] = $this->error;

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		$data['action'] = $this->url->link('extension/module/aichat', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		$posted = $this->request->server['REQUEST_METHOD'] == 'POST';
		$g = $settings->getGlobal();
		$pp = $this->post();
		if ($posted && isset($pp['g'])) {
			$g = array_merge($g, $pp['g']);
		}
		$g['g_warn_email'] = isset($g['g_warn_email']) ? $g['g_warn_email'] : '';
		$g['g_trust_cf_ip'] = !empty($g['g_trust_cf_ip']) ? 1 : 0;
		$data['g'] = $g;
		$data['has_key'] = $settings->hasApiKey();

		// Telegram (round 3): token is write-only
		$data['tg_supported'] = class_exists('\\Aichat\\Telegram') && method_exists($settings, 'setSecret');
		$data['tg_has_token'] = method_exists($settings, 'hasSecret') ? (bool)$settings->hasSecret('tg_token') : false;
		$data['tg_https'] = strpos((string)HTTP_CATALOG, 'https://') === 0;
		$data['tg_catalog'] = (string)HTTP_CATALOG;
		$data['tg_webhook_url'] = $this->link('extension/module/aichat/tg_webhook');
		$data['tg_test_url'] = $this->link('extension/module/aichat/tg_test');
		$g['g_timezone'] = !empty($g['g_timezone']) ? $g['g_timezone'] : 'Europe/Sofia';
		$g['g_model_light'] = isset($g['g_model_light']) && $g['g_model_light'] !== '' ? $g['g_model_light'] : $this->lightModel();
		foreach (array('g_price_light_in' => 0.1, 'g_price_light_out' => 0.5, 'g_tg_chat_id' => '', 'g_tg_translate' => 0, 'g_tg_staff_lang' => 'bg', 'g_report_weekly' => 0, 'g_report_email' => '') as $k => $def) {
			$g[$k] = isset($g[$k]) ? $g[$k] : $def;
		}
		$data['g'] = $g;
		$canned = isset($g['g_tg_canned']) && is_array($g['g_tg_canned']) ? $g['g_tg_canned'] : array();
		if ($posted && isset($pp['g']['g_tg_canned'])) {
			$canned = is_array($pp['g']['g_tg_canned']) ? $pp['g']['g_tg_canned'] : array();
		}
		$data['canned'] = array();
		foreach (array_values($canned) as $i => $row) {
			$data['canned'][] = array('idx' => $i, 'code' => isset($row['code']) ? (string)$row['code'] : '', 'text' => isset($row['text']) ? (string)$row['text'] : '');
		}
		$data['staff_langs'] = $this->staffLangs(isset($g['g_tg_staff_lang']) ? (string)$g['g_tg_staff_lang'] : 'bg');

		// couriers
		$couriers = isset($g['g_couriers']) && is_array($g['g_couriers']) ? $g['g_couriers'] : array();
		if ($posted && isset($pp['couriers'])) {
			$couriers = $pp['couriers'];
		}
		$data['couriers'] = array();
		foreach ($couriers as $i => $c) {
			$code = isset($c['code']) ? (string)$c['code'] : '';
			$hosts = isset($c['hosts']) ? $c['hosts'] : '';
			$data['couriers'][] = array(
				'idx'       => $i,
				'code'      => $code,
				'name'      => isset($c['name']) ? (string)$c['name'] : '',
				'type'      => isset($c['type']) && $c['type'] === 'speedy' ? 'speedy' : 'link',
				'hosts'     => is_array($hosts) ? implode(', ', $hosts) : (string)$hosts,
				'track_url' => isset($c['track_url']) ? (string)$c['track_url'] : '',
				'secret'    => ($hasCourierSecret && $code !== '') ? (bool)$settings->hasCourierSecret($code) : false
			);
		}
		$data['courier_secrets_supported'] = method_exists($settings, 'setCourierSecret');

		$data['languages'] = array();
		foreach ($languages as $language) {
			$data['languages'][] = array('language_id' => (int)$language['language_id'], 'name' => $language['name'], 'code' => $language['code']);
		}
		$data['days'] = $this->days;
		$data['avatars'] = $this->avatars();
		$data['avatar_base'] = HTTP_CATALOG . 'catalog/view/javascript/aichat/avatars/';

		$data['stores'] = array();
		foreach ($stores as $store) {
			$sid = $store['store_id'];
			$s = $settings->getStore($sid);
			if ($posted && isset($pp['s'][$sid])) {
				$s = array_merge(is_array($s) ? $s : array(), $pp['s'][$sid]);
			}
			$s = $this->fill(is_array($s) ? $s : array(), $factory);
			foreach (array('s_contacts', 's_work_hours') as $k) {
				$s[$k] = isset($s[$k]) && is_array($s[$k]) ? $s[$k] : array();
			}
			$s['s_courier'] = isset($s['s_courier']) ? (string)$s['s_courier'] : '';
			$s['s_couriers'] = isset($s['s_couriers']) && is_array($s['s_couriers']) ? array_values(array_map('strval', $s['s_couriers'])) : array();
			$s['s_tg_chat_id'] = isset($s['s_tg_chat_id']) ? (string)$s['s_tg_chat_id'] : '';
			$data['stores'][] = array('store_id' => $sid, 'name' => $store['name'], 's' => $s);
		}

		$data['factory'] = $factory;
		$data['start_tab'] = isset($this->request->get['tab']) ? preg_replace('/[^a-z]/', '', $this->request->get['tab']) : '';

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat', $data));
	}

	private function cleanGlobal($in) {
		$out = array();
		$out['g_model'] = isset($in['g_model']) ? trim($in['g_model']) : '';
		$out['g_instructions'] = isset($in['g_instructions']) ? trim($in['g_instructions']) : '';
		$out['g_price_in'] = (float)$this->num(isset($in['g_price_in']) ? $in['g_price_in'] : 0);
		$out['g_price_out'] = (float)$this->num(isset($in['g_price_out']) ? $in['g_price_out'] : 0);
		$out['g_budget_month'] = (float)$this->num(isset($in['g_budget_month']) ? $in['g_budget_month'] : 0);
		$out['g_warn_email'] = isset($in['g_warn_email']) ? trim($in['g_warn_email']) : '';
		$out['g_turnstile_site'] = isset($in['g_turnstile_site']) ? trim($in['g_turnstile_site']) : '';
		$out['g_turnstile_secret'] = isset($in['g_turnstile_secret']) ? trim($in['g_turnstile_secret']) : '';
		$out['g_trust_cf_ip'] = empty($in['g_trust_cf_ip']) ? 0 : 1;
		$out['g_retention_months'] = (int)(isset($in['g_retention_months']) ? $in['g_retention_months'] : 12);
		$out['g_image_retention_days'] = (int)(isset($in['g_image_retention_days']) ? $in['g_image_retention_days'] : 30);
		$light = isset($in['g_model_light']) ? trim($in['g_model_light']) : '';
		$out['g_model_light'] = $light !== '' ? $light : $this->lightModel();
		$tz = isset($in['g_timezone']) ? trim($in['g_timezone']) : '';
		$out['g_timezone'] = in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Europe/Sofia';
		$out['g_price_light_in'] = (float)$this->num(isset($in['g_price_light_in']) ? $in['g_price_light_in'] : 0);
		$out['g_price_light_out'] = (float)$this->num(isset($in['g_price_light_out']) ? $in['g_price_light_out'] : 0);
		$out['g_tg_chat_id'] = isset($in['g_tg_chat_id']) ? trim($in['g_tg_chat_id']) : '';
		$out['g_tg_translate'] = empty($in['g_tg_translate']) ? 0 : 1;
		$out['g_tg_staff_lang'] = isset($in['g_tg_staff_lang']) && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z]{2,4})?$/', trim($in['g_tg_staff_lang'])) ? trim($in['g_tg_staff_lang']) : 'bg';
		$out['g_report_weekly'] = empty($in['g_report_weekly']) ? 0 : 1;
		$out['g_report_email'] = isset($in['g_report_email']) ? trim($in['g_report_email']) : '';
		$out['g_tg_canned'] = array_values($this->cleanCanned(isset($in['g_tg_canned']) ? $in['g_tg_canned'] : array()));
		$post = $this->post();
		$out['g_couriers'] = array_values($this->cleanCouriers(isset($post['couriers']) ? $post['couriers'] : array()));

		return $out;
	}

	private function cleanCouriers($rows) {
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $i => $row) {
			$code = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', isset($row['code']) ? $row['code'] : ''));
			$name = trim(isset($row['name']) ? $row['name'] : '');
			if ($code === '' && $name === '') {
				continue;
			}
			$hosts = array();
			foreach (preg_split('/[\s,;]+/', isset($row['hosts']) ? (string)$row['hosts'] : '', -1, PREG_SPLIT_NO_EMPTY) as $h) {
				$h = strtolower(preg_replace('#^[a-z]+://#i', '', trim($h)));
				$h = preg_replace('#[/?].*$#', '', $h);
				if ($h !== '') {
					$hosts[] = $h;
				}
			}
			$out[$i] = array(
				'code'      => $code,
				'name'      => $name,
				'type'      => isset($row['type']) && $row['type'] === 'speedy' ? 'speedy' : 'link',
				'hosts'     => array_values(array_unique($hosts)),
				'track_url' => trim(isset($row['track_url']) ? $row['track_url'] : '')
			);
		}

		return $out;
	}

	private function lightModel() {
		return defined('Aichat\\Settings::DEFAULT_LIGHT_MODEL') ? constant('Aichat\\Settings::DEFAULT_LIGHT_MODEL') : 'claude-haiku-5-5';
	}

	/** Languages the staff can read; the saved one is always listed. */
	private function staffLangs($current) {
		$list = array('bg' => 'Български', 'en' => 'English', 'el' => 'Ελληνικά', 'ru' => 'Русский', 'ro' => 'Română', 'de' => 'Deutsch', 'tr' => 'Türkçe', 'fr' => 'Français', 'it' => 'Italiano', 'es' => 'Español');
		if ($current !== '' && !isset($list[$current])) {
			$list[$current] = $current;
		}

		return $list;
	}

	/** {code, text} rows: staff type /code in the Telegram topic. */
	private function cleanCanned($rows) {
		$out = array();
		foreach (is_array($rows) ? $rows : array() as $row) {
			$code = strtolower(ltrim(trim(isset($row['code']) ? (string)$row['code'] : ''), '/'));
			$code = preg_replace('/[^a-z0-9_]/', '', $code);
			$text = trim(str_replace("\r", '', isset($row['text']) ? (string)$row['text'] : ''));
			if ($code === '' && $text === '') {
				continue;
			}
			$out[] = array('code' => substr($code, 0, 32), 'text' => function_exists('mb_substr') ? mb_substr($text, 0, 1000) : substr($text, 0, 1000));
		}

		return $out;
	}

	private function cleanStore($in, $languages, $factory) {
		$out = array();
		$out['s_status'] = empty($in['s_status']) ? 0 : 1;
		$out['s_bot_name'] = isset($in['s_bot_name']) ? trim($in['s_bot_name']) : '';
		$out['s_avatar'] = isset($in['s_avatar']) && preg_match('/^[A-Za-z0-9_-]+$/', $in['s_avatar']) ? $in['s_avatar'] : 'default';

		foreach (array('s_greeting', 's_welcome_back', 's_quick_replies', 's_launcher_text') as $k) {
			$out[$k] = array();
			foreach ($languages as $language) {
				$lid = (int)$language['language_id'];
				$v = isset($in[$k][$lid]) ? trim(str_replace("\r", '', $in[$k][$lid])) : '';
				$out[$k][$lid] = $v;
			}
		}

		$out['s_instructions'] = isset($in['s_instructions']) ? trim($in['s_instructions']) : '';
		$out['s_contacts'] = array();
		foreach (array('phone', 'email', 'viber', 'whatsapp', 'note') as $c) {
			$out['s_contacts'][$c] = isset($in['s_contacts'][$c]) ? trim($in['s_contacts'][$c]) : '';
		}
		$out['s_work_hours'] = array();
		foreach ($this->days as $d) {
			$out['s_work_hours'][$d] = array(
				'from' => isset($in['s_work_hours'][$d]['from']) ? trim($in['s_work_hours'][$d]['from']) : '',
				'to'   => isset($in['s_work_hours'][$d]['to']) ? trim($in['s_work_hours'][$d]['to']) : ''
			);
		}
		$out['s_emoji'] = min(2, max(0, (int)(isset($in['s_emoji']) ? $in['s_emoji'] : 1)));
		$out['s_resume_days'] = (int)(isset($in['s_resume_days']) ? $in['s_resume_days'] : 7);
		$out['s_couriers'] = array();
		foreach (isset($in['s_couriers']) && is_array($in['s_couriers']) ? $in['s_couriers'] : array() as $code) {
			$code = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$code));
			if ($code !== '' && !in_array($code, $out['s_couriers'], true)) {
				$out['s_couriers'][] = $code;
			}
		}
		$out['s_handover_timeout'] = (int)(isset($in['s_handover_timeout']) ? $in['s_handover_timeout'] : 60);
		$out['s_tg_chat_id'] = isset($in['s_tg_chat_id']) ? trim($in['s_tg_chat_id']) : '';
		$out['s_proactive_delay'] = (int)(isset($in['s_proactive_delay']) ? $in['s_proactive_delay'] : 20);
		foreach (array('s_proactive', 's_sound', 's_feat_images', 's_feat_rating', 's_feat_cart') as $k) {
			$out[$k] = empty($in[$k]) ? 0 : 1;
		}
		$out['s_proactive_text'] = array();
		foreach ($languages as $language) {
			$lid = (int)$language['language_id'];
			$out['s_proactive_text'][$lid] = isset($in['s_proactive_text'][$lid]) ? trim(str_replace("\r", '', $in['s_proactive_text'][$lid])) : '';
		}
		$out['s_order_link_button'] = empty($in['s_order_link_button']) ? 0 : 1;
		$out['s_courier'] = isset($in['s_courier']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $in['s_courier']) : '';
		$out['s_budget_month'] = (float)$this->num(isset($in['s_budget_month']) ? $in['s_budget_month'] : 0);
		foreach (array('s_max_chats_day' => 0, 's_max_msgs_chat' => 40, 's_max_msgs_minute' => 6, 's_max_len' => 1000) as $k => $def) {
			$out[$k] = (int)(isset($in[$k]) ? $in[$k] : $def);
		}

		foreach (array('s_color_accent', 's_color_header_bg', 's_color_header_text', 's_color_user_bg', 's_color_user_text') as $k) {
			$out[$k] = isset($in[$k]) && preg_match('/^#[0-9a-f]{6}$/i', trim($in[$k])) ? strtolower(trim($in[$k])) : $factory[$k];
		}
		$out['s_launcher_style'] = isset($in['s_launcher_style']) && $in['s_launcher_style'] === 'avatar_text' ? 'avatar_text' : 'avatar';
		$shape = isset($in['s_launcher_shape']) ? $in['s_launcher_shape'] : '';
		$out['s_launcher_shape'] = in_array($shape, array('circle', 'rounded', 'pill'), true) ? $shape : 'circle';
		foreach (array('s_launcher_size', 's_launcher_avatar_size', 's_pos_desktop_x', 's_pos_desktop_y', 's_pos_mobile_x', 's_pos_mobile_y', 's_zindex') as $k) {
			$out[$k] = isset($in[$k]) && ctype_digit(trim($in[$k])) ? (int)trim($in[$k]) : $factory[$k];
		}
		foreach (array('s_pos_desktop_side', 's_pos_mobile_side') as $k) {
			$out[$k] = isset($in[$k]) && $in[$k] === 'left' ? 'left' : 'right';
		}
		$css = isset($in['s_custom_css']) ? str_replace(array('<', "\r"), '', $in['s_custom_css']) : '';
		$out['s_custom_css'] = trim(function_exists('mb_substr') ? mb_substr($css, 0, 5000) : substr($css, 0, 5000));

		return $out;
	}

	private function num($v) {
		return str_replace(',', '.', trim((string)$v));
	}

	private function isNum($v, $allowEmpty = true) {
		$v = $this->num($v);
		if ($v === '') {
			return $allowEmpty;
		}

		return is_numeric($v) && (float)$v >= 0;
	}

	private function isInt($v, $min, $max) {
		$v = trim((string)$v);

		return $v !== '' && ctype_digit($v) && (int)$v >= $min && (int)$v <= $max;
	}

	private function isTime($v) {
		return $v === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);
	}

	protected function validate($stores, $languages, $factory) {
		if (!$this->user->hasPermission('modify', 'extension/module/aichat')) {
			$this->error['warning'] = $this->language->get('error_permission');
			return false;
		}

		$post = $this->post();
		$g = isset($post['g']) ? $post['g'] : array();

		foreach (array('g_price_in', 'g_price_out', 'g_budget_month') as $k) {
			if (!$this->isNum(isset($g[$k]) ? $g[$k] : '')) {
				$this->error[$k] = $this->language->get('error_number');
			}
		}
		if (!$this->isInt(isset($g['g_retention_months']) ? $g['g_retention_months'] : '', 0, 120)) {
			$this->error['g_retention_months'] = $this->language->get('error_integer');
		}
		if (!$this->isInt(isset($g['g_image_retention_days']) ? $g['g_image_retention_days'] : '', 0, 3650)) {
			$this->error['g_image_retention_days'] = $this->language->get('error_integer');
		}
		if (trim(isset($g['g_model']) ? $g['g_model'] : '') === '') {
			$this->error['g_model'] = $this->language->get('error_model');
		}
		if (!empty($g['g_warn_email']) && !filter_var(trim($g['g_warn_email']), FILTER_VALIDATE_EMAIL)) {
			$this->error['g_warn_email'] = $this->language->get('error_email');
		}
		if (!empty($g['g_report_email']) && !filter_var(trim($g['g_report_email']), FILTER_VALIDATE_EMAIL)) {
			$this->error['g_report_email'] = $this->language->get('error_email');
		}
		foreach (array('g_price_light_in', 'g_price_light_out') as $k) {
			if (!$this->isNum(isset($g[$k]) ? $g[$k] : '')) {
				$this->error[$k] = $this->language->get('error_number');
			}
		}
		if (trim(isset($g['g_tg_chat_id']) ? $g['g_tg_chat_id'] : '') !== '' && !preg_match('/^-?\d{5,20}$/', trim($g['g_tg_chat_id']))) {
			$this->error['g_tg_chat_id'] = $this->language->get('error_tg_chat');
		}
		$tgToken = isset($post['tg_token']) ? trim($post['tg_token']) : '';
		if ($tgToken !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $tgToken)) {
			$this->error['tg_token'] = $this->language->get('error_tg_token');
		}
		$codes = array();
		foreach ($this->cleanCanned(isset($g['g_tg_canned']) ? $g['g_tg_canned'] : array()) as $row) {
			if ($row['code'] === '' || $row['text'] === '') {
				$this->error['canned'] = $this->language->get('error_canned_required');
			} elseif (isset($codes[$row['code']])) {
				$this->error['canned'] = $this->language->get('error_canned_duplicate');
			}
			$codes[$row['code']] = true;
		}

		// couriers
		$seen = array();
		foreach ($this->cleanCouriers(isset($post['couriers']) ? $post['couriers'] : array()) as $i => $c) {
			$e = '';
			if ($c['code'] === '' || $c['name'] === '') {
				$e = $this->language->get('error_courier_required');
			} elseif (isset($seen[$c['code']])) {
				$e = $this->language->get('error_courier_duplicate');
			} elseif ($c['track_url'] !== '' && (!preg_match('#^https?://#i', $c['track_url']) || strpos($c['track_url'], '{number}') === false)) {
				$e = $this->language->get('error_courier_url');
			} elseif ($c['type'] === 'link' && $c['track_url'] === '') {
				$e = $this->language->get('error_courier_url');
			}
			$u = isset($post['courier_secret'][$i]['username']) ? trim($post['courier_secret'][$i]['username']) : '';
			$p = isset($post['courier_secret'][$i]['password']) ? (string)$post['courier_secret'][$i]['password'] : '';
			if ($e === '' && $c['type'] === 'speedy' && (($u === '') !== ($p === ''))) {
				$e = $this->language->get('error_courier_secret');
			}
			$seen[$c['code']] = true;
			if ($e !== '') {
				$this->error['courier_' . $i] = $e;
			}
		}

		foreach ($stores as $store) {
			$sid = $store['store_id'];
			$s = isset($post['s'][$sid]) ? $post['s'][$sid] : array();
			if (!$s) {
				continue;
			}
			$p = 's_' . $sid . '_';
			if (!$this->isNum(isset($s['s_budget_month']) ? $s['s_budget_month'] : '')) {
				$this->error[$p . 's_budget_month'] = $this->language->get('error_number');
			}
			$ranges = array('s_max_chats_day' => array(0, 100000), 's_max_msgs_chat' => array(1, 1000), 's_max_msgs_minute' => array(1, 100), 's_max_len' => array(50, 4000),
				's_resume_days' => array(0, 365), 's_launcher_size' => array(32, 120), 's_launcher_avatar_size' => array(0, 100), 's_zindex' => array(1, 2147483647),
				's_pos_desktop_x' => array(0, 1000), 's_pos_desktop_y' => array(0, 1000), 's_pos_mobile_x' => array(0, 500), 's_pos_mobile_y' => array(0, 1000),
				's_handover_timeout' => array(10, 600), 's_proactive_delay' => array(0, 600));
			foreach ($ranges as $k => $r) {
				if (!$this->isInt(isset($s[$k]) ? $s[$k] : '', $r[0], $r[1])) {
					$this->error[$p . $k] = sprintf($this->language->get('error_range'), $r[0], $r[1]);
				}
			}
			if (trim(isset($s['s_tg_chat_id']) ? $s['s_tg_chat_id'] : '') !== '' && !preg_match('/^-?\d{5,20}$/', trim($s['s_tg_chat_id']))) {
				$this->error[$p . 's_tg_chat_id'] = $this->language->get('error_tg_chat');
			}
			if (!empty($s['s_contacts']['email']) && !filter_var($s['s_contacts']['email'], FILTER_VALIDATE_EMAIL)) {
				$this->error[$p . 'email'] = $this->language->get('error_email');
			}
			foreach ($this->days as $d) {
				foreach (array('from', 'to') as $w) {
					$v = isset($s['s_work_hours'][$d][$w]) ? trim($s['s_work_hours'][$d][$w]) : '';
					if (!$this->isTime($v)) {
						$this->error[$p . 'hours'] = $this->language->get('error_time');
					}
				}
			}
		}

		if ($this->error) {
			$this->error['warning'] = $this->language->get('error_warning');
		}

		return !$this->error;
	}

	// Called by extension/extension/module, which already checked the modify
	// permission. The permission for this route is added in that same request,
	// after the user's permissions were loaded, so checking it here would
	// always fail on a fresh install.
	public function install() {
		if ($this->user->hasPermission('modify', 'extension/extension/module')) {
			\Aichat\Installer::install($this->registry);
			$this->ensureMenuEvent();
		}
	}

	public function uninstall() {
		if ($this->user->hasPermission('modify', 'extension/extension/module')) {
			\Aichat\Installer::uninstall($this->registry);
			$this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `action` = 'extension/module/aichat/menu'");
		}
	}

	// ------------------------------------------------------------------
	// dashboard
	// ------------------------------------------------------------------

	public function dashboard() {
		$this->boot();
		$data = $this->pageData('dashboard');
		$this->document->setTitle($this->language->get('text_dashboard'));
		$this->document->addStyle('view/stylesheet/aichat.css');
		$this->document->addScript('view/javascript/aichat/charts.js');
		$this->document->addScript('view/javascript/aichat/dashboard.js');

		$data['stores'] = $this->storeList();
		$data['config_json'] = json_encode(array(
			'stats'  => $this->link('extension/module/aichat/stats'),
			'live'   => $this->link('extension/module/aichat/live'),
			'requests' => $this->link('extension/module/aichat/requests'),
			'stores' => $data['stores'],
			'i18n'   => array(
				'chats' => $this->str('text_chats'), 'cost' => $this->str('text_cost'), 'nodata' => $this->str('text_no_data'), 'now' => $this->str('text_now'),
				'ago_s' => $this->str('text_ago_s'), 'ago_m' => $this->str('text_ago_m'), 'live_empty' => $this->str('text_live_empty'), 'messages' => $this->str('text_messages'),
				'human' => $this->str('text_mode_human'), 'waiting' => $this->str('text_mode_waiting'), 'open' => $this->str('text_open'),
				'budget_off' => $this->str('text_budget_off'), 'of' => $this->str('text_of'), 'updated' => $this->str('text_updated'),
				'page_types' => array('product' => $this->str('page_product'), 'category' => $this->str('page_category'), 'information' => $this->str('page_information'), 'other' => $this->str('page_other')),
				'tools' => array()
			)
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat_dashboard', $data));
	}

	private function storeFilter() {
		return isset($this->request->get['store_id']) && $this->request->get['store_id'] !== '' ? (int)$this->request->get['store_id'] : null;
	}

	public function stats() {
		if (!$this->user->hasPermission('access', 'extension/module/aichat')) {
			return $this->json(array('error' => 'permission'));
		}

		$this->load->language('extension/module/aichat');
		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;
		$sid = $this->storeFilter();
		$range = isset($this->request->get['range']) ? (int)$this->request->get['range'] : 7;
		$range = in_array($range, array(1, 7, 30), true) ? $range : 7;

		$days = array();
		for ($i = $range - 1; $i >= 0; $i--) {
			$days[] = date('Y-m-d', strtotime('-' . $i . ' day'));
		}
		$from = $days[0];

		$stores = $this->storeList();
		$chats = array();
		$cost = array();
		foreach ($stores as $st) {
			$chats[$st['store_id']] = array_fill(0, count($days), 0);
			$cost[$st['store_id']] = array_fill(0, count($days), 0.0);
		}

		$tot = array('chats' => 0, 'messages' => 0, 'cost' => 0.0);
		foreach ($m->getUsage($sid, $from) as $row) {
			$idx = array_search($row['day'], $days, true);
			if ($idx === false || !isset($chats[(int)$row['store_id']])) {
				continue;
			}
			$chats[(int)$row['store_id']][$idx] += (int)$row['chats'];
			$cost[(int)$row['store_id']][$idx] += (float)$row['cost'];
			$tot['chats'] += (int)$row['chats'];
			$tot['messages'] += (int)$row['messages'];
			$tot['cost'] += (float)$row['cost'];
		}

		// topics / tools / products from message meta
		$products = array();
		$tools = array();
		$topics = array();
		$errors = 0;
		foreach ($m->getMetaRows($sid, $from, 3000) as $row) {
			$meta = json_decode($row['meta'], true);
			if (!is_array($meta)) {
				continue;
			}
			if ($row['role'] === 'user') {
				$type = isset($meta['page']['type']) ? (string)$meta['page']['type'] : 'other';
				$topics[$type] = (isset($topics[$type]) ? $topics[$type] : 0) + 1;
				continue;
			}
			if (!empty($meta['error'])) {
				$errors++;
			}
			foreach (isset($meta['tools']) && is_array($meta['tools']) ? $meta['tools'] : array() as $t) {
				if (!empty($t['name'])) {
					$tools[$t['name']] = (isset($tools[$t['name']]) ? $tools[$t['name']] : 0) + 1;
				}
			}
			foreach (isset($meta['products']) && is_array($meta['products']) ? $meta['products'] : array() as $group) {
				foreach (isset($group['items']) && is_array($group['items']) ? $group['items'] : array() as $item) {
					if (!empty($item['product_id'])) {
						$k = (int)$item['product_id'];
						if (!isset($products[$k])) {
							$products[$k] = array('name' => isset($item['name']) ? (string)$item['name'] : ('#' . $k), 'count' => 0);
						}
						$products[$k]['count']++;
					}
				}
			}
		}
		$named = array();
		foreach ($tools as $toolName => $toolCount) {
			$label = $this->language->get('tool_' . $toolName);
			if ($label == 'tool_' . $toolName) {
				$label = $toolName;
			}
			$named[$label] = (isset($named[$label]) ? $named[$label] : 0) + $toolCount;
		}
		$tools = $named;
		arsort($tools);
		arsort($topics);
		uasort($products, function ($a, $b) {
			return $b['count'] - $a['count'];
		});

		$mapList = function ($arr, $n) {
			$out = array();
			foreach (array_slice($arr, 0, $n, true) as $k => $v) {
				$out[] = array('name' => (string)$k, 'count' => (int)$v);
			}

			return $out;
		};

		// budget
		$settings = $this->settings();
		$budget = $sid === null ? (float)$settings->getGlobal('g_budget_month') : (float)$settings->getStore($sid, 's_budget_month');
		$spend = $m->getMonthSpend($sid);
		$ratings = $m->getRatings($sid, $from);
		$outcome = $m->getHandoverOutcome($sid, $from);
		$misses = array();
		foreach ($m->getSearchMisses($sid, $from, 10) as $row) {
			$misses[] = array('name' => (string)$row['query'], 'count' => (int)$row['hits']);
		}

		$this->json(array(
			'range'    => $range,
			'days'     => $days,
			'stores'   => $stores,
			'kpi'      => array(
				'chats'      => $tot['chats'],
				'messages'   => $tot['messages'],
				'cost'       => round($tot['cost'], 4),
				'avg_msgs'   => $tot['chats'] ? round($tot['messages'] / $tot['chats'], 1) : 0,
				'flagged'    => $m->countFlagged($sid, $from),
				'active'     => $m->countActive($sid, 5),
				'handover'   => $m->countHandover($sid, $from),
				'errors'     => $errors,
				'budget'     => $budget,
				'month_spend' => round($spend, 4),
				'rating_up'  => $ratings['up'],
				'rating_down' => $ratings['down'],
				'taken'      => $outcome['taken'],
				'missed'     => $outcome['missed'],
				'requests_open' => $m->countOpenRequests($sid)
			),
			'misses'   => $misses,
			'chats'    => $chats,
			'cost'     => $cost,
			'hourly'   => $m->getHourly($sid, $from),
			'products' => array_slice(array_values($products), 0, 8),
			'tools'    => $mapList($tools, 8),
			'topics'   => $mapList($topics, 6)
		));
	}

	public function live() {
		if (!$this->user->hasPermission('access', 'extension/module/aichat')) {
			return $this->json(array('error' => 'permission'));
		}

		$this->load->model('extension/module/aichat');
		$names = array();
		foreach ($this->storeList() as $st) {
			$names[$st['store_id']] = $st['name'];
		}

		$items = array();
		foreach ($this->model_extension_module_aichat->getLive($this->storeFilter(), 5, 30) as $r) {
			$last = trim(preg_replace('/\s+/u', ' ', (string)$r['last_message']));
			$items[] = array(
				'id'      => (int)$r['conversation_id'],
				'store'   => isset($names[(int)$r['store_id']]) ? $names[(int)$r['store_id']] : (string)$r['store_id'],
				'mode'    => $r['mode'],
				'url'     => $r['last_url'] !== '' ? $r['last_url'] : $r['first_url'],
				'last'    => function_exists('mb_substr') ? mb_substr($last, 0, 140) : substr($last, 0, 140),
				'count'   => (int)$r['message_count'],
				'ago'     => max(0, (int)$r['ago']),
				'flagged' => (int)$r['flagged'],
				'href'    => $this->link('extension/module/aichat/conversation', '&conversation_id=' . (int)$r['conversation_id'])
			);
		}

		$this->json(array('items' => $items, 'time' => date('H:i:s')));
	}

	// ------------------------------------------------------------------
	// conversations
	// ------------------------------------------------------------------

	public function conversations() {
		$this->boot();
		$data = $this->pageData('conversations');
		$this->document->setTitle($this->language->get('text_conversations'));
		$this->document->addStyle('view/stylesheet/aichat.css');
		$this->document->addScript('view/javascript/aichat/conversations.js');

		$this->load->model('extension/module/aichat');
		$this->load->model('localisation/language');
		$m = $this->model_extension_module_aichat;

		$get = $this->request->get;
		$f = $this->convFilter();
		$page = isset($get['page']) ? max(1, (int)$get['page']) : 1;
		$limit = 20;

		$stores = $this->storeList();
		$names = array();
		foreach ($stores as $st) {
			$names[$st['store_id']] = $st['name'];
		}
		$langs = array();
		foreach ($this->model_localisation_language->getLanguages() as $l) {
			$langs[(int)$l['language_id']] = $l['name'];
		}

		$data['conversations'] = array();
		foreach ($m->getConversations($f, ($page - 1) * $limit, $limit) as $r) {
			$first = trim(preg_replace('/\s+/u', ' ', (string)$r['first_message']));
			$data['conversations'][] = array(
				'id'       => (int)$r['conversation_id'],
				'date'     => $r['date_added'],
				'store'    => isset($names[(int)$r['store_id']]) ? $names[(int)$r['store_id']] : $r['store_id'],
				'language' => isset($langs[(int)$r['language_id']]) ? $langs[(int)$r['language_id']] : '',
				'customer' => (int)$r['customer_id'] ? ('#' . (int)$r['customer_id']) : '',
				'mode'     => $r['mode'],
				'order'    => (int)$r['verified_order_id'],
				'messages' => (int)$r['message_count'],
				'cost'     => $this->money($r['cost']),
				'flagged'  => (int)$r['flagged'],
				'is_test'  => (int)$r['is_test'],
				'up'       => (int)$r['rating_up'],
				'down'     => (int)$r['rating_down'],
				'first'    => function_exists('mb_substr') ? mb_substr($first, 0, 110) : substr($first, 0, 110),
				'href'     => $this->url->link('extension/module/aichat/conversation', 'user_token=' . $this->session->data['user_token'] . '&conversation_id=' . (int)$r['conversation_id'], true)
			);
		}

		$total = $m->getTotalConversations($f);
		$url = $this->convQuery();
		$pagination = new Pagination();
		$pagination->total = $total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link('extension/module/aichat/conversations', 'user_token=' . $this->session->data['user_token'] . $url . '&page={page}', true);
		$data['pagination'] = $pagination->render();
		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($total - $limit)) ? $total : ((($page - 1) * $limit) + $limit), $total, ceil($total / $limit));

		$data['filter'] = array(
			'store_id' => $f['store_id'], 'language_id' => $f['language_id'], 'date_from' => $f['date_from'], 'date_to' => $f['date_to'],
			'has_order' => (string)$f['has_order'], 'flagged' => (string)$f['flagged'], 'rating' => (string)$f['rating'], 'test' => (string)$f['test'], 'q' => $f['q']
		);
		$data['export'] = $this->url->link('extension/module/aichat/export', 'user_token=' . $this->session->data['user_token'] . $url, true);
		$data['delete_url'] = $this->link('extension/module/aichat/delete');
		$data['can_modify'] = $this->user->hasPermission('modify', 'extension/module/aichat');
		$data['stores'] = $stores;
		$data['language_list'] = $langs;
		$data['action'] = $this->url->link('extension/module/aichat/conversations', 'user_token=' . $this->session->data['user_token'], true);
		$data['clear'] = $data['action'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat_conversations', $data));
	}

	private function convFilter() {
		$get = $this->request->get;

		return array(
			'store_id'    => isset($get['filter_store_id']) ? $get['filter_store_id'] : '',
			'language_id' => isset($get['filter_language_id']) ? (int)$get['filter_language_id'] : 0,
			'date_from'   => isset($get['filter_date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['filter_date_from']) ? $get['filter_date_from'] : '',
			'date_to'     => isset($get['filter_date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['filter_date_to']) ? $get['filter_date_to'] : '',
			'has_order'   => isset($get['filter_has_order']) ? $get['filter_has_order'] : '',
			'flagged'     => isset($get['filter_flagged']) ? $get['filter_flagged'] : '',
			'rating'      => isset($get['filter_rating']) && in_array($get['filter_rating'], array('1', '-1'), true) ? $get['filter_rating'] : '',
			'test'        => isset($get['filter_test']) && in_array($get['filter_test'], array('1', 'all'), true) ? $get['filter_test'] : '',
			'q'           => isset($get['filter_q']) ? trim(html_entity_decode($get['filter_q'], ENT_QUOTES, 'UTF-8')) : ''
		);
	}

	private function convQuery() {
		$url = '';
		foreach (array('filter_store_id', 'filter_language_id', 'filter_date_from', 'filter_date_to', 'filter_has_order', 'filter_flagged', 'filter_rating', 'filter_test', 'filter_q') as $k) {
			if (isset($this->request->get[$k]) && $this->request->get[$k] !== '') {
				$url .= '&' . $k . '=' . urlencode($this->request->get[$k]);
			}
		}

		return $url;
	}

	private function money($v) {
		$v = (float)$v;

		return '$' . number_format($v, $v != 0 && abs($v) < 0.01 ? 5 : 4, '.', '');
	}

	public function conversation() {
		$this->boot();
		$data = $this->pageData('conversations');
		$this->document->setTitle($this->language->get('text_transcript'));
		$this->document->addStyle('view/stylesheet/aichat.css');
		$this->document->addScript('view/javascript/aichat/kb.js');
		$this->document->addScript('view/javascript/aichat/transcript.js');

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;
		$id = isset($this->request->get['conversation_id']) ? (int)$this->request->get['conversation_id'] : 0;
		$c = $m->getConversation($id);
		$token = $this->session->data['user_token'];

		if (!$c) {
			$this->response->redirect($this->url->link('extension/module/aichat/conversations', 'user_token=' . $token, true));
			return;
		}

		$names = array();
		foreach ($this->storeList() as $st) {
			$names[$st['store_id']] = $st['name'];
		}
		$this->load->model('localisation/language');
		$langName = '';
		foreach ($this->model_localisation_language->getLanguages() as $l) {
			if ((int)$l['language_id'] === (int)$c['language_id']) {
				$langName = $l['name'];
			}
		}

		$messages = array();
		$rows = $m->getMessages($id);
		$kbMap = $m->getKbBySources(array_column($rows, 'message_id'));
		$lastUser = '';
		foreach ($rows as $r) {
			$meta = $r['meta'] ? json_decode($r['meta'], true) : array();
			$meta = is_array($meta) ? $meta : array();

			// uploaded images (upload ids); shown through the permission-checked admin image route
			$images = array();
			foreach (isset($meta['images']) && is_array($meta['images']) ? $meta['images'] : array() as $img) {
				$uid = is_array($img) ? (isset($img['id']) ? (int)$img['id'] : 0) : (int)$img;
				if ($uid > 0) {
					$images[] = array('id' => $uid, 'thumb' => $this->link('extension/module/aichat/image', '&upload_id=' . $uid . '&thumb=1'), 'full' => $this->link('extension/module/aichat/image', '&upload_id=' . $uid));
				}
			}

			$tools = array();
			foreach (isset($meta['tools']) && is_array($meta['tools']) ? $meta['tools'] : array() as $t) {
				$input = isset($t['input']) ? json_encode($t['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
				$tools[] = array('name' => isset($t['name']) ? (string)$t['name'] : '?', 'input' => strlen($input) > 600 ? substr($input, 0, 600) . '…' : $input, 'error' => !empty($t['is_error']));
			}

			$products = array();
			foreach (isset($meta['products']) && is_array($meta['products']) ? $meta['products'] : array() as $group) {
				foreach (isset($group['items']) && is_array($group['items']) ? $group['items'] : array() as $item) {
					$products[] = array('id' => isset($item['product_id']) ? (int)$item['product_id'] : 0, 'name' => isset($item['name']) ? (string)$item['name'] : '', 'label' => isset($group['label']) ? (string)$group['label'] : '');
				}
			}

			$cards = array();
			$rawCards = isset($meta['cards']) && is_array($meta['cards']) ? $meta['cards'] : array();
			foreach ($rawCards as $card) {
				$list = isset($card['items']) && is_array($card['items']) ? $card['items'] : array($card);
				foreach ($list as $item) {
					if (is_array($item)) {
						$cards[] = array(
							'type'  => isset($item['type']) ? (string)$item['type'] : '',
							'title' => isset($item['title']) ? (string)$item['title'] : (isset($item['name']) ? (string)$item['name'] : ''),
							'href'  => isset($item['href']) ? $this->safeUrl($item['href']) : ''
						);
					}
				}
			}

			$page = isset($meta['page']) && is_array($meta['page']) ? $meta['page'] : array();
			$messages[] = array(
				'id'       => (int)$r['message_id'],
				'role'     => in_array($r['role'], array('user', 'assistant', 'staff', 'system'), true) ? $r['role'] : 'system',
				'content'  => (string)$r['content'],
				'date'     => $r['date_added'],
				'page_type' => isset($page['type']) ? (string)$page['type'] : '',
				'page_title' => isset($page['title']) ? (string)$page['title'] : '',
				'page_id'  => isset($page['id']) ? (string)$page['id'] : '',
				'url'      => isset($meta['url']) ? $this->safeUrl($meta['url']) : '',
				'tools'    => $tools,
				'products' => $products,
				'cards'    => $cards,
				'model'    => isset($meta['model']) ? (string)$meta['model'] : '',
				'error'    => !empty($meta['error']) ? json_encode($meta['error'], JSON_UNESCAPED_UNICODE) : '',
				'tokens_in' => (int)$r['input_tokens'],
				'tokens_out' => (int)$r['output_tokens'],
				'cost'     => (float)$r['cost'] > 0 ? $this->money($r['cost']) : '',
				'flagged'  => !empty($meta['flag']),
				'flag_note' => isset($meta['flag']['note']) ? (string)$meta['flag']['note'] : '',
				'images'   => $images,
				'rating'   => (int)$r['rating'],
				'staff_name' => isset($meta['staff_name']) ? (string)$meta['staff_name'] : '',
				'question' => $r['role'] === 'assistant' ? $lastUser : '',
				'kb_id'    => isset($kbMap[(int)$r['message_id']]) ? $kbMap[(int)$r['message_id']] : 0
			);
			if ($r['role'] === 'user') {
				$lastUser = (string)$r['content'];
			}
		}

		$data['conv'] = array(
			'id'       => (int)$c['conversation_id'],
			'public_id' => $c['public_id'],
			'store'    => isset($names[(int)$c['store_id']]) ? $names[(int)$c['store_id']] : $c['store_id'],
			'language' => $langName,
			'customer' => (int)$c['customer_id'] ? ('#' . (int)$c['customer_id']) : $this->str('text_guest'),
			'mode'     => $c['mode'],
			'order'    => (int)$c['verified_order_id'],
			'attempts' => (int)$c['order_attempts'],
			'ip'       => $c['ip'],
			'ua'       => $c['user_agent'],
			'first_url' => $this->safeUrl($c['first_url']),
			'last_url' => $this->safeUrl($c['last_url']),
			'messages' => (int)$c['message_count'],
			'tokens_in' => (int)$c['input_tokens'],
			'tokens_out' => (int)$c['output_tokens'],
			'cost'     => $this->money($c['cost']),
			'flagged'  => (int)$c['flagged'],
			'note'     => (string)$c['note'],
			'is_test'  => isset($c['is_test']) ? (int)$c['is_test'] : 0,
			'staff'    => isset($c['staff_name']) ? (string)$c['staff_name'] : '',
			'store_id' => (int)$c['store_id'],
			'language_id' => (int)$c['language_id'],
			'date'     => $c['date_added'],
			'modified' => $c['date_modified']
		);
		$data['messages'] = $messages;
		$data['flag_url'] = $this->link('extension/module/aichat/flag');
		$data['delete_url'] = $this->link('extension/module/aichat/delete');
		$data['kb_enabled'] = $m->hasTable('kb');
		$data['kb_url'] = $this->url->link('extension/module/aichat/kb', 'user_token=' . $token, true);
		$data['kb_config'] = $this->kbConfig(array('store_id' => (int)$c['store_id'], 'language_id' => (int)$c['language_id']));
		$data['back'] = $this->url->link('extension/module/aichat/conversations', 'user_token=' . $token, true);
		$data['can_modify'] = $this->user->hasPermission('modify', 'extension/module/aichat');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat_conversation', $data));
	}

	/** POST message_id (or conversation_id for the whole chat), flagged 0|1, note */
	public function flag() {
		if ($this->request->server['REQUEST_METHOD'] != 'POST' || !$this->user->hasPermission('modify', 'extension/module/aichat')) {
			return $this->json(array('success' => false, 'error' => $this->str('error_permission')));
		}

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;
		$post = $this->post();
		$flagged = !empty($post['flagged']) ? 1 : 0;
		$note = trim(isset($post['note']) ? (string)$post['note'] : '');
		$note = function_exists('mb_substr') ? mb_substr($note, 0, 1000) : substr($note, 0, 1000);

		if (!empty($post['message_id'])) {
			$ok = $m->setMessageFlag((int)$post['message_id'], $flagged, $note);
		} elseif (!empty($post['conversation_id'])) {
			$m->setConversationFlag((int)$post['conversation_id'], $flagged, $note);
			$ok = true;
		} else {
			$ok = false;
		}

		$this->json(array('success' => (bool)$ok, 'flagged' => $flagged, 'note' => $note));
	}

	// ------------------------------------------------------------------
	// shared helpers (round 3)
	// ------------------------------------------------------------------

	private function storeUrl($storeId) {
		if ((int)$storeId === 0) {
			return (string)HTTP_CATALOG;
		}

		$this->load->model('setting/store');
		$row = $this->model_setting_store->getStore((int)$storeId);

		return $row ? (string)$row['url'] : '';
	}

	private function languageList() {
		$this->load->model('localisation/language');
		$out = array();
		foreach ($this->model_localisation_language->getLanguages() as $l) {
			$out[] = array('language_id' => (int)$l['language_id'], 'name' => $l['name'], 'code' => $l['code']);
		}

		return $out;
	}

	private function paginate($total, $page, $limit, $route, $query) {
		$pagination = new Pagination();
		$pagination->total = $total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link($route, 'user_token=' . $this->session->data['user_token'] . $query . '&page={page}', true);

		return array(
			'html'    => $pagination->render(),
			'results' => sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($total - $limit)) ? $total : ((($page - 1) * $limit) + $limit), $total, ceil($total / $limit))
		);
	}

	private function cut($text, $max) {
		$text = trim(preg_replace('/\s+/u', ' ', (string)$text));

		return function_exists('mb_substr') && mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max - 1, 'UTF-8') . '…' : $text;
	}

	private function needModify() {
		return $this->request->server['REQUEST_METHOD'] == 'POST' && $this->user->hasPermission('modify', 'extension/module/aichat');
	}

	// ------------------------------------------------------------------
	// Telegram buttons on the settings page (ajax)
	// ------------------------------------------------------------------

	private function tgClient() {
		if (!class_exists('\Aichat\Telegram')) {
			return null;
		}

		$post = $this->post();
		$token = isset($post['tg_token']) ? trim($post['tg_token']) : '';

		return new \Aichat\Telegram($this->settings(), $token !== '' ? $token : null);
	}

	/** POST [tg_token]: registers the webhook of the bot (token typed in the form or the saved one). */
	public function tg_webhook() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'message' => $this->str('error_permission')));
		}

		$tg = $this->tgClient();

		if (!$tg) {
			return $this->json(array('success' => false, 'message' => $this->str('error_tg_unavailable')));
		}
		if (!$tg->hasToken()) {
			return $this->json(array('success' => false, 'message' => $this->str('error_tg_no_token')));
		}

		$url = $tg->webhookUrl((string)HTTP_CATALOG);

		if (!$tg->setWebhook($url)) {
			return $this->json(array('success' => false, 'message' => sprintf($this->str('error_tg_webhook'), (string)$tg->lastError())));
		}

		$me = $tg->call('getMe', array());
		$name = is_array($me) && !empty($me['username']) ? ' @' . $me['username'] : '';

		$this->json(array('success' => true, 'message' => sprintf($this->str('text_tg_webhook_ok'), $name)));
	}

	/** POST [tg_token, chat_id]: a test message to the group. */
	public function tg_test() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'message' => $this->str('error_permission')));
		}

		$tg = $this->tgClient();

		if (!$tg) {
			return $this->json(array('success' => false, 'message' => $this->str('error_tg_unavailable')));
		}
		if (!$tg->hasToken()) {
			return $this->json(array('success' => false, 'message' => $this->str('error_tg_no_token')));
		}

		$post = $this->post();
		$chat = isset($post['chat_id']) ? trim($post['chat_id']) : '';
		if ($chat === '') {
			$chat = trim((string)$this->settings()->getGlobal('g_tg_chat_id'));
		}
		if ($chat === '' || !\Aichat\Telegram::validChat($chat)) {
			return $this->json(array('success' => false, 'message' => $this->str('error_tg_chat')));
		}

		if (!$tg->test($chat, $this->str('text_tg_test_message'))) {
			return $this->json(array('success' => false, 'message' => sprintf($this->str('error_tg_test'), (string)$tg->lastError())));
		}

		$this->json(array('success' => true, 'message' => $this->str('text_tg_test_ok')));
	}

	// ------------------------------------------------------------------
	// knowledge base
	// ------------------------------------------------------------------

	/** Config object of the shared "save answer" dialog (kb.js). */
	private function kbConfig(array $ctx) {
		$i18n = array();
		foreach (array('kb_title_add', 'kb_title_edit', 'entry_question', 'entry_answer', 'entry_store', 'entry_language', 'text_all_stores', 'text_all_languages', 'kb_btn_save', 'kb_btn_cancel', 'entry_status',
			'text_kb_on', 'text_kb_off', 'text_kb_reference', 'text_kb_saved', 'error_kb_required', 'text_error', 'help_kb_question', 'help_kb_answer', 'text_kb_open') as $k) {
			$i18n[$k] = $this->str($k);
		}

		return json_encode(array(
			'save'      => $this->link('extension/module/aichat/kb_save'),
			'token'     => $this->session->data['user_token'],
			'stores'    => $this->storeList(),
			'languages' => $this->languageList(),
			'ctx'       => $ctx,
			'i18n'      => $i18n
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
	}

	public function kb() {
		$this->boot();
		$data = $this->pageData('kb');
		$this->document->setTitle($this->language->get('text_kb'));
		$this->document->addStyle('view/stylesheet/aichat.css');
		$this->document->addScript('view/javascript/aichat/kb.js');

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;
		$token = $this->session->data['user_token'];
		$data['kb_ready'] = $m->hasTable('kb');

		$get = $this->request->get;
		$f = array(
			'store_id'    => isset($get['filter_store_id']) ? $get['filter_store_id'] : '',
			'language_id' => isset($get['filter_language_id']) ? $get['filter_language_id'] : '',
			'status'      => isset($get['filter_status']) && in_array($get['filter_status'], array('0', '1'), true) ? $get['filter_status'] : '',
			'q'           => isset($get['filter_q']) ? trim(html_entity_decode($get['filter_q'], ENT_QUOTES, 'UTF-8')) : ''
		);
		$page = isset($get['page']) ? max(1, (int)$get['page']) : 1;
		$limit = 20;

		$names = array(-1 => $this->str('text_all_stores'));
		foreach ($this->storeList() as $st) {
			$names[$st['store_id']] = $st['name'];
		}
		$langs = array(0 => $this->str('text_all_languages'));
		foreach ($this->languageList() as $l) {
			$langs[$l['language_id']] = $l['name'];
		}

		$data['rows'] = array();
		$total = 0;
		if ($data['kb_ready']) {
			$total = $m->getKbTotal($f);
			foreach ($m->getKbList($f, ($page - 1) * $limit, $limit) as $r) {
				$src = 0;
				if ((int)$r['source_message_id']) {
					$msg = $m->getMessage((int)$r['source_message_id']);
					$src = $msg ? (int)$msg['conversation_id'] : 0;
				}
				$data['rows'][] = array(
					'id'       => (int)$r['kb_id'],
					'store'    => isset($names[(int)$r['store_id']]) ? $names[(int)$r['store_id']] : (string)$r['store_id'],
					'language' => isset($langs[(int)$r['language_id']]) ? $langs[(int)$r['language_id']] : (string)$r['language_id'],
					'question' => (string)$r['question'],
					'answer'   => (string)$r['answer'],
					'q_short'  => $this->cut($r['question'], 120),
					'a_short'  => $this->cut($r['answer'], 160),
					'status'   => (int)$r['status'],
					'date'     => $r['date_added'],
					'source'   => $src ? $this->url->link('extension/module/aichat/conversation', 'user_token=' . $token . '&conversation_id=' . $src, true) : '',
					'json'     => json_encode(array('kb_id' => (int)$r['kb_id'], 'store_id' => (int)$r['store_id'], 'language_id' => (int)$r['language_id'], 'question' => (string)$r['question'], 'answer' => (string)$r['answer'], 'status' => (int)$r['status']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
				);
			}
		}

		$query = '';
		foreach (array('filter_store_id', 'filter_language_id', 'filter_status', 'filter_q') as $k) {
			if (isset($get[$k]) && $get[$k] !== '') {
				$query .= '&' . $k . '=' . urlencode($get[$k]);
			}
		}
		$pg = $this->paginate($total, $page, $limit, 'extension/module/aichat/kb', $query);
		$data['pagination'] = $pg['html'];
		$data['results'] = $pg['results'];
		$data['filter'] = array('store_id' => (string)$f['store_id'], 'language_id' => (string)$f['language_id'], 'status' => (string)$f['status'], 'q' => $f['q']);
		$data['stores'] = $this->storeList();
		$data['language_list'] = $this->languageList();
		$data['clear'] = $this->url->link('extension/module/aichat/kb', 'user_token=' . $token, true);
		$data['can_modify'] = $this->user->hasPermission('modify', 'extension/module/aichat');
		$data['kb_config'] = $this->kbConfig(array());
		$data['toggle_url'] = $this->link('extension/module/aichat/kb_toggle');
		$data['delete_url'] = $this->link('extension/module/aichat/kb_delete');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat_kb', $data));
	}

	/** POST kb_id (0 = new), store_id (-1 = all), language_id (0 = all), question, answer, status, source_message_id */
	public function kb_save() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'error' => $this->str('error_permission')));
		}

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;

		if (!$m->hasTable('kb')) {
			return $this->json(array('success' => false, 'error' => $this->str('text_upgrade_pending')));
		}

		$post = $this->post();
		$question = trim(isset($post['question']) ? (string)$post['question'] : '');
		$answer = trim(isset($post['answer']) ? (string)$post['answer'] : '');
		$question = function_exists('mb_substr') ? mb_substr($question, 0, 1000) : substr($question, 0, 1000);
		$answer = function_exists('mb_substr') ? mb_substr($answer, 0, 4000) : substr($answer, 0, 4000);

		if ($question === '' || $answer === '') {
			return $this->json(array('success' => false, 'error' => $this->str('error_kb_required')));
		}

		$storeId = isset($post['store_id']) ? (int)$post['store_id'] : -1;
		$valid = array(-1);
		foreach ($this->storeList() as $st) {
			$valid[] = $st['store_id'];
		}
		$langIds = array(0);
		foreach ($this->languageList() as $l) {
			$langIds[] = $l['language_id'];
		}
		$languageId = isset($post['language_id']) ? (int)$post['language_id'] : 0;

		if (!in_array($storeId, $valid, true) || !in_array($languageId, $langIds, true)) {
			return $this->json(array('success' => false, 'error' => $this->str('error_kb_required')));
		}

		$id = isset($post['kb_id']) ? (int)$post['kb_id'] : 0;
		if ($id && !$m->getKb($id)) {
			return $this->json(array('success' => false, 'error' => $this->str('error_kb_required')));
		}

		$source = 0;
		if (!$id && !empty($post['source_message_id'])) {
			$msg = $m->getMessage((int)$post['source_message_id']);
			$source = $msg ? (int)$msg['message_id'] : 0;
		}

		$id = $m->saveKb($id, array(
			'store_id' => $storeId, 'language_id' => $languageId, 'question' => $question, 'answer' => $answer,
			'status' => isset($post['status']) ? (int)$post['status'] : 1, 'source_message_id' => $source
		));

		$this->json(array('success' => true, 'kb_id' => $id, 'link' => $this->link('extension/module/aichat/kb')));
	}

	public function kb_toggle() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'error' => $this->str('error_permission')));
		}

		$this->load->model('extension/module/aichat');
		$post = $this->post();
		$id = isset($post['kb_id']) ? (int)$post['kb_id'] : 0;

		if (!$id || !$this->model_extension_module_aichat->getKb($id)) {
			return $this->json(array('success' => false, 'error' => 'not found'));
		}

		$this->model_extension_module_aichat->setKbStatus($id, !empty($post['status']));
		$this->json(array('success' => true));
	}

	public function kb_delete() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'error' => $this->str('error_permission')));
		}

		$this->load->model('extension/module/aichat');
		$post = $this->post();
		$id = isset($post['kb_id']) ? (int)$post['kb_id'] : 0;

		if ($id) {
			$this->model_extension_module_aichat->deleteKb($id);
		}

		$this->json(array('success' => true));
	}

	// ------------------------------------------------------------------
	// requests (contacts left, stock alerts, returns)
	// ------------------------------------------------------------------

	public function requests() {
		$this->boot();
		$data = $this->pageData('requests');
		$this->document->setTitle($this->language->get('text_requests'));
		$this->document->addStyle('view/stylesheet/aichat.css');
		$this->document->addScript('view/javascript/aichat/requests.js');

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;
		$token = $this->session->data['user_token'];
		$data['ready'] = $m->hasTable('request');

		$get = $this->request->get;
		$f = array(
			'type'     => isset($get['filter_type']) ? $get['filter_type'] : '',
			'status'   => isset($get['filter_status']) ? $get['filter_status'] : '',
			'store_id' => isset($get['filter_store_id']) ? $get['filter_store_id'] : ''
		);
		$page = isset($get['page']) ? max(1, (int)$get['page']) : 1;
		$limit = 20;

		$names = array();
		foreach ($this->storeList() as $st) {
			$names[$st['store_id']] = $st['name'];
		}

		$data['rows'] = array();
		$total = 0;
		if ($data['ready']) {
			$total = $m->getRequestsTotal($f);
			foreach ($m->getRequests($f, ($page - 1) * $limit, $limit) as $r) {
				$data['rows'][] = $this->requestRow($r, $names, $m, $token);
			}
		}

		$query = '';
		foreach (array('filter_type', 'filter_status', 'filter_store_id') as $k) {
			if (isset($get[$k]) && $get[$k] !== '') {
				$query .= '&' . $k . '=' . urlencode($get[$k]);
			}
		}
		$pg = $this->paginate($total, $page, $limit, 'extension/module/aichat/requests', $query);
		$data['pagination'] = $pg['html'];
		$data['results'] = $pg['results'];
		$data['filter'] = array('type' => (string)$f['type'], 'status' => (string)$f['status'], 'store_id' => (string)$f['store_id']);
		$data['stores'] = $this->storeList();
		$data['clear'] = $this->url->link('extension/module/aichat/requests', 'user_token=' . $token, true);
		$data['can_modify'] = $this->user->hasPermission('modify', 'extension/module/aichat');
		$data['status_url'] = $this->link('extension/module/aichat/request_status');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat_requests', $data));
	}

	private function requestRow($r, $names, $m, $token) {
		$d = $r['data'] ? json_decode($r['data'], true) : array();
		$d = is_array($d) ? $d : array();
		$type = in_array($r['type'], array('contact', 'stock', 'return'), true) ? $r['type'] : 'contact';

		$productId = (int)$r['product_id'];
		$productName = isset($d['product']) && is_string($d['product']) ? $d['product'] : '';
		if ($productId && $productName === '') {
			$productName = $m->getProductName($productId, isset($d['language_id']) ? (int)$d['language_id'] : (int)$this->config->get('config_language_id'));
		}

		if ($type === 'contact') {
			$summary = isset($d['note']) ? (string)$d['note'] : '';
		} elseif ($type === 'stock') {
			$summary = $productName;
		} else {
			$summary = trim($productName . (isset($d['reason']) && $d['reason'] !== '' ? ' - ' . $d['reason'] : ''));
		}

		// every scalar of the stored data as label/value, images as thumbnails
		$fields = array();
		$images = array();
		foreach ($d as $k => $v) {
			if (is_array($v) && in_array($k, array('images', 'image_ids'), true)) {
				foreach ($v as $img) {
					$uid = is_array($img) ? (isset($img['id']) ? (int)$img['id'] : 0) : (int)$img;
					if ($uid > 0) {
						$images[] = array('thumb' => $this->link('extension/module/aichat/image', '&upload_id=' . $uid . '&thumb=1'), 'full' => $this->link('extension/module/aichat/image', '&upload_id=' . $uid));
					}
				}
			} elseif (is_scalar($v) && (string)$v !== '' && !in_array($k, array('language_id'), true)) {
				$label = $this->str('rq_field_' . $k);
				$fields[] = array('label' => $label === 'rq_field_' . $k ? $k : $label, 'value' => (string)$v, 'url' => in_array($k, array('page'), true) ? $this->safeUrl($v) : '');
			}
		}

		$cid = (int)$r['conversation_id'];

		return array(
			'id'       => (int)$r['request_id'],
			'type'     => $type,
			'status'   => $r['status'] === 'done' ? 'done' : 'new',
			'store'    => isset($names[(int)$r['store_id']]) ? $names[(int)$r['store_id']] : (string)$r['store_id'],
			'date'     => $r['date_added'],
			'email'    => (string)$r['email'],
			'phone'    => (string)$r['phone'],
			'summary'  => $this->cut($summary, 130),
			'order_id' => (int)$r['order_id'],
			'order_url' => (int)$r['order_id'] ? $this->url->link('sale/order/info', 'user_token=' . $token . '&order_id=' . (int)$r['order_id'], true) : '',
			'product_id' => $productId,
			'product'  => $productName,
			'product_url' => $productId ? $this->url->link('catalog/product/edit', 'user_token=' . $token . '&product_id=' . $productId, true) : '',
			'conversation' => ($cid && $m->conversationExists($cid)) ? $cid : 0,
			'conversation_url' => $cid ? $this->url->link('extension/module/aichat/conversation', 'user_token=' . $token . '&conversation_id=' . $cid, true) : '',
			'conversation_gone' => $cid && !$m->conversationExists($cid),
			'fields'   => $fields,
			'images'   => $images
		);
	}

	/** POST request_id, status new|done */
	public function request_status() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'error' => $this->str('error_permission')));
		}

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;
		$post = $this->post();
		$id = isset($post['request_id']) ? (int)$post['request_id'] : 0;

		if (!$m->hasTable('request') || !$id || !$m->getRequest($id)) {
			return $this->json(array('success' => false, 'error' => 'not found'));
		}

		$status = isset($post['status']) && $post['status'] === 'new' ? 'new' : 'done';
		$m->setRequestStatus($id, $status);

		$this->json(array('success' => true, 'status' => $status, 'open' => $m->countOpenRequests()));
	}

	// ------------------------------------------------------------------
	// test chat (playground)
	// ------------------------------------------------------------------

	public function testchat() {
		$this->boot();
		$data = $this->pageData('testchat');
		$this->document->setTitle($this->language->get('text_testchat'));
		$this->document->addStyle('view/stylesheet/aichat.css');
		$this->document->addScript('view/javascript/aichat/testchat.js');

		$this->load->model('extension/module/aichat');
		$settings = $this->settings();
		$data['ready'] = $this->model_extension_module_aichat->hasTable('preview') && class_exists('\Aichat\Guard') && method_exists('\Aichat\Guard', 'makePreviewToken');
		$data['has_key'] = $settings->hasApiKey();

		$stores = array();
		foreach ($this->storeList() as $st) {
			$sid = $st['store_id'];
			$conf = $settings->getStore($sid);
			$stores[] = array(
				'store_id'     => $sid,
				'name'         => $st['name'],
				'url'          => $this->storeUrl($sid),
				'status'       => !empty($conf['s_status']),
				'instructions' => isset($conf['s_instructions']) ? (string)$conf['s_instructions'] : ''
			);
		}

		$data['stores'] = $stores;
		$data['languages'] = $this->languageList();
		$data['global_instructions'] = (string)$settings->getGlobal('g_instructions');
		$data['config_json'] = json_encode(array(
			'send'   => $this->link('extension/module/aichat/testchat_send'),
			'token'  => $this->session->data['user_token'],
			'stores' => $stores,
			'transcript' => str_replace('&amp;', '&', $this->url->link('extension/module/aichat/conversation', 'user_token=' . $this->session->data['user_token'] . '&conversation_id=__ID__', true)),
			'i18n'   => array(
				'you' => $this->str('text_you'), 'bot' => $this->str('text_role_bot'), 'thinking' => $this->str('text_thinking'), 'error' => $this->str('text_error'),
				'tools' => $this->str('text_tool_calls'), 'cost' => $this->str('text_cost'), 'conv_cost' => $this->str('text_conv_cost'), 'tokens_in' => $this->str('text_in'), 'tokens_out' => $this->str('text_out'),
				'suggestion' => $this->str('text_suggestion'), 'open_transcript' => $this->str('text_open_transcript'), 'empty' => $this->str('text_testchat_empty'), 'mode' => $this->str('text_status'),
				'handover' => $this->str('text_mode_human'), 'waiting' => $this->str('text_mode_waiting'), 'no_tools' => $this->str('text_no_tools'), 'reset_done' => $this->str('text_reset_done')
			)
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat_testchat', $data));
	}

	/**
	 * POST store_id, url, message, preview_id?, language_code?, g_instructions, s_instructions.
	 * Stores the draft in aichat_preview and relays the message server-to-server to the store's
	 * catalog `send` (stream 0, preview token). The model's answer, cards, tools and cost come back.
	 */
	public function testchat_send() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'error' => $this->str('error_permission')));
		}

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;

		if (!$m->hasTable('preview') || !class_exists('\Aichat\Guard') || !method_exists('\Aichat\Guard', 'makePreviewToken')) {
			return $this->json(array('success' => false, 'error' => $this->str('text_upgrade_pending')));
		}

		@set_time_limit(180);
		$post = $this->post();
		$storeId = isset($post['store_id']) ? (int)$post['store_id'] : 0;
		$known = false;
		foreach ($this->storeList() as $st) {
			$known = $known || $st['store_id'] === $storeId;
		}
		$message = trim(isset($post['message']) ? (string)$post['message'] : '');

		if (!$known || $message === '') {
			return $this->json(array('success' => false, 'error' => $this->str('text_error')));
		}

		$base = $this->storeUrl($storeId);
		$url = trim(isset($post['url']) ? (string)$post['url'] : '');
		$url = $this->safeUrl($url) !== '' ? $url : $base;

		$draft = array(
			'g_instructions' => isset($post['g_instructions']) ? (string)$post['g_instructions'] : '',
			's_instructions' => isset($post['s_instructions']) ? (string)$post['s_instructions'] : ''
		);
		$previewId = $m->savePreview(isset($post['preview_id']) ? (int)$post['preview_id'] : 0, $storeId, $url, $draft);
		$token = \Aichat\Guard::makePreviewToken($previewId, $this->registry);

		$lang = '';
		foreach ($this->languageList() as $l) {
			if (isset($post['language_code']) && $post['language_code'] === $l['code']) {
				$lang = $l['code'];
			}
		}

		$endpoint = rtrim($base, '/') . '/index.php?route=extension/module/aichat/send';
		$ch = curl_init($endpoint);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(array('preview' => $token, 'message' => $message, 'url' => $url, 'stream' => 0)),
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_TIMEOUT        => 150,
			CURLOPT_USERAGENT      => 'AIChat-Admin-Test',
			CURLOPT_COOKIE         => $lang !== '' ? 'language=' . $lang : ''
		));
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		$json = is_string($body) ? json_decode($body, true) : null;

		if (!is_array($json)) {
			return $this->json(array('success' => false, 'preview_id' => $previewId, 'error' => sprintf($this->str('error_testchat_relay'), $status, $curlError)));
		}
		if (!empty($json['error'])) {
			$msg = is_array($json['error']) ? (isset($json['error']['message']) ? (string)$json['error']['message'] : '') : (string)$json['error'];
			$code = is_array($json['error']) && isset($json['error']['code']) ? ' [' . $json['error']['code'] . ']' : '';

			return $this->json(array('success' => false, 'preview_id' => $previewId, 'error' => $msg . $code));
		}

		// tools and cost of this reply from the stored assistant message
		$tools = array();
		$cost = '';
		$tokens = array('in' => 0, 'out' => 0);
		$model = '';
		$row = !empty($json['message_id']) ? $m->getMessage((int)$json['message_id']) : array();
		if ($row) {
			$meta = $row['meta'] ? json_decode($row['meta'], true) : array();
			$meta = is_array($meta) ? $meta : array();
			foreach (isset($meta['tools']) && is_array($meta['tools']) ? $meta['tools'] : array() as $t) {
				$input = isset($t['input']) ? json_encode($t['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
				$tools[] = array('name' => isset($t['name']) ? (string)$t['name'] : '?', 'input' => $this->cut($input, 400), 'error' => !empty($t['is_error']));
			}
			$cost = $this->money($row['cost']);
			$tokens = array('in' => (int)$row['input_tokens'], 'out' => (int)$row['output_tokens']);
			$model = isset($meta['model']) ? (string)$meta['model'] : '';
		}

		$guard = new \Aichat\Guard($this->registry, $this->settings());
		$conv = $m->getConversationByPublicId($guard->previewPublicId($previewId, $m->getPreviewDate($previewId)));

		$products = array();
		foreach (isset($json['products']) && is_array($json['products']) ? $json['products'] : array() as $p) {
			if (is_array($p)) {
				$products[] = array(
					'name'    => isset($p['name']) ? (string)$p['name'] : '',
					'href'    => isset($p['href']) ? $this->safeUrl($p['href']) : '',
					'image'   => isset($p['image']) ? $this->safeUrl($p['image']) : '',
					'price'   => isset($p['price']) ? (string)$p['price'] : '',
					'special' => !empty($p['special']) ? (string)$p['special'] : '',
					'label'   => isset($p['label']) ? (string)$p['label'] : ''
				);
			}
		}
		$cards = array();
		foreach (isset($json['cards']) && is_array($json['cards']) ? $json['cards'] : array() as $c) {
			if (is_array($c)) {
				$cards[] = array(
					'type'     => isset($c['type']) ? (string)$c['type'] : '',
					'title'    => isset($c['title']) ? (string)$c['title'] : '',
					'subtitle' => isset($c['subtitle']) ? (string)$c['subtitle'] : '',
					'href'     => isset($c['href']) ? $this->safeUrl($c['href']) : '',
					'image'    => isset($c['image']) ? $this->safeUrl($c['image']) : ''
				);
			}
		}

		$this->json(array(
			'success'    => true,
			'preview_id' => $previewId,
			'reply'      => isset($json['reply']) ? (string)$json['reply'] : '',
			'products'   => $products,
			'cards'      => $cards,
			'mode'       => isset($json['mode']) ? (string)$json['mode'] : 'bot',
			'tools'      => $tools,
			'cost'       => $cost,
			'tokens_in'  => $tokens['in'],
			'tokens_out' => $tokens['out'],
			'model'      => $model,
			'conv_cost'  => $conv ? $this->money($conv['cost']) : '',
			'conversation_id' => $conv ? (int)$conv['conversation_id'] : 0
		));
	}

	// ------------------------------------------------------------------
	// conversations: CSV export, delete, uploaded images
	// ------------------------------------------------------------------

	/** CSV of the filtered list. UTF-8 with BOM and ";" (Excel in a Bulgarian locale), spreadsheet formulas neutralised. */
	public function export() {
		if (!$this->user->hasPermission('access', 'extension/module/aichat')) {
			$this->response->redirect($this->url->link('error/permission', 'user_token=' . $this->session->data['user_token'], true));
			return;
		}

		$this->boot();
		$this->load->language('extension/module/aichat');
		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;

		$names = array();
		foreach ($this->storeList() as $st) {
			$names[$st['store_id']] = $st['name'];
		}
		$langs = array();
		foreach ($this->languageList() as $l) {
			$langs[$l['language_id']] = $l['name'];
		}

		$safe = function ($v) {
			$v = (string)$v;

			return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
		};

		$fh = fopen('php://temp', 'r+');
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array('ID', $this->str('text_date'), $this->str('text_store'), $this->str('text_language'), $this->str('text_customer'), $this->str('text_status'), $this->str('text_order'),
			$this->str('text_messages'), $this->str('text_cost') . ' (USD)', $this->str('text_flagged'), $this->str('text_rating_up'), $this->str('text_rating_down'), $this->str('text_test_chat'), $this->str('text_first_message'), $this->str('text_first_page')), ';');

		foreach ($m->getConversationsForExport($this->convFilter(), 20000) as $r) {
			fputcsv($fh, array(
				(int)$r['conversation_id'],
				$r['date_added'],
				$safe(isset($names[(int)$r['store_id']]) ? $names[(int)$r['store_id']] : $r['store_id']),
				$safe(isset($langs[(int)$r['language_id']]) ? $langs[(int)$r['language_id']] : ''),
				(int)$r['customer_id'] ? (int)$r['customer_id'] : '',
				$r['mode'],
				(int)$r['verified_order_id'] ? (int)$r['verified_order_id'] : '',
				(int)$r['message_count'],
				number_format((float)$r['cost'], 6, '.', ''),
				(int)$r['flagged'] ? 1 : 0,
				(int)$r['rating_up'],
				(int)$r['rating_down'],
				(int)$r['is_test'] ? 1 : 0,
				$safe($this->cut($r['first_message'], 500)),
				$safe($r['first_url'])
			), ';');
		}

		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);

		$this->response->addHeader('Content-Type: text/csv; charset=utf-8');
		$this->response->addHeader('Content-Disposition: attachment; filename="aichat-conversations-' . date('Ymd-His') . '.csv"');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput($csv);
	}

	/** POST conversation_id: removes the conversation, its messages and its upload files. */
	public function delete() {
		if (!$this->needModify()) {
			return $this->json(array('success' => false, 'error' => $this->str('error_permission')));
		}

		$this->load->model('extension/module/aichat');
		$m = $this->model_extension_module_aichat;
		$post = $this->post();
		$id = isset($post['conversation_id']) ? (int)$post['conversation_id'] : 0;

		if (!$id || !$m->getConversation($id)) {
			return $this->json(array('success' => false, 'error' => 'not found'));
		}

		if ($m->getUploadIds($id)) {
			if (class_exists('\Aichat\Uploads')) {
				$uploads = new \Aichat\Uploads($this->registry);
				$uploads->deleteForConversation($id);
			} else {
				$this->db->query("DELETE FROM `" . DB_PREFIX . "aichat_upload` WHERE `conversation_id` = '" . $id . "'");
			}
		}

		$m->deleteConversation($id);
		$this->json(array('success' => true, 'redirect' => $this->link('extension/module/aichat/conversations')));
	}

	/**
	 * GET upload_id [thumb=1]. The file is looked up by id in aichat_upload (never a path from the
	 * request) and read from DIR_STORAGE/aichat/uploads; needs the module's access permission.
	 */
	public function image() {
		if (!$this->user->hasPermission('access', 'extension/module/aichat') || !class_exists('\Aichat\Uploads')) {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
			$this->response->setOutput('');
			return;
		}

		$uploads = new \Aichat\Uploads($this->registry);
		$row = $uploads->getAny(isset($this->request->get['upload_id']) ? (int)$this->request->get['upload_id'] : 0);
		$file = '';

		if ($row) {
			$file = \Aichat\Uploads::path($row, !empty($this->request->get['thumb']));
			if ($file === '') {
				$file = \Aichat\Uploads::path($row, false);
			}
		}

		if ($file === '') {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
			$this->response->setOutput('');
			return;
		}

		$this->response->setCompression(0);
		$this->response->addHeader('Content-Type: image/jpeg');
		$this->response->addHeader('Content-Disposition: inline; filename="upload.jpg"');
		$this->response->addHeader('X-Content-Type-Options: nosniff');
		$this->response->addHeader('Cache-Control: private, max-age=3600');
		$this->response->setOutput((string)file_get_contents($file));
	}
}
