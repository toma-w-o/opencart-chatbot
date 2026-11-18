<?php
/**
 * Permission entry only: "extension/module/aichat_pd" = may see personal data in AI Chat
 * (full transcripts, contact data, photos, export, erase). OpenCart lists a permission in
 * Users > User Groups only when a controller file exists for it, and drops unlisted entries
 * when the group is saved - hence this file. It is not a module: opening it leads to the
 * GDPR page, and Install/Uninstall do nothing.
 */
class ControllerExtensionModuleAichatPd extends Controller {
	public function index() {
		$this->response->redirect($this->url->link('extension/module/aichat/privacy', 'user_token=' . $this->session->data['user_token'], true));
	}

	public function install() {
	}

	public function uninstall() {
	}
}
