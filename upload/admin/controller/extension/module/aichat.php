<?php
class ControllerExtensionModuleAichat extends Controller {
	private $error = array();
	private $days = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');

	private function settings() {
		return new \Aichat\Settings($this->registry);
	}

	public function index() {
		$this->load->language('extension/module/aichat');
		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/store');
		$this->load->model('localisation/language');

		$settings = $this->settings();
		$languages = $this->model_localisation_language->getLanguages();

		$stores = array(array('store_id' => 0, 'name' => $this->config->get('config_name') . ' ' . $this->language->get('text_default')));
		foreach ($this->model_setting_store->getStores() as $store) {
			$stores[] = array('store_id' => (int)$store['store_id'], 'name' => $store['name']);
		}

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate($stores, $languages)) {
			$post = $this->request->post;

			$g = $this->cleanGlobal(isset($post['g']) ? $post['g'] : array());
			$settings->saveGlobal($g);

			// write-only API key: empty keeps the existing one
			$key = isset($post['api_key']) ? trim($post['api_key']) : '';
			if ($key !== '') {
				$settings->setApiKey($key);
			}

			foreach ($stores as $store) {
				$sid = $store['store_id'];
				if (isset($post['s'][$sid])) {
					$settings->saveStore($sid, $this->cleanStore($post['s'][$sid], $languages));
				}
			}

			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('extension/module/aichat', 'user_token=' . $this->session->data['user_token'], true));
		}

		$data = array();
		foreach (array('heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'text_default', 'text_none', 'text_moderate', 'text_lots',
			'text_key_set', 'text_key_not_set', 'text_key_help', 'text_secret_help', 'text_price_help', 'text_budget_help', 'text_contacts', 'text_greeting',
			'text_work_hours', 'text_work_hours_help', 'text_from', 'text_to', 'text_closed_hint', 'text_limits',
			'tab_general', 'button_save', 'button_cancel',
			'entry_api_key', 'entry_model', 'entry_instructions', 'entry_price_in', 'entry_price_out', 'entry_budget_month',
			'entry_turnstile_site', 'entry_turnstile_secret', 'entry_retention_months', 'entry_image_retention_days',
			'entry_status', 'entry_bot_name', 'entry_avatar', 'entry_store_instructions', 'entry_emoji', 'entry_order_link_button',
			'entry_max_chats_day', 'entry_max_msgs_chat', 'entry_max_msgs_minute', 'entry_max_len',
			'entry_phone', 'entry_email', 'entry_viber', 'entry_whatsapp', 'entry_note',
			'text_day_mon', 'text_day_tue', 'text_day_wed', 'text_day_thu', 'text_day_fri', 'text_day_sat', 'text_day_sun',
			'text_global_note', 'text_store_note', 'text_avatar_default') as $k) {
			$data[$k] = $this->language->get($k);
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['errors'] = $this->error;

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		$token = $this->session->data['user_token'];
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/aichat', 'user_token=' . $token, true))
		);
		$data['action'] = $this->url->link('extension/module/aichat', 'user_token=' . $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module', true);

		// values: posted data wins after a failed validation, otherwise stored settings
		$posted = $this->request->server['REQUEST_METHOD'] == 'POST';
		$g = $settings->getGlobal();
		if ($posted && isset($this->request->post['g'])) {
			$g = array_merge($g, $this->request->post['g']);
		}
		$data['g'] = $g;
		$data['has_key'] = $settings->hasApiKey();

		$data['languages'] = array();
		foreach ($languages as $language) {
			$data['languages'][] = array('language_id' => (int)$language['language_id'], 'name' => $language['name'], 'code' => $language['code']);
		}
		$data['days'] = $this->days;

		$data['stores'] = array();
		foreach ($stores as $store) {
			$sid = $store['store_id'];
			$s = $settings->getStore($sid);
			if ($posted && isset($this->request->post['s'][$sid])) {
				$s = array_merge(is_array($s) ? $s : array(), $this->request->post['s'][$sid]);
			}
			$s = is_array($s) ? $s : array();
			$s['s_greeting'] = isset($s['s_greeting']) && is_array($s['s_greeting']) ? $s['s_greeting'] : array();
			$s['s_contacts'] = isset($s['s_contacts']) && is_array($s['s_contacts']) ? $s['s_contacts'] : array();
			$s['s_work_hours'] = isset($s['s_work_hours']) && is_array($s['s_work_hours']) ? $s['s_work_hours'] : array();
			$data['stores'][] = array('store_id' => $sid, 'name' => $store['name'], 's' => $s);
		}

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
		$out['g_turnstile_site'] = isset($in['g_turnstile_site']) ? trim($in['g_turnstile_site']) : '';
		$out['g_turnstile_secret'] = isset($in['g_turnstile_secret']) ? trim($in['g_turnstile_secret']) : '';
		$out['g_retention_months'] = (int)(isset($in['g_retention_months']) ? $in['g_retention_months'] : 12);
		$out['g_image_retention_days'] = (int)(isset($in['g_image_retention_days']) ? $in['g_image_retention_days'] : 30);
		return $out;
	}

	private function cleanStore($in, $languages) {
		$out = array();
		$out['s_status'] = empty($in['s_status']) ? 0 : 1;
		$out['s_bot_name'] = isset($in['s_bot_name']) ? trim($in['s_bot_name']) : '';
		$out['s_avatar'] = isset($in['s_avatar']) ? trim($in['s_avatar']) : 'default';
		$out['s_greeting'] = array();
		foreach ($languages as $language) {
			$lid = (int)$language['language_id'];
			$out['s_greeting'][$lid] = isset($in['s_greeting'][$lid]) ? trim($in['s_greeting'][$lid]) : '';
		}
		$out['s_instructions'] = isset($in['s_instructions']) ? trim($in['s_instructions']) : '';
		$out['s_contacts'] = array();
		foreach (array('phone', 'email', 'viber', 'whatsapp', 'note') as $c) {
			$out['s_contacts'][$c] = isset($in['s_contacts'][$c]) ? trim($in['s_contacts'][$c]) : '';
		}
		$out['s_work_hours'] = array();
		foreach ($this->days as $d) {
			$from = isset($in['s_work_hours'][$d]['from']) ? trim($in['s_work_hours'][$d]['from']) : '';
			$to = isset($in['s_work_hours'][$d]['to']) ? trim($in['s_work_hours'][$d]['to']) : '';
			$out['s_work_hours'][$d] = array('from' => $from, 'to' => $to);
		}
		$out['s_emoji'] = min(2, max(0, (int)(isset($in['s_emoji']) ? $in['s_emoji'] : 1)));
		$out['s_order_link_button'] = empty($in['s_order_link_button']) ? 0 : 1;
		$out['s_budget_month'] = (float)$this->num(isset($in['s_budget_month']) ? $in['s_budget_month'] : 0);
		$out['s_max_chats_day'] = (int)(isset($in['s_max_chats_day']) ? $in['s_max_chats_day'] : 0);
		$out['s_max_msgs_chat'] = (int)(isset($in['s_max_msgs_chat']) ? $in['s_max_msgs_chat'] : 40);
		$out['s_max_msgs_minute'] = (int)(isset($in['s_max_msgs_minute']) ? $in['s_max_msgs_minute'] : 6);
		$out['s_max_len'] = (int)(isset($in['s_max_len']) ? $in['s_max_len'] : 1000);
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

	protected function validate($stores, $languages) {
		if (!$this->user->hasPermission('modify', 'extension/module/aichat')) {
			$this->error['warning'] = $this->language->get('error_permission');
			return false;
		}

		$post = $this->request->post;
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
			foreach (array('s_max_chats_day' => array(0, 100000), 's_max_msgs_chat' => array(1, 1000), 's_max_msgs_minute' => array(1, 100), 's_max_len' => array(50, 4000)) as $k => $r) {
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
		}
	}

	public function uninstall() {
		if ($this->user->hasPermission('modify', 'extension/extension/module')) {
			\Aichat\Installer::uninstall($this->registry);
		}
	}
}
