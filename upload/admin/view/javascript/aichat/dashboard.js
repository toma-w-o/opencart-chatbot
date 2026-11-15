/* AI Chat dashboard: KPI tiles, charts, "live now" list; refreshes every 15 s. */
(function ($) {
	'use strict';

	var C, T, esc = AichatCharts.esc;
	var state = {range: 7, store: ''};
	var last = null;

	function money(v) {
		v = +v || 0;
		return '$' + v.toFixed(v !== 0 && Math.abs(v) < 0.1 ? 4 : 2);
	}

	function params() {
		return {range: state.range, store_id: state.store};
	}

	function tile(k, html) {
		$('[data-k="' + k + '"]').html(html);
	}

	function render(d) {
		last = d;
		var k = d.kpi;
		tile('chats', k.chats);
		tile('messages', k.messages);
		tile('avg', k.avg_msgs);
		tile('flagged', k.flagged);
		tile('active', k.active);
		tile('handover', k.handover);
		tile('errors', k.errors);
		tile('cost', money(k.cost));

		var $b = $('[data-k="budget"]');
		if (k.budget > 0) {
			var pct = k.month_spend * 100 / k.budget;
			$b.html('<div class="aichat-meter"><span class="' + (pct >= 100 ? 'is-over' : pct >= 80 ? 'is-warn' : '') + '" style="width:' + Math.min(100, pct).toFixed(1) + '%"></span></div>' +
				'<small>' + money(k.month_spend) + ' ' + esc(T.of) + ' ' + money(k.budget) + ' (' + Math.round(pct) + '%)</small>');
		} else {
			$b.html('<small>' + esc(T.budget_off) + ' &middot; ' + money(k.month_spend) + '</small>');
		}

		var labels = d.days, i, s, chats = [], cost = [], sid;
		var stores = d.stores.filter(function (st) {
			return state.store === '' || String(st.store_id) === String(state.store);
		});
		for (i = 0; i < stores.length; i++) {
			sid = stores[i].store_id;
			chats.push({name: stores[i].name, values: d.chats[sid] || []});
			cost.push({name: stores[i].name, values: d.cost[sid] || []});
		}
		// colour per store stays the same on every chart
		for (i = 0; i < stores.length; i++) {
			chats[i].color = cost[i].color = AichatCharts.palette[(d.stores.indexOf(stores[i])) % AichatCharts.palette.length];
		}
		var xl = function (l) {
			return l.substr(8, 2) + '.' + l.substr(5, 2);
		};
		AichatCharts.bars(document.getElementById('chart-chats'), {labels: labels, series: chats, nodata: T.nodata, xlabel: xl});
		AichatCharts.bars(document.getElementById('chart-cost'), {labels: labels, series: cost, nodata: T.nodata, xlabel: xl, fmt: function (v) { return money(v); }});

		var hl = [];
		for (i = 0; i < 24; i++) {
			hl.push((i < 10 ? '0' : '') + i);
		}
		AichatCharts.bars(document.getElementById('chart-hourly'), {labels: hl, series: [{name: T.messages, values: d.hourly, color: AichatCharts.palette[1]}], nodata: T.nodata, height: 180,
			xlabel: function (l) { return +l % 3 === 0 ? l : ''; }});
		AichatCharts.hbars(document.getElementById('chart-products'), d.products, {nodata: T.nodata});
		AichatCharts.hbars(document.getElementById('chart-tools'), d.tools, {nodata: T.nodata, color: AichatCharts.palette[3]});
		AichatCharts.hbars(document.getElementById('chart-topics'), d.topics, {nodata: T.nodata, color: AichatCharts.palette[2], label: function (n) { return T.page_types[n] || n; }});
	}

	function ago(sec) {
		if (sec < 20) {
			return esc(T.now);
		}
		return sec < 90 ? T.ago_s.replace('%d', sec) : T.ago_m.replace('%d', Math.round(sec / 60));
	}

	function path(u) {
		return String(u || '').replace(/^https?:\/\/[^\/]+/i, '') || '/';
	}

	function renderLive(d) {
		var $l = $('#aichat-live');
		if (!d.items.length) {
			$l.html('<div class="aichat-chart-empty">' + esc(T.live_empty) + '</div>');
		} else {
			var h = '';
			$.each(d.items, function (i, it) {
				var badge = it.mode === 'human' ? '<span class="label label-warning">' + esc(T.human) + '</span> ' : it.mode === 'waiting' ? '<span class="label label-danger">' + esc(T.waiting) + '</span> ' : '';
				var page = /^https?:\/\//i.test(it.url) ? '<a href="' + esc(it.url) + '" target="_blank" rel="noopener">' + esc(path(it.url)) + '</a>' : esc(path(it.url));
				h += '<tr><td><span class="aichat-dot' + (it.ago < 60 ? ' is-on' : '') + '"></span></td>' +
					'<td><span class="label label-default">' + esc(it.store) + '</span></td>' +
					'<td class="aichat-cut">' + badge + page + '</td>' +
					'<td class="aichat-cut aichat-muted">' + esc(it.last) + '</td>' +
					'<td class="text-right">' + it.count + '</td>' +
					'<td class="text-right aichat-muted">' + ago(it.ago) + '</td>' +
					'<td class="text-right"><a class="btn btn-default btn-xs" href="' + esc(it.href) + '">' + esc(T.open) + '</a></td></tr>';
			});
			$l.html('<table class="table aichat-live-table"><tbody>' + h + '</tbody></table>');
		}
		$('#aichat-updated').text(T.updated + ' ' + d.time);
	}

	function load(what) {
		if (what !== 'live') {
			$.getJSON(C.stats, params()).done(function (d) {
				if (!d.error) {
					render(d);
				}
			});
		}
		$.getJSON(C.live, {store_id: state.store}).done(function (d) {
			if (!d.error) {
				renderLive(d);
			}
		});
	}

	$(function () {
		C = window.AICHAT_DASH;
		T = C.i18n;
		$('#aichat-store-filter').on('change', function () {
			state.store = $(this).val();
			load();
		});
		$('#aichat-range button').on('click', function () {
			$(this).addClass('active').siblings().removeClass('active');
			state.range = +$(this).data('range');
			load('stats');
		});
		$(window).on('resize', function () {
			if (last) {
				render(last);
			}
		});
		load();
		setInterval(function () {
			if (!document.hidden) {
				load();
			}
		}, 15000);
	});
})(jQuery);
