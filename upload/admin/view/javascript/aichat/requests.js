/* AI Chat admin: requests list (details row, mark done / reopen). */
(function ($) {
	'use strict';

	$(function () {
		var C = window.AICHAT_RQ;
		if (!C) {
			return;
		}

		$(document).on('click', '.aichat-rq-details', function () {
			$(this).closest('tr').next('.aichat-rq-detail').toggle();
		});

		$(document).on('click', '.aichat-rq-toggle', function () {
			var $btn = $(this), $row = $btn.closest('tr'), next = $btn.data('status');
			$btn.prop('disabled', true);
			$.post(C.url, {user_token: C.token, request_id: $row.data('id'), status: next}, null, 'json').done(function (r) {
				if (r && r.success) {
					var done = r.status === 'done';
					$row.toggleClass('is-done', done);
					$row.find('.aichat-rq-status').html(done ? '<span class="label label-success">' + C.i18n.done + '</span>' : '<span class="label label-danger">' + C.i18n['new'] + '</span>');
					$btn.data('status', done ? 'new' : 'done').attr('data-status', done ? 'new' : 'done')
						.toggleClass('btn-success', !done).toggleClass('btn-default', done)
						.html(done ? '<i class="fa fa-repeat"></i> ' + C.i18n.reopen : '<i class="fa fa-check"></i> ' + C.i18n.mark_done);
					var $badge = $('.aichat-badge');
					if (r.open > 0) {
						$badge.text(r.open);
					} else {
						$badge.remove();
					}
				}
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
