/* AI Chat admin: knowledge base dialog (list page and "save correct answer" in the transcript). */
(function ($) {
	'use strict';

	var C, T, $modal;

	function esc(v) {
		return $('<i>').text(v == null ? '' : v).html();
	}

	function build() {
		var h = '<div class="modal fade" id="aichat-kb-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content aichat-admin">' +
			'<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="aichat-kb-title"></h4></div>' +
			'<div class="modal-body">' +
			'<div class="alert alert-danger" id="aichat-kb-err" style="display:none"></div>' +
			'<div class="aichat-kb-ref" id="aichat-kb-ref" style="display:none"><b>' + esc(T.text_kb_reference) + '</b><div class="aichat-kb-ref-text"></div></div>' +
			'<div class="row"><div class="col-sm-4"><div class="form-group"><label>' + esc(T.entry_store) + '</label><select class="form-control" id="aichat-kb-store"><option value="-1">' + esc(T.text_all_stores) + '</option>';
		$.each(C.stores, function (i, s) {
			h += '<option value="' + s.store_id + '">' + esc(s.name) + '</option>';
		});
		h += '</select></div></div><div class="col-sm-4"><div class="form-group"><label>' + esc(T.entry_language) + '</label><select class="form-control" id="aichat-kb-lang"><option value="0">' + esc(T.text_all_languages) + '</option>';
		$.each(C.languages, function (i, l) {
			h += '<option value="' + l.language_id + '">' + esc(l.name) + '</option>';
		});
		h += '</select></div></div><div class="col-sm-4"><div class="form-group"><label>' + esc(T.entry_status) + '</label><select class="form-control" id="aichat-kb-status"><option value="1">' + esc(T.text_kb_on) + '</option><option value="0">' + esc(T.text_kb_off) + '</option></select></div></div></div>' +
			'<div class="form-group"><label>' + esc(T.entry_question) + '</label><textarea class="form-control" rows="2" id="aichat-kb-q" maxlength="1000"></textarea><div class="help-block">' + esc(T.help_kb_question) + '</div></div>' +
			'<div class="form-group"><label>' + esc(T.entry_answer) + '</label><textarea class="form-control" rows="6" id="aichat-kb-a" maxlength="4000"></textarea><div class="help-block">' + esc(T.help_kb_answer) + '</div></div>' +
			'<input type="hidden" id="aichat-kb-id" value="0" /><input type="hidden" id="aichat-kb-src" value="0" />' +
			'</div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">' + esc(T.kb_btn_cancel) + '</button> <button type="button" class="btn btn-primary" id="aichat-kb-save"><i class="fa fa-save"></i> ' + esc(T.kb_btn_save) + '</button></div>' +
			'</div></div></div>';
		$modal = $(h).appendTo('body');
		$modal.on('click', '#aichat-kb-save', save);
	}

	function open(o) {
		o = o || {};
		$('#aichat-kb-title').text(o.kb_id ? T.kb_title_edit + ' #' + o.kb_id : T.kb_title_add);
		$('#aichat-kb-id').val(o.kb_id || 0);
		$('#aichat-kb-src').val(o.source_message_id || 0);
		$('#aichat-kb-store').val(o.store_id !== undefined ? o.store_id : -1);
		$('#aichat-kb-lang').val(o.language_id !== undefined ? o.language_id : 0);
		$('#aichat-kb-status').val(o.status !== undefined ? o.status : 1);
		$('#aichat-kb-q').val(o.question || '');
		$('#aichat-kb-a').val(o.answer || '');
		$('#aichat-kb-err').hide();
		var $ref = $('#aichat-kb-ref');
		if (o.reference) {
			$ref.show().find('.aichat-kb-ref-text').text(o.reference);
		} else {
			$ref.hide();
		}
		$modal.modal('show');
		setTimeout(function () {
			$(o.question ? '#aichat-kb-a' : '#aichat-kb-q').focus();
		}, 300);
	}

	function save() {
		var $b = $('#aichat-kb-save').prop('disabled', true);
		$.post(C.save, {
			user_token: C.token, kb_id: $('#aichat-kb-id').val(), store_id: $('#aichat-kb-store').val(), language_id: $('#aichat-kb-lang').val(),
			status: $('#aichat-kb-status').val(), question: $('#aichat-kb-q').val(), answer: $('#aichat-kb-a').val(), source_message_id: $('#aichat-kb-src').val()
		}, null, 'json').done(function (r) {
			if (r && r.success) {
				$modal.modal('hide');
				$(document).trigger('aichat-kb-saved', [r, $('#aichat-kb-src').val()]);
			} else {
				$('#aichat-kb-err').text((r && r.error) || T.text_error).show();
			}
		}).fail(function () {
			$('#aichat-kb-err').text(T.text_error).show();
		}).always(function () {
			$b.prop('disabled', false);
		});
	}

	window.AichatKb = {open: open};

	$(function () {
		C = window.AICHAT_KB;
		if (!C) {
			return;
		}
		T = C.i18n;
		build();

		// list page
		$('#aichat-kb-add').on('click', function () {
			open({});
		});
		$(document).on('click', '.aichat-kb-edit', function () {
			var row = $(this).data('row');
			if (typeof row === 'string') {
				row = JSON.parse(row);
			}
			open(row);
		});
		var P = window.AICHAT_KBPAGE;
		if (P) {
			$(document).on('click', '.aichat-kb-toggle', function () {
				$.post(P.toggle, {user_token: C.token, kb_id: $(this).data('id'), status: $(this).data('status')}, null, 'json').done(function () {
					window.location.reload();
				});
			});
			$(document).on('click', '.aichat-kb-delete', function () {
				if (window.confirm(P.confirm)) {
					$.post(P.del, {user_token: C.token, kb_id: $(this).data('id')}, null, 'json').done(function () {
						window.location.reload();
					});
				}
			});
			$(document).on('aichat-kb-saved', function () {
				window.location.reload();
			});
		}
	});
})(jQuery);
