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
			'settings'      => array('extension/module/aichat', 'fa-cog', 'menu_settings')
		);
		$html = '<ul class="nav nav-pills aichat-subnav">';
		foreach ($items as $key => $item) {
			$html .= '<li' . ($key === $active ? ' class="active"' : '') . '><a href="' . $this->url->link($item[0], 'user_token=' . $token, true) . '"><i class="fa ' . $item[1] . '"></i> ' . htmlspecialchars($this->str($item[2]), ENT_QUOTES, 'UTF-8') . '</a></li>';
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
				's_pos_desktop_x' => array(0, 1000), 's_pos_desktop_y' => array(0, 1000), 's_pos_mobile_x' => array(0, 500), 's_pos_mobile_y' => array(0, 1000));
			foreach ($ranges as $k => $r) {
				if (!$this->isInt(isset($s[$k]) ? $s[$k] : '', $r[0], $r[1])) {
					$this->error[$p . $k] = sprintf($this->language->get('error_range'), $r[0], $r[1]);
				}
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
				'month_spend' => round($spend, 4)
			),
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

		$this->load->model('extension/module/aichat');
		$this->load->model('localisation/language');
		$m = $this->model_extension_module_aichat;

		$get = $this->request->get;
		$f = array(
			'store_id'    => isset($get['filter_store_id']) ? $get['filter_store_id'] : '',
			'language_id' => isset($get['filter_language_id']) ? (int)$get['filter_language_id'] : 0,
			'date_from'   => isset($get['filter_date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['filter_date_from']) ? $get['filter_date_from'] : '',
			'date_to'     => isset($get['filter_date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['filter_date_to']) ? $get['filter_date_to'] : '',
			'has_order'   => isset($get['filter_has_order']) ? $get['filter_has_order'] : '',
			'flagged'     => isset($get['filter_flagged']) ? $get['filter_flagged'] : '',
			'q'           => isset($get['filter_q']) ? trim(html_entity_decode($get['filter_q'], ENT_QUOTES, 'UTF-8')) : ''
		);
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
				'first'    => function_exists('mb_substr') ? mb_substr($first, 0, 110) : substr($first, 0, 110),
				'href'     => $this->url->link('extension/module/aichat/conversation', 'user_token=' . $this->session->data['user_token'] . '&conversation_id=' . (int)$r['conversation_id'], true)
			);
		}

		$total = $m->getTotalConversations($f);
		$url = '';
		foreach (array('filter_store_id', 'filter_language_id', 'filter_date_from', 'filter_date_to', 'filter_has_order', 'filter_flagged', 'filter_q') as $k) {
			if (isset($get[$k]) && $get[$k] !== '') {
				$url .= '&' . $k . '=' . urlencode($get[$k]);
			}
		}
		$pagination = new Pagination();
		$pagination->total = $total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link('extension/module/aichat/conversations', 'user_token=' . $this->session->data['user_token'] . $url . '&page={page}', true);
		$data['pagination'] = $pagination->render();
		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($total - $limit)) ? $total : ((($page - 1) * $limit) + $limit), $total, ceil($total / $limit));

		$data['filter'] = array(
			'store_id' => $f['store_id'], 'language_id' => $f['language_id'], 'date_from' => $f['date_from'], 'date_to' => $f['date_to'],
			'has_order' => (string)$f['has_order'], 'flagged' => (string)$f['flagged'], 'q' => $f['q']
		);
		$data['stores'] = $stores;
		$data['language_list'] = $langs;
		$data['action'] = $this->url->link('extension/module/aichat/conversations', 'user_token=' . $this->session->data['user_token'], true);
		$data['clear'] = $data['action'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/aichat_conversations', $data));
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
		foreach ($m->getMessages($id) as $r) {
			$meta = $r['meta'] ? json_decode($r['meta'], true) : array();
			$meta = is_array($meta) ? $meta : array();

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
				'flag_note' => isset($meta['flag']['note']) ? (string)$meta['flag']['note'] : ''
			);
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
			'date'     => $c['date_added'],
			'modified' => $c['date_modified']
		);
		$data['messages'] = $messages;
		$data['flag_url'] = $this->link('extension/module/aichat/flag');
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
}
