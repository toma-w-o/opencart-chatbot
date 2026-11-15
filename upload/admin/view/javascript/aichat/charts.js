/* Tiny dependency-free SVG charts for the AI Chat dashboard. */
(function (w) {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var PALETTE = ['#2563eb', '#0d9488', '#d97706', '#9333ea', '#db2777', '#64748b', '#16a34a', '#dc2626'];

	function el(name, attrs, text) {
		var n = document.createElementNS(NS, name);
		for (var k in attrs) {
			if (attrs.hasOwnProperty(k)) {
				n.setAttribute(k, attrs[k]);
			}
		}
		if (text !== undefined) {
			n.textContent = text;
		}
		return n;
	}

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
		});
	}

	function niceMax(v) {
		if (v <= 0) {
			return 1;
		}
		var p = Math.pow(10, Math.floor(Math.log(v) / Math.LN10));
		var f = v / p;
		var n = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10;
		return n * p;
	}

	function fmtNum(v) {
		if (Math.abs(v) >= 1000) {
			return (v / 1000).toFixed(v % 1000 === 0 ? 0 : 1) + 'k';
		}
		return (Math.round(v * 100) / 100).toString();
	}

	function empty(host, msg) {
		host.innerHTML = '<div class="aichat-chart-empty">' + esc(msg) + '</div>';
	}

	/**
	 * Vertical (stacked) bars.
	 * opts: labels[], series[{name,color?,values[]}], fmt(v), height, nodata
	 */
	function bars(host, opts) {
		var series = opts.series || [];
		var labels = opts.labels || [];
		var fmt = opts.fmt || fmtNum;
		var total = 0, max = 0, i, s;
		for (i = 0; i < labels.length; i++) {
			var sum = 0;
			for (s = 0; s < series.length; s++) {
				sum += +series[s].values[i] || 0;
			}
			total += sum;
			max = Math.max(max, sum);
		}
		if (!total) {
			return empty(host, opts.nodata || '-');
		}

		var W = Math.max(280, host.clientWidth || 560), H = opts.height || 210;
		var m = {l: 38, r: 6, t: 8, b: 24};
		var top = niceMax(max), pw = W - m.l - m.r, ph = H - m.t - m.b;
		var svg = el('svg', {viewBox: '0 0 ' + W + ' ' + H, width: '100%', height: H, role: 'img', 'class': 'aichat-svg'});

		for (i = 0; i <= 4; i++) {
			var gy = m.t + ph - ph * i / 4;
			svg.appendChild(el('line', {x1: m.l, x2: W - m.r, y1: gy, y2: gy, 'class': 'aichat-grid'}));
			svg.appendChild(el('text', {x: m.l - 6, y: gy + 3.5, 'text-anchor': 'end', 'class': 'aichat-axis'}, fmt(top * i / 4)));
		}

		var slot = pw / labels.length, bw = Math.min(34, slot * 0.68);
		var every = Math.ceil(labels.length / Math.max(1, Math.floor(pw / 54)));
		for (i = 0; i < labels.length; i++) {
			var x = m.l + slot * i + (slot - bw) / 2, y = m.t + ph, tip = labels[i];
			var g = el('g', {'class': 'aichat-bar'});
			for (s = 0; s < series.length; s++) {
				var v = +series[s].values[i] || 0;
				if (v <= 0) {
					continue;
				}
				var h = Math.max(1.5, ph * v / top);
				y -= h;
				var r = el('rect', {x: x, y: y, width: bw, height: h, rx: 2, fill: series[s].color || PALETTE[s % PALETTE.length]});
				r.appendChild(el('title', {}, labels[i] + ' - ' + series[s].name + ': ' + fmt(v)));
				g.appendChild(r);
			}
			// invisible hit area so the tooltip works on empty days too
			var hit = el('rect', {x: m.l + slot * i, y: m.t, width: slot, height: ph, fill: 'transparent'});
			hit.appendChild(el('title', {}, tip));
			g.appendChild(hit);
			svg.appendChild(g);
			if (i % every === 0 || i === labels.length - 1 && every === 1) {
				svg.appendChild(el('text', {x: x + bw / 2, y: H - 7, 'text-anchor': 'middle', 'class': 'aichat-axis'}, opts.xlabel ? opts.xlabel(labels[i]) : labels[i]));
			}
		}

		host.innerHTML = '';
		host.appendChild(svg);

		if (series.length > 1) {
			var lg = document.createElement('div');
			lg.className = 'aichat-legend';
			for (s = 0; s < series.length; s++) {
				lg.innerHTML += '<span><i style="background:' + (series[s].color || PALETTE[s % PALETTE.length]) + '"></i>' + esc(series[s].name) + '</span>';
			}
			host.appendChild(lg);
		}
	}

	/** Horizontal ranked bars as plain HTML (best for long labels). items[{name,count}] */
	function hbars(host, items, opts) {
		opts = opts || {};
		if (!items || !items.length) {
			return empty(host, opts.nodata || '-');
		}
		var max = 0, i, html = '';
		for (i = 0; i < items.length; i++) {
			max = Math.max(max, items[i].count);
		}
		for (i = 0; i < items.length; i++) {
			var pct = Math.max(2, Math.round(items[i].count * 100 / max));
			html += '<div class="aichat-hrow" title="' + esc(items[i].name) + ': ' + items[i].count + '">' +
				'<span class="aichat-hname">' + esc(opts.label ? opts.label(items[i].name) : items[i].name) + '</span>' +
				'<span class="aichat-htrack"><span class="aichat-hfill" style="width:' + pct + '%;background:' + (opts.color || PALETTE[0]) + '"></span></span>' +
				'<span class="aichat-hval">' + items[i].count + '</span></div>';
		}
		host.innerHTML = html;
	}

	w.AichatCharts = {bars: bars, hbars: hbars, palette: PALETTE, esc: esc};
})(window);
