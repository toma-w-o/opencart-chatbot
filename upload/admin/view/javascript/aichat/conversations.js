/* AI Chat admin: delete a conversation from the list (confirm first). */
(function ($) {
	'use strict';

	$(function () {
		var C = window.AICHAT_CONV;
		if (!C) {
			return;
		}
		$(document).on('click', '.aichat-conv-delete', function () {
			var id = $(this).data('id');
			if (!window.confirm(C.confirm)) {
				return;
			}
			$.post(C.url, {user_token: C.token, conversation_id: id}, null, 'json').done(function (r) {
				if (r && r.success) {
					window.location.reload();
				} else {
					window.alert((r && r.error) || 'Error');
				}
			});
		});
	});
})(jQuery);
