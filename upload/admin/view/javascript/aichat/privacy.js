/* AI Chat admin: personal data (GDPR) page and compliance page helpers. */
(function ($) {
	'use strict';

	$(function () {
		// compliance page: copy the record of processing
		$('#aichat-ropa-copy').on('click', function () {
			var $t = $('#aichat-ropa');
			$t.trigger('focus').trigger('select');
			try {
				document.execCommand('copy');
				$(this).find('span').text($(this).data('done'));
			} catch (e) { /* the text stays selected */ }
		});

		var G = window.AICHAT_GDPR, $f = $('#aichat-dsr-act');
		if (!G || !$f.length) {
			return;
		}

		function ids(group) {
			return $f.find('.aichat-pick[data-group="' + group + '"]:checked').map(function () {
				return this.value;
			}).get();
		}

		function base() {
			return {
				user_token: G.token,
				case: $f.data('case'), received: $f.data('received'), ref: $f.attr('data-ref') || '',
				email: $f.attr('data-email') || '', phone: $f.attr('data-phone') || '', order_id: $f.attr('data-order') || '', customer_id: $f.attr('data-customer') || ''
			};
		}

		function msg(text, ok) {
			$('#aichat-dsr-msg').html($('<div class="alert">').addClass(ok ? 'alert-success' : 'alert-danger').text(text));
		}

		$f.on('change', '.aichat-all', function () {
			$f.find('.aichat-pick[data-group="' + $(this).data('group') + '"]').prop('checked', this.checked);
		});

		$('#aichat-dsr-export').on('click', function () {
			var c = ids('c'), r = ids('r'), $b = $(this);
			if (!c.length && !r.length) {
				return msg(G.i18n.select, false);
			}
			$b.prop('disabled', true);
			$.post(G.urls.export, $.extend(base(), {conversation_ids: c, request_ids: r}), null, 'json').done(function (j) {
				if (j && j.success && j.url) {
					window.location.href = j.url;
					msg(G.i18n.exported, true);
				} else {
					msg((j && j.error) || G.i18n.error, false);
				}
			}).fail(function () {
				msg(G.i18n.error, false);
			}).always(function () {
				$b.prop('disabled', false);
			});
		});

		$('#aichat-dsr-erase').on('click', function () {
			var c = ids('c'), r = ids('r'), $b = $(this), word = $.trim($('#aichat-erase-word').val());
			if (!c.length && !r.length) {
				return msg(G.i18n.select, false);
			}
			if (word !== G.word) {
				$('#aichat-erase-word').trigger('focus');
				return msg(G.i18n.erase_word, false);
			}
			if (!window.confirm(G.i18n.erase_confirm)) {
				return;
			}
			$b.prop('disabled', true);
			$.post(G.urls.erase, $.extend(base(), {conversation_ids: c, request_ids: r, confirm: word}), null, 'json').done(function (j) {
				if (j && j.success) {
					msg(j.message, true);
					$f.find('.aichat-pick:checked').closest('tr').fadeOut();
					$('#aichat-erase-word').val('');
				} else {
					msg((j && j.error) || G.i18n.error, false);
				}
			}).fail(function () {
				msg(G.i18n.error, false);
			}).always(function () {
				$b.prop('disabled', false);
			});
		});

		$f.on('click', '.aichat-restrict', function () {
			var $b = $(this), on = String($b.attr('data-on')) === '1';
			$b.prop('disabled', true);
			$.post(G.urls.restrict, $.extend(base(), {conversation_id: $b.data('id'), on: on ? 1 : 0}), null, 'json').done(function (j) {
				if (j && j.success) {
					var now = !!j.restricted;
					$b.attr('data-on', now ? 0 : 1).find('span').text(now ? G.unrestrict : G.restrict);
					$b.closest('tr').find('.aichat-restrict-state').html(now ? '<span class="label label-warning"><i class="fa fa-pause-circle"></i> ' + $('<i>').text(G.restricted).html() + '</span>' : '<span class="aichat-muted">' + $('<i>').text(G.active).html() + '</span>');
					msg(j.message, true);
				} else {
					msg((j && j.error) || G.i18n.error, false);
				}
			}).always(function () {
				$b.prop('disabled', false);
			});
		});
	});
})(jQuery);
