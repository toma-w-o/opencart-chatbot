/* AI Chat admin: test chat. Messages go through the admin controller to the store's catalog `send` (preview token). */
(function ($) {
	'use strict';

	var C, T, previewId = 0, busy = false, $log;

	function esc(v) {
		return $('<i>').text(v == null ? '' : v).html();
	}

	function nl(v) {
		return esc(v).replace(/\n/g, '<br>');
	}

	function store() {
		var id = String($('#tc-store').val()), found = null;
		$.each(C.stores, function (i, s) {
			if (String(s.store_id) === id) {
				found = s;
			}
		});
		return found || C.stores[0];
	}

	function fillStore() {
		var s = store();
		$('#tc-url').val(s.url);
		$('#tc-sinst').val(s.instructions);
	}

	function reset(silent) {
		previewId = 0;
		$('#tc-conv').text('');
		$('#tc-open').hide();
		$('#tc-foot').text($('#tc-foot').data('base'));
		$log.html('<div class="aichat-chart-empty">' + esc(silent ? T.empty : T.reset_done) + '</div>');
	}

	function bubble(who, html, cls) {
		if ($log.find('.aichat-chart-empty').length) {
			$log.empty();
		}
		var $b = $('<div class="aichat-tc-msg ' + cls + '"><div class="aichat-tc-who">' + esc(who) + '</div><div class="aichat-tc-bubble">' + html + '</div></div>');
		$log.append($b);
		$log.scrollTop($log[0].scrollHeight);

		return $b;
	}

	function cards(r) {
		var h = '';
		$.each(r.products || [], function (i, p) {
			h += '<a class="aichat-tc-product' + (p.label === 'suggestion' ? ' is-suggestion' : '') + '"' + (p.href ? ' href="' + esc(p.href) + '" target="_blank" rel="noopener"' : '') + '>' +
				(p.image ? '<img src="' + esc(p.image) + '" alt="">' : '<span class="aichat-tc-noimg"><i class="fa fa-cube"></i></span>') +
				'<span class="aichat-tc-pname">' + esc(p.name) + '</span><span class="aichat-tc-price">' + (p.special ? '<s>' + esc(p.price) + '</s> <b>' + esc(p.special) + '</b>' : esc(p.price)) + '</span>' +
				(p.label === 'suggestion' ? '<span class="aichat-tc-sug">' + esc(T.suggestion) + '</span>' : '') + '</a>';
		});
		$.each(r.cards || [], function (i, c) {
			h += '<a class="aichat-tc-link"' + (c.href ? ' href="' + esc(c.href) + '" target="_blank" rel="noopener"' : '') + '>' + (c.image ? '<img src="' + esc(c.image) + '" alt="">' : '<i class="fa fa-link"></i>') +
				'<span><small>' + esc(c.type) + '</small> ' + esc(c.title) + (c.subtitle ? '<br><small>' + esc(c.subtitle) + '</small>' : '') + '</span></a>';
		});

		return h ? '<div class="aichat-tc-cards">' + h + '</div>' : '';
	}

	function meta(r) {
		var h = '<div class="aichat-tc-meta">';
		h += '<span><i class="fa fa-usd"></i> ' + esc(T.cost) + ': <b>' + esc(r.cost || '-') + '</b></span>';
		h += '<span>' + r.tokens_in + ' ' + esc(T.tokens_in) + ' &middot; ' + r.tokens_out + ' ' + esc(T.tokens_out) + '</span>';
		if (r.model) {
			h += '<span>' + esc(r.model) + '</span>';
		}
		if (r.mode && r.mode !== 'bot') {
			h += '<span class="label label-warning">' + esc(r.mode === 'waiting' ? T.waiting : T.handover) + '</span>';
		}
		h += '</div>';
		h += '<div class="aichat-tc-tools"><i class="fa fa-wrench"></i> ' + esc(T.tools) + ': ';
		if (r.tools && r.tools.length) {
			$.each(r.tools, function (i, t) {
				h += '<span class="aichat-tc-tool' + (t.error ? ' is-error' : '') + '" title="' + esc(t.input) + '"><code>' + esc(t.name) + '</code></span> ';
			});
		} else {
			h += '<span class="aichat-muted">' + esc(T.no_tools) + '</span>';
		}
		h += '</div>';

		return h;
	}

	function send(text) {
		if (busy || !$.trim(text)) {
			return;
		}
		busy = true;
		$('#tc-send').prop('disabled', true);
		bubble(T.you, nl(text), 'is-user');
		var $wait = bubble(T.bot, '<span class="aichat-tc-dots"><i></i><i></i><i></i></span> <span class="aichat-muted">' + esc(T.thinking) + '</span>', 'is-bot');
		var s = store();

		$.post(C.send, {
			user_token: C.token, store_id: s.store_id, url: $('#tc-url').val(), message: text, preview_id: previewId, language_code: $('#tc-lang').val() || '',
			g_instructions: $('#tc-ginst').val(), s_instructions: $('#tc-sinst').val()
		}, null, 'json').done(function (r) {
			if (r && r.preview_id) {
				previewId = r.preview_id;
			}
			if (r && r.success) {
				$wait.find('.aichat-tc-bubble').html(nl(r.reply) + cards(r) + meta(r));
				if (r.conversation_id) {
					$('#tc-conv').text('#' + r.conversation_id);
					$('#tc-open').attr('href', C.transcript.replace('__ID__', r.conversation_id)).show();
				}
				if (r.conv_cost) {
					$('#tc-foot').text($('#tc-foot').data('base') + ' ' + T.conv_cost + ': ' + r.conv_cost);
				}
			} else {
				$wait.addClass('is-error').find('.aichat-tc-bubble').html('<i class="fa fa-exclamation-triangle"></i> ' + esc((r && r.error) || T.error));
			}
		}).fail(function () {
			$wait.addClass('is-error').find('.aichat-tc-bubble').html('<i class="fa fa-exclamation-triangle"></i> ' + esc(T.error));
		}).always(function () {
			busy = false;
			$('#tc-send').prop('disabled', false);
			$log.scrollTop($log[0].scrollHeight);
			$('#tc-input').focus();
		});
	}

	$(function () {
		C = window.AICHAT_TC;
		if (!C) {
			return;
		}
		T = C.i18n;
		$log = $('#tc-log');
		var ginst0 = $('#tc-ginst').val();
		$('#tc-foot').data('base', $('#tc-foot').text());
		fillStore();

		$('#tc-store').on('change', function () {
			fillStore();
			reset(true);
		});
		$('#tc-lang').on('change', function () {
			reset(true);
		});
		$('#tc-restore').on('click', function () {
			fillStore();
			$('#tc-ginst').val(ginst0);
		});
		$('#tc-reset').on('click', function () {
			reset(false);
		});
		$('#tc-form').on('submit', function (e) {
			e.preventDefault();
			var t = $('#tc-input').val();
			$('#tc-input').val('');
			send(t);
		});
		$('#tc-input').on('keydown', function (e) {
			if (e.which === 13 && !e.shiftKey) {
				e.preventDefault();
				$('#tc-form').trigger('submit');
			}
		});
	});
})(jQuery);
