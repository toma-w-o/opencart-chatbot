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
