/* AI Chat admin: settings page behaviour (tabs, store switcher, couriers, live preview, factory reset). */
(function ($) {
	'use strict';

	function field(sid, key) {
		return $('[name="s[' + sid + '][' + key + ']"]');
	}

	function num(v, d) {
		var n = parseInt(v, 10);
		return isNaN(n) ? d : n;
	}

	function initial(name) {
		name = $.trim(name || '');
		return name ? name.charAt(0).toUpperCase() : 'AI';
	}

	/* ---------- store switcher ---------- */
	var $form, $bar, $select;

	function currentStore() {
		return $select.val();
	}

	function showStore(sid) {
		$form.find('.aichat-store').each(function () {
			$(this).toggle(String($(this).data('store')) === String(sid));
		});
		$select.val(sid);
		$form.find('.aichat-preview[data-store="' + sid + '"]').each(function () {
			updatePreview(sid);
		});
	}

	function syncBar() {
		var isStoreTab = $('#aichat-tabs li.active').hasClass('aichat-tab-store');
		$bar.toggle(isStoreTab);
	}

	function activateTab(id) {
		$('#aichat-tabs a[href="' + id + '"]').tab('show');
	}

	/* ---------- couriers ---------- */
	function courierIndex() {
		var max = -1;
		$('#aichat-couriers .aichat-courier').each(function () {
			max = Math.max(max, num($(this).attr('data-idx'), -1));
		});
		return max + 1;
	}

	function syncCourierRow($row) {
		$row.find('.aichat-courier-secret').toggle($row.find('.aichat-courier-type').val() === 'speedy');
	}

	function refreshCourierSelects() {
		var opts = [];
		$('#aichat-couriers .aichat-courier').each(function () {
			var code = $.trim($(this).find('input[name$="[code]"]').val()).toLowerCase().replace(/[^a-z0-9_-]/g, '');
			var name = $.trim($(this).find('input[name$="[name]"]').val()) || code;
			if (code) {
				opts.push({code: code, name: name});
			}
		});
		$('.aichat-courier-multi').each(function () {
			var $s = $(this), cur = $s.val();
			if (cur === null || typeof cur === 'undefined') {
				try {
					cur = JSON.parse($s.attr('data-selected') || '[]');
				} catch (e) {
					cur = [];
				}
			}
			$s.empty();
			$.each(opts, function (i, o) {
				$s.append($('<option>').val(o.code).text(o.name));
			});
			$s.val(cur);
			$s.attr('data-selected', JSON.stringify($s.val() || []));
		});
		$('.aichat-courier-select').each(function () {
			var $s = $(this), cur = $s.val() || $s.data('selected') || '', none = $s.find('option:first').text();
			$s.empty().append($('<option>').val('').text(none));
			$.each(opts, function (i, o) {
				$s.append($('<option>').val(o.code).text(o.name));
			});
			$s.val(cur);
		});
	}

	/* ---------- Telegram: canned replies, webhook and test buttons ---------- */
	function cannedIndex() {
		var max = -1;
		$('#aichat-canned .aichat-canned').each(function () {
			max = Math.max(max, num($(this).attr('data-idx'), -1));
		});
		return max + 1;
	}

	function tgCall($btn, extra, $out) {
		var data = {user_token: $form.find('input[name="user_token"]').val() || window.AICHAT_UT || '', tg_token: $('#input-tg-token').val()};
		$.extend(data, extra || {});
		$btn.prop('disabled', true).find('i').addClass('fa-spin');
		$out.removeClass('is-ok is-bad').text('...');
		$.post($btn.data('url'), data, null, 'json').done(function (r) {
			$out.addClass(r && r.success ? 'is-ok' : 'is-bad').text((r && r.message) || 'Error');
		}).fail(function () {
			$out.addClass('is-bad').text('Error');
		}).always(function () {
			$btn.prop('disabled', false).find('i').removeClass('fa-spin');
		});
	}

	/* ---------- preview ---------- */
	function updatePreview(sid) {
		var $pv = $('.aichat-preview[data-store="' + sid + '"]');
		if (!$pv.length) {
			return;
		}
		var accent = field(sid, 's_color_accent').val() || '#2563eb';
		var hbg = field(sid, 's_color_header_bg').val() || '#1e3a8a';
		var htx = field(sid, 's_color_header_text').val() || '#ffffff';
		var ubg = field(sid, 's_color_user_bg').val() || '#2563eb';
		var utx = field(sid, 's_color_user_text').val() || '#ffffff';
		var name = $.trim(field(sid, 's_bot_name').val());
		var style = field(sid, 's_launcher_style').val();
		var shape = field(sid, 's_launcher_shape').val();
		var size = Math.max(32, Math.min(120, num(field(sid, 's_launcher_size').val(), 56)));
		var asize = num(field(sid, 's_launcher_avatar_size').val(), 0);
		asize = asize > 0 ? Math.min(100, Math.max(20, asize)) : (style === 'avatar_text' ? 36 : 45);
		var text = '';
		$('.aichat-store[data-store="' + sid + '"] .aichat-launcher-text').each(function () {
			if (!text && $.trim($(this).val())) {
				text = $.trim($(this).val());
			}
		});
		text = text || name;

		var avatarUrl = $('[name="s[' + sid + '][s_avatar]"]:checked').closest('label').find('img').attr('src') || '';
		var avHtml = '<b>' + $('<i>').text(initial(name)).html() + '</b>' + (avatarUrl ? '<img src="' + avatarUrl + '" alt="" onerror="this.style.display=\'none\'">' : '');

		$pv.find('.aichat-pv-head').css({background: hbg, color: htx});
		$pv.find('.aichat-pv-name').text(name || 'AI');
		$pv.find('.aichat-pv-avatar').html(avHtml);
		$pv.find('.aichat-pv-user').css({background: ubg, color: utx});
		$pv.find('.aichat-pv-chip').css({borderColor: accent, color: accent});

		var withText = style === 'avatar_text';
		var $l = $pv.find('.aichat-pv-launcher');
		var radius = shape === 'circle' ? '50%' : (shape === 'rounded' ? '16px' : size + 'px');
		if (withText && shape === 'circle') {
			radius = size + 'px';
		}
		$l.css({background: accent, color: '#fff', height: size + 'px', minWidth: size + 'px', width: withText ? 'auto' : size + 'px', borderRadius: radius});
		$l.find('.aichat-pv-lav').css({width: (withText ? asize : Math.min(asize, size - 4)) + 'px', height: (withText ? asize : Math.min(asize, size - 4)) + 'px'}).html(avHtml);
		$l.find('.aichat-pv-ltext').text(text).toggle(withText);

		var dev = $pv.find('.aichat-pv-devices .active').data('dev') || 'desktop';
		var side = field(sid, 's_pos_' + dev + '_side').val() === 'left' ? 'left' : 'right';
		var x = Math.max(0, num(field(sid, 's_pos_' + dev + '_x').val(), 20));
		var y = Math.max(0, num(field(sid, 's_pos_' + dev + '_y').val(), 20));
		$pv.find('.aichat-pv-frame').toggleClass('is-mobile', dev === 'mobile').toggleClass('is-desktop', dev !== 'mobile');
		$l.css({left: side === 'left' ? x + 'px' : 'auto', right: side === 'right' ? x + 'px' : 'auto', bottom: y + 'px'});
	}

	/* ---------- factory reset ---------- */
	function resetIn($scope) {
		$scope.find('[data-default]').each(function () {
			var $e = $(this);
			$e.val($e.attr('data-default'));
			if ($e.hasClass('aichat-colortext')) {
				$e.siblings('.aichat-colorpick').val($e.val());
			}
		});
	}

	$(function () {
		$form = $('#form-aichat');
		if (!$form.length) {
			return;
		}
		$bar = $('#aichat-storebar');
		$select = $('#aichat-store-select');

		$select.on('change', function () {
			showStore($(this).val());
		});
		$('#aichat-tabs a[data-toggle="tab"]').on('shown.bs.tab', function () {
			syncBar();
			if ($(this).attr('href') === '#tab-orders') {
				refreshCourierSelects();
			}
			updatePreview(currentStore());
		});
		$('.aichat-goto-tab').on('click', function (e) {
			e.preventDefault();
			activateTab($(this).attr('href'));
		});

		// couriers
		$('#aichat-couriers .aichat-courier').each(function () {
			syncCourierRow($(this));
		});
		$('#aichat-courier-add').on('click', function () {
			var html = $('#aichat-courier-tpl').html().replace(/__I__/g, courierIndex());
			var $row = $(html);
			$('#aichat-couriers').append($row);
			syncCourierRow($row);
		});
		$('#aichat-couriers').on('click', '.aichat-courier-del', function () {
			$(this).closest('.aichat-courier').remove();
		}).on('change', '.aichat-courier-type', function () {
			syncCourierRow($(this).closest('.aichat-courier'));
		});

		// Telegram
		$('#aichat-canned-add').on('click', function () {
			$('#aichat-canned').append($($('#aichat-canned-tpl').html().replace(/__I__/g, cannedIndex())));
		});
		$('#aichat-canned').on('click', '.aichat-canned-del', function () {
			$(this).closest('.aichat-canned').remove();
		});
		$('#aichat-tg-webhook').on('click', function () {
			tgCall($(this), {}, $('#aichat-tg-result'));
		});
		$('#aichat-tg-test').on('click', function () {
			tgCall($(this), {chat_id: $.trim($('#input-tg-chat').val())}, $('#aichat-tg-result'));
		});
		$form.on('click', '.aichat-tg-store-test', function () {
			var $st = $(this).closest('.aichat-store');
			tgCall($(this), {chat_id: $.trim($st.find('.aichat-tg-store-chat').val()) || $.trim($('#input-tg-chat').val())}, $st.find('.aichat-tg-store-result'));
		});

		// "Send test e-mail" per store: sends through the store's mail settings and shows the real outcome
		$form.on('click', '.aichat-test-mail', function () {
			var $btn = $(this), $st = $btn.closest('.aichat-store'), $out = $st.find('.aichat-test-mail-result');
			var data = {user_token: $form.find('input[name="user_token"]').val() || window.AICHAT_UT || '', store_id: $btn.data('store'), notify_email: $.trim($st.find('.aichat-notify-email').val())};
			$btn.prop('disabled', true).find('i').addClass('fa-spin');
			$out.removeClass('is-ok is-bad').text('...');
			$.post($btn.data('url'), data, null, 'json').done(function (r) {
				$out.addClass(r && r.success ? 'is-ok' : 'is-bad').text((r && r.message) || 'Error');
			}).fail(function (x) {
				$out.addClass('is-bad').text('Error ' + (x && x.status ? x.status : ''));
			}).always(function () {
				$btn.prop('disabled', false).find('i').removeClass('fa-spin');
			});
		});

		// colours: picker <-> text
		$form.on('input change', '.aichat-colorpick', function () {
			$(this).siblings('.aichat-colortext').val($(this).val()).trigger('input');
		});
		$form.on('input', '.aichat-colortext', function () {
			var v = $.trim($(this).val());
			if (/^#[0-9a-fA-F]{6}$/.test(v)) {
				$(this).siblings('.aichat-colorpick').val(v);
			}
		});

		// reset
		$('#aichat-reset-store').on('click', function () {
			if (window.confirm($(this).data('confirm') || 'Reset?')) {
				var sid = currentStore();
				resetIn($form.find('.aichat-store[data-store="' + sid + '"]'));
				updatePreview(sid);
			}
		});
		$form.on('click', '.aichat-reset-appearance', function () {
			var sid = currentStore();
			resetIn($(this).closest('.aichat-store'));
			updatePreview(sid);
		});

		// preview
		$form.on('input change', '.aichat-store input, .aichat-store select, .aichat-store textarea', function () {
			updatePreview($(this).closest('.aichat-store').data('store'));
		});
		$form.on('click', '.aichat-pv-devices button', function () {
			$(this).addClass('active').siblings().removeClass('active');
			updatePreview(currentStore());
		});

		/* ---------- privacy (round 6): notice preview, retention warnings ---------- */
		var POLICY = /(политика\S*\s+за\s+поверителност|поверителност|privacy\s+policy|privacy\s+notice|πολιτικ\S*\s+απορρήτου|απόρρητο\S*)/i;
		function privacyPreview($row) {
			var sid = $row.data('store');
			var $txt = $row.find('.aichat-privacy-text');
			var text = $.trim($txt.val()) || $.trim($txt.attr('placeholder') || '');
			var url = $.trim($row.find('.aichat-privacy-url').val()) || $row.find('.aichat-privacy-url').data('auto') || '';
			var accept = field(sid, 's_privacy_accept').val() === '1';
			var $t = $row.find('.aichat-pp-text').empty();
			var m = url ? POLICY.exec(text) : null;
			if (m) {
				$t.append(document.createTextNode(text.slice(0, m.index)));
				$t.append($('<a target="_blank" rel="noopener noreferrer">').attr('href', url).text(m[0]));
				$t.append(document.createTextNode(text.slice(m.index + m[0].length)));
			} else {
				$t.text(text + ' ');
				if (url) {
					$t.append($('<a target="_blank" rel="noopener noreferrer">').attr('href', url).text($form.data('privacy-link') || 'Privacy policy'));
				}
			}
			$row.find('.aichat-pp-accept').toggle(accept);
			$row.find('.aichat-pp-input').toggleClass('is-locked', accept);
			// the notice names a period: warn when it differs from the conversation retention
			var months = num($('#input-g_retention_months').val(), 0);
			var pm = /(\d+)\s*(месец|месеца|month|μήν|μην)/i.exec(text);
			$row.find('.aichat-privacy-period').toggle(!!(pm && months > 0 && num(pm[1], 0) !== months));
			$row.find('.aichat-privacy-preview').css('--pp-accent', field(sid, 's_color_accent').val() || '#2563eb');
		}
		function allPrivacy() {
			$('.aichat-privacy-row').each(function () {
				privacyPreview($(this));
			});
		}
		$form.on('input change', '.aichat-privacy-text, .aichat-privacy-url', function () {
			privacyPreview($(this).closest('.aichat-privacy-row'));
		});
		$form.on('change', '.aichat-privacy-accept', allPrivacy);
		function retentionWarn($i) {
			var v = num($i.val(), 0), lim = num($i.data('warn'), 12);
			$i.closest('.form-group').find('.aichat-retention-warn').toggle(v > lim || ($.trim($i.val()) === '0'));
		}
		$form.on('input change', '.aichat-retention', function () {
			retentionWarn($(this));
			if (this.id === 'input-g_retention_months') {
				allPrivacy();
			}
		});
		$('.aichat-retention').each(function () {
			retentionWarn($(this));
		});
		allPrivacy();

		// initial state: first store, tab from error / ?tab=
		showStore($select.find('option:first').val());
		var $err = $form.find('.aichat-err:first, .tab-pane .text-danger:first').first();
		var start = $form.data('start-tab');
		if ($err.length) {
			var $pane = $err.closest('.tab-pane');
			var $st = $err.closest('.aichat-store');
			if ($st.length) {
				showStore($st.data('store'));
			}
			activateTab('#' + $pane.attr('id'));
		} else if (start) {
			activateTab('#tab-' + start);
		}
		syncBar();
		refreshCourierSelects();
	});
})(jQuery);
