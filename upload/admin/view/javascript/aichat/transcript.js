/* AI Chat admin: "bad answer" flags on the transcript page. */
(function ($) {
	'use strict';

	function send(data, done) {
		var C = window.AICHAT_FLAG;
		data.user_token = C.token;
		$.post(C.url, data, null, 'json').done(function (r) {
			if (r && r.success) {
				done(r);
			} else {
				window.alert((r && r.error) || 'Error');
			}
		}).fail(function () {
			window.alert('Error');
		});
	}

	$(function () {
		// save the correct answer to the knowledge base (shared dialog from kb.js)
		$(document).on('click', '.aichat-kb-btn', function () {
			var $m = $(this).closest('[data-message]'), K = window.AICHAT_KB;
			if (!window.AichatKb || !K) {
				return;
			}
			window.AichatKb.open({
				kb_id: 0, source_message_id: $m.data('message'), store_id: K.ctx.store_id, language_id: K.ctx.language_id, status: 1,
				question: $(this).attr('data-q') || '', answer: '', reference: $(this).attr('data-a') || ''
			});
		});
		$(document).on('aichat-kb-saved', function (e, r, src) {
			var $m = $('[data-message="' + src + '"]');
			if ($m.length && !$m.find('.aichat-kb-done').length) {
				$m.find('.aichat-msg-head').after('<div class="aichat-ctx aichat-kb-done"><i class="fa fa-check text-success"></i> ' + window.AICHAT_KB.i18n.text_kb_saved + ' <a href="' + window.AICHAT_KB.save.replace('kb_save', 'kb') + '">' + window.AICHAT_KB.i18n.text_kb_open + '</a></div>');
			}
		});
		$('#aichat-delete-conv').on('click', function () {
			var C = window.AICHAT_FLAG;
			if (!window.confirm(C.confirm)) {
				return;
			}
			$.post(C.del, {user_token: C.token, conversation_id: $(this).data('id')}, null, 'json').done(function (r) {
				if (r && r.success) {
					window.location.href = r.redirect;
				} else {
					window.alert((r && r.error) || 'Error');
				}
			});
		});

		$(document).on('click', '.aichat-flag-btn', function () {
			var $m = $(this).closest('[data-message]');
			$m.find('.aichat-flag-form').toggle().find('textarea').focus();
		});
		$(document).on('click', '.aichat-flag-save', function () {
			var $m = $(this).closest('[data-message]');
			var flagged = $(this).data('flag') ? 1 : 0;
			var note = $m.find('.aichat-flag-form textarea').val();
			var data = {flagged: flagged, note: note};
			if ($m.data('message')) {
				data.message_id = $m.data('message');
			} else {
				data.conversation_id = $m.data('conversation');
			}
			send(data, function (r) {
				$m.toggleClass('is-flagged', !!r.flagged);
				$m.find('.aichat-flag-btn').toggleClass('btn-danger', !!r.flagged).toggleClass('btn-default', !r.flagged);
				$m.find('.aichat-flag-note').text(r.note).toggle(!!r.flagged && !!r.note);
				$m.find('.aichat-flag-form').hide();
				$m.find('.aichat-flag-clear').toggle(!!r.flagged);
			});
		});
	});
})(jQuery);
