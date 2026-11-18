/*! AI Chat widget - dependency-free (rounds 1-6). */
(function () {
  'use strict';
  if (window.__aichatLoaded) { return; }
  window.__aichatLoaded = true;

  /* ---------- config / base url ---------- */
  var script = document.currentScript || (function () {
    var s = document.getElementsByTagName('script');
    for (var i = s.length - 1; i >= 0; i--) { if (/aichat\/widget\.js/.test(s[i].src)) { return s[i]; } }
    return null;
  })();
  var scriptSrc = script ? script.src : '';
  var cfg = window.AICHAT_CONFIG || {};
  var siteRoot = (function () {
    var m = scriptSrc.match(/^(.*?)catalog\/view\/javascript\/aichat\//);
    return m ? m[1] : '/';
  })();
  var base = cfg.endpoint || (siteRoot + 'index.php?route=extension/module/aichat/');
  var avatarBase = cfg.avatarBase || (scriptSrc ? scriptSrc.replace(/widget\.js(\?.*)?$/, 'avatars/') : '');
  function endpoint(name, params) {
    var u = base + name;
    var q = [];
    for (var k in params) { if (params[k] !== undefined && params[k] !== null) { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); } }
    return u + (q.length ? (u.indexOf('?') > -1 ? '&' : '?') + q.join('&') : '');
  }
  function siteUrl(route) { return (/index\.php/.test(base) ? base.split('index.php')[0] : '/') + 'index.php?route=' + route; }

  /* ---------- safe storage ---------- */
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* ignore */ } },
    del: function (k) { try { window.localStorage.removeItem(k); } catch (e) { /* ignore */ } }
  };
  var sess = {
    get: function (k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* ignore */ } }
  };
  var K_TOKEN = 'aichat_token', K_OPEN = 'aichat_open', K_DRAFT = 'aichat_draft', K_MUTE = 'aichat_mute', K_INVITE = 'aichat_invite', K_ACCEPT = 'aichat_privacy_ok';

  /* ---------- strings: [en, bg, el] ---------- */
  var S = {
    ai_label: ['AI assistant', 'AI асистент', 'Βοηθός AI'],
    placeholder: ['Type your message…', 'Напишете съобщение…', 'Γράψτε το μήνυμά σας…'],
    send: ['Send', 'Изпрати', 'Αποστολή'],
    open: ['Open chat', 'Отвори чата', 'Άνοιγμα συνομιλίας'],
    close: ['Close chat', 'Затвори чата', 'Κλείσιμο συνομιλίας'],
    emoji: ['Emoji', 'Емоджита', 'Emoji'],
    typing: ['The assistant is typing', 'Асистентът пише', 'Ο βοηθός πληκτρολογεί'],
    error: ['Something went wrong. Please try again.', 'Нещо се обърка. Моля, опитайте отново.', 'Κάτι πήγε στραβά. Δοκιμάστε ξανά.'],
    too_long: ['Message is too long', 'Съобщението е твърде дълго', 'Το μήνυμα είναι πολύ μεγάλο'],
    greeting: ['Hello! How can I help you?', 'Здравейте! С какво мога да помогна?', 'Γεια σας! Πώς μπορώ να βοηθήσω;'],
    messages: ['Conversation', 'Разговор', 'Συνομιλία'],
    view_order: ['View order', 'Виж поръчката', 'Προβολή παραγγελίας'],
    order: ['Order', 'Поръчка', 'Παραγγελία'],
    track: ['Track', 'Проследи', 'Παρακολούθηση'],
    you_may_need: ['You may also need', 'Може да ви потрябва и', 'Ίσως χρειαστείτε και'],
    add_cart: ['Add to cart', 'Добави в количката', 'Προσθήκη στο καλάθι'],
    view_cart: ['View cart', 'Към количката', 'Προβολή καλαθιού'],
    options_required: ['Please choose the required options', 'Изберете задължителните опции', 'Επιλέξτε τις απαραίτητες επιλογές'],
    cancel: ['Cancel', 'Отказ', 'Άκυρο'],
    choose: ['Choose…', 'Изберете…', 'Επιλέξτε…'],
    verifying: ['Quick security check…', 'Кратка проверка за сигурност…', 'Γρήγορος έλεγχος ασφαλείας…'],
    connecting: ['Connecting you to a colleague', 'Свързваме ви с колега', 'Σας συνδέουμε με συνάδελφο'],
    still_checking: ['Still checking, thank you for your patience', 'Още проверяваме, благодарим за търпението', 'Ελέγχουμε ακόμα, ευχαριστούμε για την υπομονή'],
    colleague: ['Colleague', 'Колега', 'Συνάδελφος'],
    attach: ['Attach a photo', 'Прикачи снимка', 'Επισύναψη φωτογραφίας'],
    remove: ['Remove', 'Премахни', 'Αφαίρεση'],
    img_type: ['Only JPG, PNG or WebP photos are accepted', 'Приемат се само JPG, PNG или WebP снимки', 'Επιτρέπονται μόνο JPG, PNG ή WebP'],
    img_big: ['This photo is too large', 'Снимката е твърде голяма', 'Η φωτογραφία είναι πολύ μεγάλη'],
    img_max: ['Up to 3 photos per message', 'До 3 снимки в съобщение', 'Έως 3 φωτογραφίες ανά μήνυμα'],
    img_caption: ['Photo', 'Снимка', 'Φωτογραφία'],
    helpful: ['Helpful', 'Полезен отговор', 'Χρήσιμη απάντηση'],
    not_helpful: ['Not helpful', 'Неполезен отговор', 'Μη χρήσιμη απάντηση'],
    menu: ['Menu', 'Меню', 'Μενού'],
    transcript: ['E-mail me this conversation', 'Изпрати ми разговора по имейл', 'Στείλτε μου τη συνομιλία με email'],
    email_ph: ['your@email.com', 'вашият@имейл.com', 'το@email.σας'],
    forget: ['Delete my conversation', 'Изтрий разговора ми', 'Διαγραφή της συνομιλίας μου'],
    forget_ask: ['Delete the whole conversation?', 'Да изтрия ли целия разговор?', 'Διαγραφή όλης της συνομιλίας;'],
    yes: ['Yes, delete', 'Да, изтрий', 'Ναι, διαγραφή'],
    mute: ['Mute sounds', 'Изключи звука', 'Σίγαση ήχων'],
    unmute: ['Turn sounds on', 'Включи звука', 'Ενεργοποίηση ήχων'],
    sent_ok: ['Sent. Check your inbox.', 'Изпратено. Проверете пощата си.', 'Εστάλη. Ελέγξτε τα εισερχόμενα.'],
    transcript_confirm: ['Send the conversation to %s?', 'Да изпратя ли разговора на %s?', 'Αποστολή της συνομιλίας στο %s;'],
    dismiss: ['Dismiss', 'Затвори', 'Κλείσιμο'],
    privacy: ['Privacy', 'Поверителност', 'Απόρρητο'],
    privacy_link: ['Privacy policy', 'Политика за поверителност', 'Πολιτική απορρήτου'],
    privacy_default: ['This chat is handled by an AI assistant.', 'Разговорът се обработва от AI асистент.', 'Τη συνομιλία χειρίζεται βοηθός AI.'],
    accept: ['I agree', 'Приемам', 'Αποδέχομαι'],
    accept_first: ['Please accept the privacy notice to start the chat.', 'Моля, приемете условията за поверителност, за да започнете.', 'Αποδεχτείτε την ειδοποίηση απορρήτου για να ξεκινήσετε.'],
    masked: ['Please do not send card details, IBAN or personal ID numbers - we have masked them.', 'Моля, не изпращайте данни за карти, IBAN или ЕГН - маскирахме ги.', 'Μη στέλνετε στοιχεία καρτών, IBAN ή ΑΜΚΑ - τα αποκρύψαμε.']
  };
  var lang = (document.documentElement.lang || navigator.language || 'en').toLowerCase().slice(0, 2);
  if (lang === 'gr') { lang = 'el'; }
  var LI = lang === 'bg' ? 1 : lang === 'el' ? 2 : 0;
  function t(key) { return (state.strings && state.strings[key]) || (S[key] && (S[key][LI] || S[key][0])) || key; }

  /* ---------- state ---------- */
  var state = {
    boot: null, strings: {}, token: store.get(K_TOKEN) || '', open: false, busy: false,
    maxLen: 1000, mode: 'bot', avatar: 'default', botName: '', features: {}, appearance: {},
    lastBoot: 0, lastId: 0, wait: null, sent: false, unread: false, staffName: '', pics: [], okReply: false, booting: false,
    privacy: null, accepted: false, privacyClosed: false, pending: null, gated: false, lastUserWrap: null
  };
  var el = {};

  /* ---------- helpers ---------- */
  function h(tag, cls, attrs) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (attrs) { for (var k in attrs) { if (attrs[k] !== null && attrs[k] !== undefined) { n.setAttribute(k, attrs[k]); } } }
    return n;
  }
  function txt(tag, cls, text, attrs) { var n = h(tag, cls, attrs); n.textContent = text; return n; }
  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function mq(q) { return !!(window.matchMedia && window.matchMedia(q).matches); }
  function isFull() { return mq('(max-width: 640px)'); }
  function isMobile() { return mq('(max-width: 768px)'); }
  function num(v, def, min, max) { v = parseInt(v, 10); if (isNaN(v)) { v = def; } return Math.max(min, Math.min(max, v)); }
  function postJson(name, obj) {
    return fetch(endpoint(name), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(obj)
    }).then(function (r) { return r.json(); }).catch(function () { return { error: { message: t('error') } }; });
  }

  /* ---------- colours ---------- */
  function parseHex(c) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(c || '').trim());
    if (!m) { return null; }
    var x = m[1]; if (x.length === 3) { x = x.replace(/./g, '$&$&'); }
    return [parseInt(x.substr(0, 2), 16), parseInt(x.substr(2, 2), 16), parseInt(x.substr(4, 2), 16)];
  }
  function toHex(r) { return '#' + r.map(function (v) { v = Math.max(0, Math.min(255, Math.round(v))); return (v < 16 ? '0' : '') + v.toString(16); }).join(''); }
  function lum(r) {
    var a = r.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  }
  function contrastOf(r) { return lum(r) > 0.179 ? '#1b1f2a' : '#ffffff'; }
  function mix(r, to, amt) { return [r[0] + (to[0] - r[0]) * amt, r[1] + (to[1] - r[1]) * amt, r[2] + (to[2] - r[2]) * amt]; }
  function hoverOf(r) { return toHex(lum(r) < 0.06 ? mix(r, [255, 255, 255], 0.18) : mix(r, [0, 0, 0], 0.14)); }

  /* ---------- scoped custom css ---------- */
  function scopeCss(css) {
    css = String(css || '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/@import[^;]*;/gi, '').replace(/<\/?style[^>]*>/gi, '');
    function sel(s) {
      return s.split(',').map(function (p) {
        p = p.trim(); if (!p) { return ''; }
        p = p.replace(/^(:root|html|body)(?![\w-])/, '.aichat-root');
        return /^\.aichat-root/.test(p) ? p : '.aichat-root ' + p;
      }).filter(Boolean).join(', ');
    }
    function parse(str) {
      var res = '', pos = 0;
      while (pos < str.length) {
        var open = str.indexOf('{', pos);
        if (open < 0) { break; }
        var head = str.slice(pos, open).trim(), depth = 1, j = open + 1;
        while (j < str.length && depth > 0) { var ch = str.charAt(j); if (ch === '{') { depth++; } else if (ch === '}') { depth--; } j++; }
        var body = str.slice(open + 1, j - 1); pos = j;
        if (head.charAt(0) === '@') {
          if (/^@(media|supports)/i.test(head)) { res += head + '{' + parse(body) + '}'; }
          else if (/^@(-webkit-)?keyframes/i.test(head)) { res += head + '{' + body + '}'; }
        } else { var s = sel(head); if (s) { res += s + '{' + body + '}'; } }
      }
      return res;
    }
    return parse(css);
  }

  /* ---------- appearance + position ---------- */
  function applyAppearance() {
    var a = state.appearance || {}, root = el.root;
    if (!root) { return; }
    var acc = parseHex(a.accent), css = '.aichat-root{';
    if (acc) {
      css += '--aichat-accent:' + toHex(acc) + ';--aichat-accent-text:' + contrastOf(acc) + ';--aichat-accent-hover:' + hoverOf(acc) + ';';
    } else { css += '--aichat-accent-hover:#4338ca;'; }
    var hb = parseHex(a.header_bg) || acc;
    if (hb) { css += '--aichat-head-bg:' + toHex(hb) + ';--aichat-head-text:' + (parseHex(a.header_text) ? toHex(parseHex(a.header_text)) : contrastOf(hb)) + ';'; }
    else if (parseHex(a.header_text)) { css += '--aichat-head-text:' + toHex(parseHex(a.header_text)) + ';'; }
    var ub = parseHex(a.user_bg) || acc;
    if (ub) { css += '--aichat-user-bg:' + toHex(ub) + ';--aichat-user-text:' + (parseHex(a.user_text) ? toHex(parseHex(a.user_text)) : contrastOf(ub)) + ';'; }
    else if (parseHex(a.user_text)) { css += '--aichat-user-text:' + toHex(parseHex(a.user_text)) + ';'; }
    var z = parseInt(a.z_index, 10);
    if (z > 0) { css += 'z-index:' + Math.min(z, 2147483647) + ';'; }
    css += '}' + scopeCss(a.custom_css);
    if (!el.style) { el.style = h('style', null, { 'data-aichat-appearance': '1' }); root.appendChild(el.style); }
    el.style.textContent = css;

    // launcher
    var L = a.launcher || {}, style = L.style === 'avatar_text' ? 'avatar_text' : 'avatar', shape = /^(rounded|pill)$/.test(L.shape) ? L.shape : 'circle';
    var size = num(L.size, 56, 40, 96), av = parseInt(L.avatar_size, 10);
    var text = style === 'avatar_text' ? String(L.text || state.botName || '') : '';
    if (style === 'avatar_text' && !text) { style = 'avatar'; }
    if (!av || av < 1) { av = style === 'avatar' ? Math.round(size * 45 / 56) : Math.round(size * 0.58); }
    av = Math.max(16, Math.min(av, size - 4));
    var radius = style === 'avatar_text' ? (shape === 'rounded' ? Math.round(size * 0.3) : Math.round(size / 2)) : (shape === 'circle' || shape === 'pill' ? Math.round(size / 2) : Math.round(size * 0.3));
    root.style.setProperty('--ac-size', size + 'px');
    root.style.setProperty('--ac-av', av + 'px');
    root.style.setProperty('--ac-radius', radius + 'px');
    el.launcher.setAttribute('data-style', style);
    el.launcher.setAttribute('data-shape', shape);
    el.launcherText.textContent = text;
    el.launcherText.hidden = !text;
    applyPosition();
    attnSchedule();
  }
  function applyPosition() {
    if (!el.root) { return; }
    var pos = (state.appearance && state.appearance.position) || {}, p = pos[isMobile() ? 'mobile' : 'desktop'] || {};
    el.root.setAttribute('data-side', p.side === 'left' ? 'left' : 'right');
    el.root.style.setProperty('--ac-x', num(p.x, 20, 0, 600) + 'px');
    el.root.style.setProperty('--ac-y', num(p.y, 20, 0, 600) + 'px');
  }

  /* ---------- safe text rendering ---------- */
  function bareHost(hn) { return String(hn || '').toLowerCase().replace(/^www\./, ''); }
  function safeHref(url) {
    try {
      var u = new URL(url, location.href);
      if ((u.protocol === 'http:' || u.protocol === 'https:') && bareHost(u.host) === bareHost(location.host)) { return u.href; }
    } catch (e) { /* ignore */ }
    return null;
  }
  function externalHref(url) { // tracking links only
    try { var u = new URL(url, location.href); if (u.protocol === 'http:' || u.protocol === 'https:') { return u.href; } } catch (e) { /* ignore */ }
    return null;
  }
  var INLINE = /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|\*\*([^*\n]+?)\*\*|(https?:\/\/[^\s<>()]+[^\s<>().,;:!?'"])/g;
  function inline(text) {
    var out = '', last = 0, m;
    INLINE.lastIndex = 0;
    while ((m = INLINE.exec(text)) !== null) {
      out += esc(text.slice(last, m.index));
      last = m.index + m[0].length;
      if (m[1] !== undefined) {
        var href = safeHref(m[2]);
        out += href ? '<a href="' + esc(href) + '" class="aichat-link">' + esc(m[1]) + '</a>' : esc(m[1]);
      } else if (m[3] !== undefined) {
        out += '<strong>' + esc(m[3]) + '</strong>';
      } else {
        var h2 = safeHref(m[4]);
        out += h2 ? '<a href="' + esc(h2) + '" class="aichat-link">' + esc(m[4]) + '</a>' : esc(m[4]);
      }
    }
    return out + esc(text.slice(last));
  }
  function render(text) {
    var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
    var html = '', para = [], list = null;
    function flushPara() { if (para.length) { html += '<p>' + para.map(inline).join('<br>') + '</p>'; para = []; } }
    function flushList() { if (list) { html += '<' + list.t + '>' + list.items.map(function (i) { return '<li>' + inline(i) + '</li>'; }).join('') + '</' + list.t + '>'; list = null; } }
    lines.forEach(function (line) {
      var ul = line.match(/^\s*[-*•]\s+(.*)$/), ol = line.match(/^\s*\d+[.)]\s+(.*)$/);
      if (ul || ol) {
        flushPara();
        var tt = ul ? 'ul' : 'ol';
        if (list && list.t !== tt) { flushList(); }
        if (!list) { list = { t: tt, items: [] }; }
        list.items.push((ul || ol)[1]);
      } else if (/^\s*$/.test(line)) { flushPara(); flushList(); }
      else { flushList(); para.push(line); }
    });
    flushPara(); flushList();
    return html;
  }

  /* ---------- avatar library ---------- */
  var avCache = {}, avs = [];
  function renderAvatar(name, st) { // built-in robot: fallback while loading / when the file is missing
    var n = h('span', 'aichat-av', { 'data-avatar': name || 'default', 'data-state': st || 'idle', 'aria-hidden': 'true' });
    n.innerHTML =
      '<svg viewBox="0 0 64 64" focusable="false">' +
      '<line class="aichat-av-ant" x1="32" y1="7" x2="32" y2="14" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>' +
      '<circle class="aichat-av-bulb" cx="32" cy="6" r="3.5"/>' +
      '<rect class="aichat-av-head" x="8" y="14" width="48" height="40" rx="16"/>' +
      '<rect class="aichat-av-face" x="13" y="21" width="38" height="27" rx="11"/>' +
      '<g class="aichat-av-eyes"><ellipse class="aichat-av-eye aichat-av-eye-l" cx="24" cy="32" rx="4" ry="5"/>' +
      '<ellipse class="aichat-av-eye aichat-av-eye-r" cx="40" cy="32" rx="4" ry="5"/></g>' +
      '<rect class="aichat-av-mouth" x="26" y="41" width="12" height="4" rx="2"/>' +
      '<circle class="aichat-av-cheek" cx="17" cy="41" r="2.5"/><circle class="aichat-av-cheek" cx="47" cy="41" r="2.5"/>' +
      '</svg>';
    return n;
  }
  var DEFAULT_AV = 'nova';
  function loadAvatarSvg(id) {
    if (!id || id === 'default' || !/^[a-z0-9_-]+$/i.test(id)) { id = DEFAULT_AV; }
    if (!avatarBase || !window.fetch) { return Promise.reject(); }
    if (!avCache[id]) {
      avCache[id] = fetch(avatarBase + id + '.svg', { credentials: 'omit' }).then(function (r) {
        if (!r.ok) { throw new Error('av'); }
        return r.text();
      }).then(function (s) {
        s = s.replace(/^\s*<\?xml[^>]*>\s*/, '');
        if (!/^<svg[\s>]/i.test(s) || /<script|\son\w+\s*=/i.test(s)) { throw new Error('av'); }
        return s;
      });
      avCache[id].catch(function () { avCache[id] = null; });
    }
    return avCache[id];
  }
  function paintAvatarState(n, st) {
    n.setAttribute('data-state', st);
    if (n.hasAttribute('data-loaded') && n.firstElementChild) {
      var svg = n.firstElementChild, c = (svg.getAttribute('class') || '').replace(/\bis-[a-z0-9-]+/g, '').replace(/\s+/g, ' ').trim();
      svg.setAttribute('class', c + ' is-' + st);
    }
  }
  function liveAvatar(name) {
    var n = renderAvatar(name, 'idle');
    avs.push(n);
    n.setAttribute('data-live', '1');
    setAvatarOf(n, name);
    return n;
  }
  function setAvatarOf(n, name) {
    n.setAttribute('data-avatar', name || 'default');
    loadAvatarSvg(name).catch(function () { return name === DEFAULT_AV || !name || name === 'default' ? Promise.reject() : loadAvatarSvg(DEFAULT_AV); }).then(function (s) {
      if (n.getAttribute('data-avatar') !== (name || 'default')) { return; }
      var st = n.getAttribute('data-state') || 'idle';
      n.innerHTML = s; n.setAttribute('data-loaded', '1'); paintAvatarState(n, st);
    }, function () { /* keep built-in robot */ });
  }
  function setAvatarState(st) {
    state.avState = st;
    for (var i = 0; i < avs.length; i++) { paintAvatarState(avs[i], st); }
  }
  /* attention gestures: a random short grimace on the launcher avatar while the panel is closed */
  var attn = { timer: null, clear: null, last: 0, node: null };
  function attnCfg() {
    var a = (state.appearance && state.appearance.attention) || {};
    return { enabled: !!a.enabled && a.enabled !== '0', interval: num(a.interval, 5, 3, 120) };
  }
  function attnEnd() {
    clearTimeout(attn.clear); attn.clear = null;
    if (attn.node) {
      attn.node.setAttribute('class', (attn.node.getAttribute('class') || '').replace(/\s*\bis-attn-\d+/g, ''));
      attn.node = null;
    }
  }
  function attnStop() { clearTimeout(attn.timer); attn.timer = null; attnEnd(); }
  function attnCan() {
    return !state.open && !document.hidden && !mq('(prefers-reduced-motion: reduce)') && el.launcher && attnCfg().enabled;
  }
  function attnSchedule() {
    attnStop();
    if (!attnCan()) { return; }
    attn.timer = setTimeout(attnFire, attnCfg().interval * 1000);
  }
  function attnFire() {
    attn.timer = null;
    if (!attnCan()) { return; }
    attn.timer = setTimeout(attnFire, attnCfg().interval * 1000); // start-to-start interval
    var n = el.launcher.querySelector('.aichat-av'), svg = n && n.hasAttribute('data-loaded') ? n.firstElementChild : null;
    var cnt = svg ? parseInt(svg.getAttribute('data-gestures'), 10) || 0 : 0;
    if (!cnt || state.busy || state.avState && state.avState !== 'idle') { return; }
    var k;
    do { k = 1 + Math.floor(Math.random() * cnt); } while (cnt > 1 && k === attn.last);
    attn.last = k;
    attnEnd();
    svg.setAttribute('class', (svg.getAttribute('class') || '') + ' is-attn-' + k);
    attn.node = svg;
    attn.clear = setTimeout(attnEnd, 2600);
  }
  var listenTimer = null;
  function listening() {
    if (state.busy) { return; }
    setAvatarState('listening');
    clearTimeout(listenTimer);
    listenTimer = setTimeout(function () { if (!state.busy && state.avState === 'listening') { setAvatarState('idle'); } }, 2200);
  }

  /* ---------- sound / unread ---------- */
  var audio = { ctx: null };
  function unlockAudio() {
    try {
      if (!audio.ctx) { var AC = window.AudioContext || window.webkitAudioContext; if (AC) { audio.ctx = new AC(); } }
      if (audio.ctx && audio.ctx.state === 'suspended') { audio.ctx.resume(); }
    } catch (e) { /* ignore */ }
  }
  function soundOn() { return !!(state.boot && state.boot.sound) && store.get(K_MUTE) !== '1'; }
  function ding() {
    if (!soundOn() || !audio.ctx) { return; }
    try {
      var c = audio.ctx, t0 = c.currentTime;
      [[880, 0], [1318, 0.12]].forEach(function (n) {
        var o = c.createOscillator(), g = c.createGain(), s = t0 + n[1];
        o.type = 'sine'; o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, s); g.gain.exponentialRampToValueAtTime(0.1, s + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, s + 0.3);
        o.connect(g); g.connect(c.destination); o.start(s); o.stop(s + 0.32);
      });
    } catch (e) { /* ignore */ }
  }
  function setUnread(on) {
    state.unread = on;
    if (el.unread) { el.unread.hidden = !on; }
  }
  function notifyIncoming() {
    if (!state.open) { setUnread(true); hideInvite(); }
    if (!state.open || document.hidden) { ding(); }
  }

  /* ---------- proactive invite ---------- */
  var inviteTimer = null;
  function hideInvite() { if (el.invite) { el.invite.hidden = true; } clearTimeout(inviteTimer); }
  function scheduleInvite() {
    clearTimeout(inviteTimer);
    var p = state.boot && state.boot.proactive;
    if (!p || !p.enabled || !p.text || sess.get(K_INVITE) || state.open || state.sent) { return; }
    inviteTimer = setTimeout(function () {
      if (state.open || state.sent || sess.get(K_INVITE)) { return; }
      el.inviteText.textContent = p.text; el.invite.hidden = false; sess.set(K_INVITE, '1');
    }, Math.max(1, num(p.delay, 20, 0, 3600)) * 1000);
  }

  /* ---------- emoji ---------- */
  var EMOJI = [
    ['😀', '😀 😃 😄 😁 😆 😅 😂 🙂 😉 😊 😇 😍 😘 😋 😎 🤗 🤔 😐 😴 😢 😭 😡 😱 🙄'],
    ['👍', '👍 👎 👌 ✌️ 🤞 👏 🙌 🙏 💪 👋 🤝 ☝️ 👉 👈'],
    ['❤️', '❤️ 🧡 💛 💚 💙 💜 🖤 💔 💕 ✨ ⭐ 🔥 💯 ✅ ❌ ❓ ❗ 🎉 🎁 🌟'],
    ['🛒', '🛒 🛍️ 📦 🚚 💳 💰 🏷️ 📞 ✉️ 🕐 🏠 🎈 🧸 🚲 ⚽ 🎮 📚 🎨']
  ].map(function (g) { return { icon: g[0], items: g[1].split(' ') }; });

  /* ---------- DOM ---------- */
  var ICON = {
    close: '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none"/>',
    dots: '<circle cx="5" cy="12" r="2" fill="currentColor"/><circle cx="12" cy="12" r="2" fill="currentColor"/><circle cx="19" cy="12" r="2" fill="currentColor"/>',
    send: '<path d="M3.4 20.4l17.4-8.4L3.4 3.6 3.3 10l12 2-12 2z" fill="currentColor"/>',
    clip: '<path d="M16.5 6.5l-8 8a2.5 2.5 0 003.5 3.5l8.5-8.5a4.5 4.5 0 00-6.4-6.4L5.5 11.7a6.5 6.5 0 009.2 9.2l5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    up: '<path d="M7 11v9H4v-9h3zm2 9V11l4-7c1.5 0 2.5 1.2 2.2 2.7L14.8 10H19a2 2 0 012 2.3l-1.2 6.5A2 2 0 0117.8 20H9z" fill="currentColor"/>',
    down: '<path d="M7 13V4H4v9h3zm2-9v9l4 7c1.5 0 2.5-1.2 2.2-2.7L14.8 14H19a2 2 0 002-2.3l-1.2-6.5A2 2 0 0017.8 4H9z" fill="currentColor"/>',
    chev: '<path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
    category: '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" fill="currentColor"/>',
    manufacturer: '<path d="M4 20V9l8-5 8 5v11h-5v-6H9v6z" fill="currentColor"/>',
    information: '<path d="M6 3h9l4 4v14H6z" fill="currentColor"/><path d="M9 11h7M9 15h7" stroke="#fff" stroke-width="1.6"/>',
    special: '<path d="M3 12l9-9h8v8l-9 9z" fill="currentColor"/><circle cx="16" cy="8" r="1.7" fill="#fff"/>',
    contact: '<path d="M4 5h16a1 1 0 011 1v10a1 1 0 01-1 1h-8l-5 4v-4H4a1 1 0 01-1-1V6a1 1 0 011-1z" fill="currentColor"/>',
    order: '<path d="M3 7l9-4 9 4v10l-9 4-9-4z" fill="currentColor"/><path d="M3 7l9 4 9-4M12 11v10" stroke="#fff" stroke-width="1.4" fill="none"/>',
    tracking: '<path d="M2 6h12v9H2zM14 9h4l3 3v3h-7z" fill="currentColor"/><circle cx="7" cy="17" r="2" fill="currentColor" stroke="#fff" stroke-width="1.2"/><circle cx="17" cy="17" r="2" fill="currentColor" stroke="#fff" stroke-width="1.2"/>'
  };
  function svgi(name, cls) { var s = h('span', cls || 'aichat-ico'); s.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (ICON[name] || ICON.chev) + '</svg>'; return s; }
  function iconBtn(cls, label, icon) {
    var b = h('button', cls, { type: 'button', 'aria-label': label, title: label });
    b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + ICON[icon] + '</svg>';
    return b;
  }

  function build() {
    var root = h('div', 'aichat-root', { id: 'aichat-root', 'data-side': 'right' });
    el.root = root;

    el.launcher = h('button', 'aichat-launcher', { type: 'button', 'aria-label': t('open'), 'aria-expanded': 'false', 'aria-controls': 'aichat-panel', 'data-style': 'avatar' });
    el.launcher.appendChild(liveAvatar(state.avatar));
    el.launcherText = h('span', 'aichat-launcher-text'); el.launcherText.hidden = true;
    el.launcher.appendChild(el.launcherText);
    el.unread = h('span', 'aichat-unread', { 'aria-hidden': 'true' }); el.unread.hidden = true;
    el.launcher.appendChild(el.unread);
    el.launcher.addEventListener('click', function () { setOpen(true); });

    el.invite = h('div', 'aichat-invite', { role: 'status' }); el.invite.hidden = true;
    el.inviteText = h('button', 'aichat-invite-text', { type: 'button' });
    el.inviteText.addEventListener('click', function () { hideInvite(); setOpen(true); });
    var ic = iconBtn('aichat-invite-x', t('dismiss'), 'close');
    ic.addEventListener('click', function () { hideInvite(); });
    el.invite.appendChild(el.inviteText); el.invite.appendChild(ic);

    var panel = h('div', 'aichat-panel', { id: 'aichat-panel', role: 'dialog', 'aria-label': state.botName + ' - ' + t('ai_label'), hidden: '' });
    el.panel = panel;

    var head = h('div', 'aichat-head');
    head.appendChild(liveAvatar(state.avatar));
    var titles = h('div', 'aichat-titles');
    el.name = txt('div', 'aichat-name', state.botName);
    el.sub = txt('div', 'aichat-sub', t('ai_label'));
    titles.appendChild(el.name); titles.appendChild(el.sub);
    head.appendChild(titles);
    el.menuBtn = iconBtn('aichat-hbtn', t('menu'), 'dots');
    el.menuBtn.setAttribute('aria-expanded', 'false');
    el.menuBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(); });
    head.appendChild(el.menuBtn);
    el.close = iconBtn('aichat-hbtn aichat-close', t('close'), 'close');
    el.close.addEventListener('click', function () { setOpen(false); });
    head.appendChild(el.close);
    panel.appendChild(head);
    buildMenu(head);

    el.list = h('div', 'aichat-list', { role: 'log', 'aria-live': 'polite', 'aria-label': t('messages'), tabindex: '0' });
    panel.appendChild(el.list);

    el.ts = h('div', 'aichat-ts'); el.ts.hidden = true;
    el.ts.appendChild(txt('div', 'aichat-ts-note', t('verifying')));
    el.tsBox = h('div', 'aichat-ts-box'); el.ts.appendChild(el.tsBox);
    panel.appendChild(el.ts);

    var foot = h('div', 'aichat-foot');
    el.emojiPop = h('div', 'aichat-emoji', { role: 'group', 'aria-label': t('emoji'), hidden: '' });
    buildEmoji();
    foot.appendChild(el.emojiPop);

    el.priv = h('div', 'aichat-privacy', { role: 'note' }); el.priv.hidden = true;
    foot.appendChild(el.priv);
    el.thumbErr = h('div', 'aichat-thumb-err', { role: 'alert' }); el.thumbErr.hidden = true;
    foot.appendChild(el.thumbErr);
    el.thumbs = h('div', 'aichat-thumbs'); el.thumbs.hidden = true;
    foot.appendChild(el.thumbs);

    var row = h('div', 'aichat-row');
    el.emojiBtn = h('button', 'aichat-iconbtn', { type: 'button', 'aria-label': t('emoji'), 'aria-expanded': 'false' });
    el.emojiBtn.textContent = '🙂';
    el.emojiBtn.addEventListener('click', function () { toggleEmoji(); });
    el.attachBtn = iconBtn('aichat-iconbtn aichat-attach', t('attach'), 'clip'); el.attachBtn.hidden = true;
    el.file = h('input', 'aichat-file', { type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: '', tabindex: '-1', 'aria-hidden': 'true' });
    el.attachBtn.addEventListener('click', function () { el.file.click(); });
    el.file.addEventListener('change', function () { addFiles(el.file.files); el.file.value = ''; });
    el.input = h('textarea', 'aichat-input', { rows: '1', placeholder: t('placeholder'), 'aria-label': t('placeholder'), maxlength: String(state.maxLen), enterkeyhint: 'send' });
    el.input.addEventListener('input', onInput);
    el.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); }
    });
    el.input.addEventListener('paste', function (e) {
      var items = e.clipboardData && e.clipboardData.files;
      if (items && items.length && state.features.images) { e.preventDefault(); addFiles(items); }
    });
    el.sendBtn = iconBtn('aichat-send', t('send'), 'send');
    el.sendBtn.addEventListener('click', function () { submit(); });
    row.appendChild(el.emojiBtn); row.appendChild(el.attachBtn); row.appendChild(el.input); row.appendChild(el.sendBtn);
    row.appendChild(el.file);
    foot.appendChild(row);
    el.count = h('div', 'aichat-count', { 'aria-live': 'polite' });
    foot.appendChild(el.count);
    panel.appendChild(foot);

    el.drop = h('div', 'aichat-dropzone'); el.drop.hidden = true;
    el.drop.appendChild(txt('div', null, t('attach')));
    panel.appendChild(el.drop);
    var dragDepth = 0;
    panel.addEventListener('dragenter', function (e) { if (state.features.images && hasFiles(e)) { e.preventDefault(); dragDepth++; el.drop.hidden = false; } });
    panel.addEventListener('dragover', function (e) { if (state.features.images && hasFiles(e)) { e.preventDefault(); } });
    panel.addEventListener('dragleave', function () { dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) { el.drop.hidden = true; } });
    panel.addEventListener('drop', function (e) {
      dragDepth = 0; el.drop.hidden = true;
      if (state.features.images && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); }
    });

    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (!el.menu.hidden) { toggleMenu(false); el.menuBtn.focus(); }
        else if (!el.emojiPop.hidden) { toggleEmoji(false); el.emojiBtn.focus(); } else { setOpen(false); }
      }
    });
    panel.addEventListener('click', function (e) { if (!el.menu.hidden && !el.menu.contains(e.target)) { toggleMenu(false); } });

    root.appendChild(panel);
    root.appendChild(el.invite);
    root.appendChild(el.launcher);
    document.body.appendChild(root);

    var draft = store.get(K_DRAFT);
    if (draft) { el.input.value = draft; onInput(); }

    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', fitViewport);
      window.visualViewport.addEventListener('scroll', fitViewport);
    }
    window.addEventListener('resize', function () { fitViewport(); applyPosition(); });
    document.addEventListener('pointerdown', unlockAudio, { once: true, passive: true });
    document.addEventListener('keydown', unlockAudio, { once: true, passive: true });
  }
  function hasFiles(e) { var t0 = e.dataTransfer && e.dataTransfer.types; return !!(t0 && (t0.indexOf ? t0.indexOf('Files') > -1 : t0.contains && t0.contains('Files'))); }

  function fitViewport() {
    if (!el.root) { return; }
    var vv = window.visualViewport;
    if (vv && state.open && isFull()) {
      el.root.style.setProperty('--aichat-vh', vv.height + 'px');
      el.root.style.setProperty('--aichat-vt', vv.offsetTop + 'px');
    } else {
      el.root.style.removeProperty('--aichat-vh');
      el.root.style.removeProperty('--aichat-vt');
    }
  }

  function buildEmoji() {
    var tabs = h('div', 'aichat-emoji-tabs', { role: 'tablist' });
    var grid = h('div', 'aichat-emoji-grid');
    function show(i) {
      var tb = tabs.children;
      for (var k = 0; k < tb.length; k++) { tb[k].setAttribute('aria-selected', k === i ? 'true' : 'false'); }
      grid.innerHTML = '';
      EMOJI[i].items.forEach(function (em) {
        var b = h('button', 'aichat-emoji-item', { type: 'button', 'aria-label': em });
        b.textContent = em;
        b.addEventListener('click', function () { insertAtCaret(em); });
        grid.appendChild(b);
      });
    }
    EMOJI.forEach(function (g, i) {
      var b = h('button', 'aichat-emoji-tab', { type: 'button', role: 'tab', 'aria-label': g.icon });
      b.textContent = g.icon;
      b.addEventListener('click', function () { show(i); });
      tabs.appendChild(b);
    });
    el.emojiPop.appendChild(tabs);
    el.emojiPop.appendChild(grid);
    show(0);
  }
  function toggleEmoji(force) {
    var open = typeof force === 'boolean' ? force : el.emojiPop.hidden;
    el.emojiPop.hidden = !open;
    el.emojiBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function insertAtCaret(text) {
    var i = el.input, s = i.selectionStart || 0, e = i.selectionEnd || 0;
    if (i.value.length - (e - s) + text.length > state.maxLen) { return; }
    i.value = i.value.slice(0, s) + text + i.value.slice(e);
    i.selectionStart = i.selectionEnd = s + text.length;
    i.focus();
    onInput();
  }

  function onInput() {
    var i = el.input;
    i.style.height = 'auto';
    i.style.height = Math.min(i.scrollHeight, 120) + 'px';
    var len = i.value.length;
    el.count.textContent = len > state.maxLen * 0.8 ? len + ' / ' + state.maxLen : '';
    store.set(K_DRAFT, i.value);
    if (i.value) { listening(); }
    updateSend();
  }
  function uploading() { for (var i = 0; i < state.pics.length; i++) { if (state.pics[i].status === 'up') { return true; } } return false; }
  function readyPics() { return state.pics.filter(function (p) { return p.status === 'ok'; }); }
  function updateSend() {
    var locked = needAccept();
    el.sendBtn.disabled = locked || state.busy || uploading() || (!el.input.value.trim() && !readyPics().length);
    if (el.input.disabled !== locked) { el.input.disabled = locked; el.input.setAttribute('aria-disabled', locked ? 'true' : 'false'); }
    el.root.classList.toggle('aichat-locked', locked);
  }

  /* ---------- privacy notice (round 6) ---------- */
  // boot.privacy = {text, url, require_accept}; the notice shows above the input on the first open of a
  // conversation, and from the "..." menu afterwards. With require_accept the input stays locked until "I agree".
  function privacyOf(b) {
    var p = b && b.privacy;
    if (!p || typeof p !== 'object') { return null; }
    var text = typeof p.text === 'string' ? p.text.trim() : '';
    if (!text && !p.require_accept && !p.url) { return null; }
    return { text: text || t('privacy_default'), url: p.url ? externalHref(String(p.url)) : null, require: !!p.require_accept, accepted: !!p.accepted };
  }
  var POLICY_RE = /(политика\S*\s+за\s+поверителност|поверителност|privacy\s+policy|privacy\s+notice|πολιτικ\S*\s+απορρήτου|απόρρητο\S*)/i;
  function needAccept() { return !!(state.privacy && state.privacy.require && !state.accepted); }
  function privacyNodes(box, withAccept, withClose) {
    box.innerHTML = '';
    var p = state.privacy;
    var body = h('div', 'aichat-privacy-text');
    // the policy named in the text becomes the link; otherwise the link is added after the text
    var m = p.url ? POLICY_RE.exec(p.text) : null;
    if (m) {
      body.appendChild(document.createTextNode(p.text.slice(0, m.index)));
      body.appendChild(txt('a', 'aichat-privacy-link', m[0], { href: p.url, target: '_blank', rel: 'noopener' }));
      body.appendChild(document.createTextNode(p.text.slice(m.index + m[0].length)));
    } else {
      body.appendChild(document.createTextNode(p.text + ' '));
      if (p.url) { body.appendChild(txt('a', 'aichat-privacy-link', t('privacy_link'), { href: p.url, target: '_blank', rel: 'noopener' })); }
    }
    box.appendChild(body);
    if (withAccept) {
      var ok = txt('button', 'aichat-mbtn aichat-privacy-accept', t('accept'), { type: 'button' });
      ok.addEventListener('click', acceptPrivacy);
      box.appendChild(ok);
    } else if (withClose) {
      var x = iconBtn('aichat-privacy-x', t('dismiss'), 'close');
      x.addEventListener('click', function () { state.privacyClosed = true; el.priv.hidden = true; });
      box.appendChild(x);
    }
  }
  function showPrivacy(force) {
    if (!state.privacy) { el.priv.hidden = true; updateSend(); return; }
    var lock = needAccept();
    var show = lock || force || (!state.privacyClosed && !state.sent && !el.list.querySelector('.aichat-msg--user'));
    if (show) { privacyNodes(el.priv, lock, !lock); }
    el.priv.hidden = !show;
    updateSend();
  }
  function acceptPrivacy() {
    state.accepted = true;
    if (state.token) { store.set(K_ACCEPT, state.token); }
    state.privacyClosed = true;
    el.priv.hidden = true;
    updateSend();
    if (el.mSub && !el.mSub.hidden) { toggleMenu(false); }
    var p = state.pending; state.pending = null;
    if (p) { dispatch(p.text, p.pics, p.opts); } else { setTimeout(function () { el.input.focus({ preventScroll: true }); }, 30); }
  }
  function showNotice(n) {
    var text = typeof n === 'string' ? n : (n && (n.message || n.text)) || t('masked');
    var node = txt('div', 'aichat-sys aichat-sys--warn', text, { role: 'status' });
    var after = state.lastUserWrap;
    // the server stored and sent the masked text: show the visitor that version too
    if (after && n && typeof n.text === 'string' && n.text) { var bb = after.querySelector('.aichat-bubble'); if (bb) { bb.textContent = n.text; } }
    if (after && after.parentNode === el.list) { el.list.insertBefore(node, after.nextSibling); } else { appendToList(node); }
    scrollDown(false);
  }

  /* ---------- consent gate (round 6, s_wait_consent) ---------- */
  // The shop's cookie module calls: window.AICHAT_CONSENT = true; window.dispatchEvent(new Event('aichat:consent'));
  var consent = { given: false, waiters: [] };
  function consented() { return consent.given || window.AICHAT_CONSENT === true; }
  function onConsent() {
    consent.given = true;
    var w = consent.waiters; consent.waiters = [];
    w.forEach(function (fn) { fn(); });
  }
  window.addEventListener('aichat:consent', onConsent);
  document.addEventListener('aichat:consent', onConsent);
  function whenConsented(fn) {
    if (consented()) { fn(); return; }
    consent.waiters.push(fn);
    if (!consent.timer) { // a cookie module may only set the flag: notice it without the event too
      consent.timer = setInterval(function () { if (window.AICHAT_CONSENT === true) { clearInterval(consent.timer); consent.timer = null; onConsent(); } }, 1000);
    }
  }
  function gateWanted(b) {
    if (b) { return !!((b.privacy && b.privacy.wait_consent) || b.wait_consent); }
    return !!(cfg.wait_consent || cfg.waitConsent || window.AICHAT_WAIT_CONSENT);
  }

  /* ---------- header menu ---------- */
  function buildMenu(head) {
    el.menu = h('div', 'aichat-menu', { role: 'menu' }); el.menu.hidden = true;
    el.mTranscript = txt('button', 'aichat-mitem', t('transcript'), { type: 'button', role: 'menuitem' });
    el.mForget = txt('button', 'aichat-mitem', t('forget'), { type: 'button', role: 'menuitem' });
    el.mMute = h('button', 'aichat-mitem', { type: 'button', role: 'menuitem' });
    el.mPrivacy = txt('button', 'aichat-mitem', t('privacy'), { type: 'button', role: 'menuitem' });
    el.mSub = h('div', 'aichat-msub'); el.mSub.hidden = true;
    el.mPrivacy.addEventListener('click', function () {
      if (!state.privacy) { return; }
      el.mSub.innerHTML = ''; el.mSub.hidden = false;
      var box = h('div', 'aichat-mmsg aichat-privacy-menu');
      privacyNodes(box, needAccept(), false);
      el.mSub.appendChild(box);
    });
    el.mTranscript.addEventListener('click', function () {
      // the server decides the address (account e-mail or the e-mail of an order checked in this chat)
      el.mSub.innerHTML = ''; el.mSub.hidden = false;
      var msg = h('div', 'aichat-mmsg', { role: 'status' });
      el.mSub.appendChild(msg);
      postJson('transcript', { token: state.token, check: 1 }).then(function (j) {
        if (!j || j.error || !j.available) { msg.textContent = (j && j.error && j.error.message) || (j && j.message) || t('error'); return; }
        msg.textContent = t('transcript_confirm').replace('%s', String(j.masked || ''));
        var go = txt('button', 'aichat-mbtn', t('send'), { type: 'button' });
        var no = txt('button', 'aichat-mbtn aichat-mghost', t('cancel'), { type: 'button' });
        no.addEventListener('click', function () { el.mSub.hidden = true; });
        go.addEventListener('click', function () {
          go.disabled = true;
          postJson('transcript', { token: state.token }).then(function (r) {
            go.disabled = false;
            if (r && r.error) { msg.textContent = r.error.message || t('error'); return; }
            toggleMenu(false); addSys((r && r.message) || t('sent_ok'));
          });
        });
        el.mSub.appendChild(go); el.mSub.appendChild(no);
        go.focus();
      });
    });
    el.mForget.addEventListener('click', function () {
      el.mSub.innerHTML = ''; el.mSub.hidden = false;
      el.mSub.appendChild(txt('div', 'aichat-mmsg', t('forget_ask')));
      var yes = txt('button', 'aichat-mbtn aichat-mdanger', t('yes'), { type: 'button' });
      var no = txt('button', 'aichat-mbtn aichat-mghost', t('cancel'), { type: 'button' });
      no.addEventListener('click', function () { el.mSub.hidden = true; });
      yes.addEventListener('click', function () {
        yes.disabled = true;
        postJson('forget', { token: state.token }).then(function () { toggleMenu(false); forgetLocal(); });
      });
      el.mSub.appendChild(yes); el.mSub.appendChild(no);
    });
    el.mMute.addEventListener('click', function () {
      store.set(K_MUTE, store.get(K_MUTE) === '1' ? '0' : '1'); syncMenu(); unlockAudio(); if (store.get(K_MUTE) !== '1') { ding(); }
    });
    el.menu.appendChild(el.mTranscript); el.menu.appendChild(el.mForget); el.menu.appendChild(el.mPrivacy); el.menu.appendChild(el.mMute); el.menu.appendChild(el.mSub);
    head.appendChild(el.menu);
  }
  function syncMenu() {
    el.mPrivacy.hidden = !state.privacy;
    el.mMute.hidden = !(state.boot && state.boot.sound);
    el.mMute.textContent = store.get(K_MUTE) === '1' ? t('unmute') : t('mute');
  }
  function toggleMenu(force) {
    var open = typeof force === 'boolean' ? force : el.menu.hidden;
    el.menu.hidden = !open; el.menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { el.mSub.hidden = true; syncMenu(); toggleEmoji(false); }
  }
  function forgetLocal() {
    store.del(K_TOKEN); store.del(K_DRAFT); store.del(K_ACCEPT);
    state.token = ''; state.sent = false; state.accepted = false; state.privacyClosed = false; state.pending = null; state.pics = []; renderThumbs(); el.input.value = ''; onInput();
    stopPoll(); setMode('bot');
    doBoot(true);
  }

  /* ---------- open / close ---------- */
  function setOpen(open) {
    state.open = open;
    store.set(K_OPEN, open ? '1' : '0');
    el.panel.hidden = !open;
    el.root.classList.toggle('aichat-is-open', open);
    el.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    el.launcher.setAttribute('aria-label', open ? t('close') : t('open'));
    document.documentElement.classList.toggle('aichat-lock', open && isFull());
    fitViewport();
    if (open) {
      setUnread(false); hideInvite();
      if (!el.list.children.length || Date.now() - state.lastBoot > 60000) { doBoot(true); }
      scrollDown(true);
      if (!needAccept()) { setTimeout(function () { el.input.focus({ preventScroll: true }); }, 30); }
      attnStop();
    } else {
      toggleEmoji(false); toggleMenu(false);
      el.launcher.focus({ preventScroll: true });
      attnSchedule();
    }
  }

  /* ---------- messages ---------- */
  function scrollDown(force) {
    var l = el.list;
    if (force || l.scrollHeight - l.scrollTop - l.clientHeight < 140) { l.scrollTop = l.scrollHeight; }
  }
  function appendToList(n) { el.list.appendChild(n); }
  function addImages(wrap, imgs) {
    if (!imgs || !imgs.length) { return; }
    var box = h('div', 'aichat-imgs');
    imgs.forEach(function (im) {
      var src = im.src || im.thumb; if (!src) { return; }
      box.appendChild(h('img', 'aichat-pic', { src: src, alt: '', loading: 'lazy' }));
    });
    wrap.appendChild(box);
  }
  function addMsg(role, text, opts) {
    opts = opts || {};
    var cls = role === 'user' ? 'user' : role === 'error' ? 'error' : role === 'staff' ? 'staff' : 'bot';
    var wrap = h('div', 'aichat-msg aichat-msg--' + cls);
    if (role === 'staff') {
      var who = h('div', 'aichat-who');
      var nm = opts.name || state.staffName || t('colleague');
      who.appendChild(txt('span', 'aichat-badge', nm.charAt(0).toUpperCase()));
      who.appendChild(txt('span', 'aichat-whoname', nm));
      wrap.appendChild(who);
    }
    if (opts.images) { addImages(wrap, opts.images); }
    var bubble = h('div', 'aichat-bubble');
    if (role === 'user' || role === 'error') { bubble.textContent = text; if (role === 'error') { bubble.setAttribute('role', 'alert'); } } else { bubble.innerHTML = render(text); }
    wrap.appendChild(bubble);
    appendToList(wrap);
    scrollDown(true);
    return { wrap: wrap, bubble: bubble };
  }
  function addSys(text) {
    var n = txt('div', 'aichat-sys', text); appendToList(n); scrollDown(true); return n;
  }

  /* ----- ratings ----- */
  function addRate(wrap, messageId, value) {
    if (!state.features.rating || !messageId) { return; }
    var box = h('div', 'aichat-rate', { role: 'group' });
    var up = iconBtn('aichat-vote', t('helpful'), 'up'), dn = iconBtn('aichat-vote', t('not_helpful'), 'down');
    var cur = parseInt(value, 10) || 0;
    function paint() { up.setAttribute('aria-pressed', cur === 1 ? 'true' : 'false'); dn.setAttribute('aria-pressed', cur === -1 ? 'true' : 'false'); }
    function vote(v) {
      if (cur === v) { return; }
      var prev = cur; cur = v; paint();
      postJson('rate', { token: state.token, message_id: messageId, value: v }).then(function (j) { if (j && j.error) { cur = prev; paint(); } });
    }
    up.addEventListener('click', function () { vote(1); });
    dn.addEventListener('click', function () { vote(-1); });
    paint(); box.appendChild(up); box.appendChild(dn); wrap.appendChild(box);
  }

  /* ----- products ----- */
  function normProducts(x, label) {
    var items = Array.isArray(x) ? x : (x && x.items) || [], lab = (!Array.isArray(x) && x && x.label) || label || '';
    var main = [], sug = [];
    items.forEach(function (p) { (lab === 'suggestion' || p.label === 'suggestion' ? sug : main).push(p); });
    var g = [];
    if (main.length) { g.push({ items: main, label: '' }); }
    if (sug.length) { g.push({ items: sug, label: 'suggestion' }); }
    return g;
  }
  function productCard(p) {
    var card = h('div', 'aichat-card');
    var href = safeHref(p.href);
    var link = h('a', 'aichat-card-link', href ? { href: href } : {});
    if (p.image) { link.appendChild(h('img', 'aichat-card-img', { src: p.image, alt: '', loading: 'lazy' })); }
    var info = h('span', 'aichat-card-info');
    info.appendChild(txt('span', 'aichat-card-name', p.name || ''));
    var pr = h('span', 'aichat-card-price');
    if (p.special) { pr.appendChild(txt('span', 'aichat-price-now', p.special)); pr.appendChild(txt('s', 'aichat-price-old', p.price || '')); }
    else { pr.appendChild(txt('span', 'aichat-price-now', p.price || '')); }
    info.appendChild(pr);
    link.appendChild(info);
    card.appendChild(link);
    if (state.features.cart && p.can_buy && p.product_id) {
      var act = h('div', 'aichat-card-act');
      var btn = txt('button', 'aichat-btn', t('add_cart'), { type: 'button' });
      btn.addEventListener('click', function () { addToCart(p, card, act, btn, null); });
      act.appendChild(btn);
      card.appendChild(act);
    }
    return card;
  }
  function addProducts(wrap, x, label) {
    normProducts(x, label).forEach(function (g) {
      var box = h('div', 'aichat-products' + (g.label === 'suggestion' ? ' aichat-suggest' : ''));
      if (g.label === 'suggestion') { box.appendChild(txt('div', 'aichat-suggest-title', t('you_may_need'))); }
      g.items.forEach(function (p) { box.appendChild(productCard(p)); });
      wrap.appendChild(box);
    });
    scrollDown(true);
  }

  /* ----- cart ----- */
  function setStatus(act, text, isErr, withCart) {
    var s = act.querySelector('.aichat-status');
    if (!s) { s = h('div', 'aichat-status', { role: 'status' }); act.appendChild(s); }
    s.className = 'aichat-status' + (isErr ? ' is-err' : '');
    s.textContent = text || '';
    if (withCart) {
      var a = h('a', 'aichat-link aichat-cartlink', { href: siteUrl('checkout/cart') }); a.textContent = t('view_cart');
      s.appendChild(document.createTextNode(' ')); s.appendChild(a);
    }
  }
  function refreshThemeCart(j) {
    try {
      var $ = window.jQuery;
      if (!$ || !$('#cart').length) { return; }
      var url = siteUrl('common/cart/info') + ' ul li';
      if ($('#cart > ul').length) { $('#cart > ul').load(url); }
      if (j && typeof j.cart_total === 'string' && $('#cart-total').length) {
        var icon = $('#cart-total i').first().clone();
        $('#cart-total').empty().append(icon).append(document.createTextNode(' ' + j.cart_total));
      }
    } catch (e) { /* best effort */ }
  }
  function addToCart(p, card, act, btn, option) {
    btn.disabled = true;
    var body = { token: state.token, product_id: p.product_id, quantity: 1 };
    if (option) { body.option = option; }
    postJson('cart', body).then(function (j) {
      btn.disabled = false;
      var old = card.querySelector('.aichat-opts'); if (old && j && !j.need_options) { old.parentNode.removeChild(old); }
      if (j && j.need_options && j.need_options.length) { setStatus(act, ''); optionsForm(p, card, act, btn, j.need_options); }
      else if (j && j.ok) { setStatus(act, j.message || '', false, true); refreshThemeCart(j); }
      else { setStatus(act, (j && j.error && j.error.message) || (j && j.message) || t('error'), true); }
      scrollDown(false);
    });
  }
  function optionsForm(p, card, act, addBtn, opts) {
    var old = card.querySelector('.aichat-opts'); if (old) { old.parentNode.removeChild(old); }
    var form = h('div', 'aichat-opts'), getters = [];
    opts.forEach(function (o, idx) {
      var f = h('div', 'aichat-opt'), id = 'aichat-o-' + p.product_id + '-' + idx;
      f.appendChild(txt('label', 'aichat-optlabel', o.name + (o.required ? ' *' : ''), { 'for': id }));
      var vals = o.values || [], type = o.type, get;
      function vlabel(v) { return v.name + (v.price ? ' (' + v.price + ')' : ''); }
      if (type === 'select') {
        var sel = h('select', 'aichat-select', { id: id });
        sel.appendChild(txt('option', null, t('choose'), { value: '' }));
        vals.forEach(function (v) { sel.appendChild(txt('option', null, vlabel(v), { value: v.product_option_value_id })); });
        f.appendChild(sel); get = function () { return sel.value; };
      } else if (type === 'radio' || type === 'checkbox') {
        var grp = h('div', 'aichat-choices', { role: type === 'radio' ? 'radiogroup' : 'group', id: id }), ins = [];
        vals.forEach(function (v, k) {
          var lab = h('label', 'aichat-choice'), inp = h('input', null, { type: type, name: id, value: v.product_option_value_id });
          lab.appendChild(inp); lab.appendChild(txt('span', null, vlabel(v))); grp.appendChild(lab); ins.push(inp);
        });
        f.appendChild(grp);
        get = function () {
          var r = ins.filter(function (i) { return i.checked; }).map(function (i) { return i.value; });
          return type === 'radio' ? (r[0] || '') : r;
        };
      } else {
        var inp2 = type === 'textarea' ? h('textarea', 'aichat-textin', { id: id, rows: '2' }) : h('input', 'aichat-textin', { id: id, type: 'text' });
        f.appendChild(inp2); get = function () { return inp2.value.trim(); };
      }
      getters.push({ o: o, get: get, box: f });
      form.appendChild(f);
    });
    var row = h('div', 'aichat-opt-actions');
    var ok = txt('button', 'aichat-btn', t('add_cart'), { type: 'button' });
    var cancel = txt('button', 'aichat-btn aichat-btn-ghost', t('cancel'), { type: 'button' });
    cancel.addEventListener('click', function () { form.parentNode.removeChild(form); setStatus(act, ''); });
    ok.addEventListener('click', function () {
      var optObj = {}, missing = false;
      getters.forEach(function (g) {
        var v = g.get(), empty = Array.isArray(v) ? !v.length : v === '';
        g.box.classList.toggle('is-missing', !!g.o.required && empty);
        if (g.o.required && empty) { missing = true; }
        if (!empty) { optObj[g.o.product_option_id] = v; }
      });
      if (missing) { setStatus(act, t('options_required'), true); return; }
      ok.disabled = true; addToCart(p, card, act, ok, optObj);
    });
    row.appendChild(ok); row.appendChild(cancel); form.appendChild(row);
    card.insertBefore(form, act); scrollDown(false);
  }

  /* ----- link / order / tracking cards ----- */
  function addCards(wrap, items) {
    if (!items || !items.length) { return; }
    var box = h('div', 'aichat-cards');
    items.forEach(function (c) {
      var type = c.type || 'information', n;
      if (type === 'order') { n = orderCard(c); }
      else if (type === 'tracking') { n = trackingCard(c); }
      else { n = linkCard(c, type); }
      if (n) { box.appendChild(n); }
    });
    if (box.children.length) { wrap.appendChild(box); scrollDown(true); }
  }
  function linkCard(c, type) {
    var href = safeHref(c.href);
    if (!href && type === 'contact' && /^(tel:|mailto:)/i.test(c.href || '')) { href = c.href; }
    var a = h('a', 'aichat-lcard aichat-lcard--' + type, href ? { href: href } : {});
    if (c.image) { a.appendChild(h('img', 'aichat-lcard-img', { src: c.image, alt: '', loading: 'lazy' })); }
    else { a.appendChild(svgi(ICONS_OK[type] ? type : 'information', 'aichat-lcard-ico')); }
    var info = h('span', 'aichat-lcard-info');
    info.appendChild(txt('span', 'aichat-lcard-title', c.title || ''));
    if (c.subtitle) { info.appendChild(txt('span', 'aichat-lcard-sub', c.subtitle)); }
    a.appendChild(info);
    a.appendChild(svgi('chev', 'aichat-lcard-go'));
    return a;
  }
  var ICONS_OK = { category: 1, manufacturer: 1, information: 1, special: 1, contact: 1 };
  function orderCard(c) {
    var d = h('div', 'aichat-ocard');
    var top = h('div', 'aichat-ocard-top');
    top.appendChild(svgi('order', 'aichat-lcard-ico'));
    var info = h('span', 'aichat-lcard-info');
    info.appendChild(txt('span', 'aichat-lcard-title', c.title || (t('order') + (c.number ? ' #' + c.number : ''))));
    var sub = c.subtitle || [c.status, c.total].filter(Boolean).join(' · ');
    if (sub) { info.appendChild(txt('span', 'aichat-lcard-sub', sub)); }
    if (c.address) { info.appendChild(txt('span', 'aichat-lcard-sub aichat-ocard-addr', String(c.address))); }
    top.appendChild(info);
    if (c.total && c.subtitle) { top.appendChild(txt('span', 'aichat-ocard-total', c.total)); }
    d.appendChild(top);
    var href = c.href ? safeHref(c.href) : null;
    if (href) { var b = h('a', 'aichat-btn aichat-btn-link', { href: href }); b.textContent = c.button || t('view_order'); d.appendChild(b); }
    return d;
  }
  function trackingCard(c) {
    var d = h('div', 'aichat-ocard aichat-tcard');
    var top = h('div', 'aichat-ocard-top');
    top.appendChild(svgi('tracking', 'aichat-lcard-ico'));
    var info = h('span', 'aichat-lcard-info');
    info.appendChild(txt('span', 'aichat-lcard-title', c.courier || c.title || t('track')));
    var number = c.number || c.subtitle;
    if (number) { info.appendChild(txt('span', 'aichat-lcard-sub aichat-mono', number)); }
    top.appendChild(info);
    d.appendChild(top);
    if (c.status) { d.appendChild(txt('div', 'aichat-tstatus', c.status)); }
    var href = c.href ? externalHref(c.href) : null;
    if (href) {
      var b = h('a', 'aichat-btn aichat-btn-link', { href: href, target: '_blank', rel: 'noopener noreferrer' });
      b.textContent = c.button || t('track'); d.appendChild(b);
    }
    return d;
  }

  /* ----- typing / waiting ----- */
  function showTyping() {
    hideTyping();
    var w = h('div', 'aichat-msg aichat-msg--bot aichat-typing', { role: 'status', 'aria-label': t('typing') });
    var b = h('div', 'aichat-bubble');
    b.innerHTML = '<span class="aichat-dot"></span><span class="aichat-dot"></span><span class="aichat-dot"></span>';
    w.appendChild(b);
    appendToList(w);
    el.typing = w;
    scrollDown(true);
  }
  function hideTyping() { if (el.typing && el.typing.parentNode) { el.typing.parentNode.removeChild(el.typing); } el.typing = null; }
  var waitTimer = null;
  function waitOf(o) { return o && (o.wait_until || o.wait_seconds) ? { until: o.wait_until, seconds: o.wait_seconds } : null; }
  function waitDeadline(w) {
    var now = Date.now(), tot = parseInt(w.seconds, 10) || 0, until = parseFloat(w.until) || 0;
    var dl = until ? until * 1000 : (tot ? now + tot * 1000 : 0);
    if (until && tot && dl - now > tot * 1000 + 5000) { dl = now + tot * 1000; } // browser clock behind the server's
    if (!tot && dl) { tot = Math.max(1, Math.round((dl - now) / 1000)); }
    return dl ? { until: dl, total: tot } : null;
  }
  function tickWait() {
    var w = el.wait;
    if (!w || !w.parentNode) { clearInterval(waitTimer); return; }
    var p = w._p, left = state.wait ? Math.ceil((state.wait.until - Date.now()) / 1000) : null, late = left !== null && left <= 0;
    p.dots.textContent = '...'.slice(0, (Math.floor(Date.now() / 1000) % 3) + 1);
    w.classList.toggle('is-late', late);
    w.classList.toggle('has-time', left !== null && !late);
    p.label.textContent = late ? t('still_checking') : t('connecting');
    if (left !== null && !late) {
      p.time.textContent = Math.floor(left / 60) + ':' + (left % 60 < 10 ? '0' : '') + (left % 60);
      var frac = Math.max(0, Math.min(1, (state.wait.until - Date.now()) / (state.wait.total * 1000)));
      p.fg.style.strokeDashoffset = String(62.83 * (1 - frac));
      w.setAttribute('aria-label', p.label.textContent + ' ' + p.time.textContent);
    } else { p.time.textContent = ''; p.fg.style.strokeDashoffset = '0'; w.removeAttribute('aria-label'); }
  }
  function showWait() {
    if (el.wait && el.wait.parentNode) { tickWait(); return; }
    var w = h('div', 'aichat-wait', { role: 'status' });
    var ring = h('span', 'aichat-wait-ring');
    ring.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle class="aichat-wait-bg" cx="12" cy="12" r="10"/><circle class="aichat-wait-fg" cx="12" cy="12" r="10" transform="rotate(-90 12 12)"/></svg>';
    var label = h('span', 'aichat-wait-t'), dots = h('span', 'aichat-wait-dots'), time = h('span', 'aichat-wait-time');
    w.appendChild(ring); w.appendChild(label); w.appendChild(dots); w.appendChild(time);
    w._p = { label: label, dots: dots, time: time, fg: ring.querySelector('.aichat-wait-fg') };
    appendToList(w); el.wait = w; scrollDown(true);
    tickWait(); clearInterval(waitTimer); waitTimer = setInterval(tickWait, 500);
  }
  function hideWait() { clearInterval(waitTimer); if (el.wait && el.wait.parentNode) { el.wait.parentNode.removeChild(el.wait); } el.wait = null; }

  function setBusy(b) {
    state.busy = b;
    el.root.classList.toggle('aichat-busy', b);
    updateSend();
  }

  /* ---------- handover mode + polling ---------- */
  var poll = { timer: null, busy: false, paused: false, hiddenAt: 0 };
  function human() { return state.mode === 'waiting' || state.mode === 'human'; }
  function setMode(m, staffName, wait) {
    if (!m) { return; }
    if (staffName) { state.staffName = staffName; }
    var was = state.mode;
    state.mode = m;
    if (m !== 'human' && m !== 'waiting') { state.staffName = ''; }
    el.sub.textContent = m === 'waiting' ? t('connecting') : m === 'human' ? (state.staffName || t('colleague')) : t('ai_label');
    el.root.setAttribute('data-mode', m);
    if (m === 'waiting') {
      var d = wait ? waitDeadline(wait) : null;
      if (d && (!state.wait || Math.abs(state.wait.until - d.until) > 1500 || was !== 'waiting')) { state.wait = d; }
      showWait();
    } else { state.wait = null; hideWait(); }
    if (human()) { startPoll(); } else { stopPoll(); }
    if (was !== m) { scrollDown(true); }
  }
  function startPoll() { if (!poll.timer && !poll.busy) { poll.timer = setTimeout(tickPoll, 3000); } }
  function stopPoll() { clearTimeout(poll.timer); poll.timer = null; }
  function tickPoll() {
    poll.timer = null;
    if (!human() || !state.token) { return; }
    if (document.hidden && Date.now() - poll.hiddenAt > 60000) { poll.paused = true; return; }
    poll.busy = true;
    fetch(endpoint('poll', { after: state.lastId }), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Aichat-Token': state.token } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || j.error) { return; }
        var staff = '', got = false;
        (j.messages || []).forEach(function (m) {
          var id = m.message_id || 0;
          if (id && id <= state.lastId) { return; }
          if (id) { state.lastId = id; }
          if (m.staff_name) { staff = m.staff_name; }
          got = true;
          if (m.role === 'system') { hideWait(); addSys(m.content || ''); }
          else if (m.role === 'staff') { hideWait(); addMsg('staff', m.content || '', { name: m.staff_name || staff || state.staffName }); }
          else { hideWait(); var x = addMsg('bot', m.content || ''); if (id) { addRate(x.wrap, id, 0); } }
        });
        if (j.mode) { setMode(j.mode, staff, waitOf(j)); }
        if (got) { notifyIncoming(); }
      })
      .catch(function () { /* keep polling */ })
      .then(function () { poll.busy = false; if (human()) { startPoll(); } });
  }
  function pollKick() { if (human() && poll.paused) { poll.paused = false; stopPoll(); tickPoll(); } else if (human()) { startPoll(); } }

  /* ---------- images ---------- */
  var MAX_PICS = 3;
  function renderThumbs() {
    el.thumbs.innerHTML = '';
    el.thumbs.hidden = !state.pics.length;
    state.pics.forEach(function (p) {
      var d = h('div', 'aichat-thumb aichat-thumb--' + p.status);
      d.appendChild(h('img', null, { src: p.url, alt: '' }));
      if (p.status === 'up') { d.appendChild(h('span', 'aichat-spin')); }
      var rm = iconBtn('aichat-thumb-x', t('remove'), 'close');
      rm.addEventListener('click', function () { state.pics = state.pics.filter(function (q) { return q !== p; }); renderThumbs(); updateSend(); });
      d.appendChild(rm);
      el.thumbs.appendChild(d);
    });
    updateSend();
  }
  var picErrTimer = null;
  function picError(msg) {
    el.thumbErr.textContent = msg; el.thumbErr.hidden = false;
    clearTimeout(picErrTimer); picErrTimer = setTimeout(function () { el.thumbErr.hidden = true; }, 4500);
  }
  function downscale(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onerror = function () { URL.revokeObjectURL(url); reject({ type: 1 }); };
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, hh = img.naturalHeight, s = Math.min(1, 1568 / Math.max(w, hh)), cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(hh * s));
        var c = document.createElement('canvas'); c.width = cw; c.height = ch;
        var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, cw, ch); x.drawImage(img, 0, 0, cw, ch);
        var q = 0.86;
        (function enc() {
          c.toBlob(function (b) {
            if (!b) { return reject({ type: 1 }); }
            if (b.size > 5 * 1024 * 1024) { if (q > 0.5) { q -= 0.15; return enc(); } return reject({ big: 1 }); }
            resolve(b);
          }, 'image/jpeg', q);
        })();
      };
      img.src = url;
    });
  }
  function addFiles(files) {
    if (!state.features.images) { return; }
    for (var i = 0; i < files.length; i++) {
      (function (f) {
        if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { picError(t('img_type')); return; }
        if (f.size > 30 * 1024 * 1024) { picError(t('img_big')); return; }
        if (state.pics.length >= MAX_PICS) { picError(t('img_max')); return; }
        var pic = { status: 'up', url: '', id: null };
        state.pics.push(pic);
        downscale(f).then(function (blob) {
          pic.url = URL.createObjectURL(blob); renderThumbs();
          var fd = new FormData(); fd.append('token', state.token); fd.append('file', blob, 'photo.jpg');
          return fetch(endpoint('upload'), { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.id) { pic.id = j.id; pic.status = 'ok'; }
            else { throw { msg: (j && j.error && j.error.message) || t('error') }; }
          });
        }).catch(function (e) {
          state.pics = state.pics.filter(function (q) { return q !== pic; }); renderThumbs();
          picError(e && e.msg ? e.msg : e && e.big ? t('img_big') : e && e.type ? t('img_type') : t('error'));
        }).then(function () { if (pic.status === 'ok') { renderThumbs(); } });
      })(files[i]);
    }
    renderThumbs();
  }

  /* ---------- sending ---------- */
  function submit(forced) {
    var typed = typeof forced === 'string' ? forced : '';
    var text = typed || el.input.value.trim();
    var pics = typed ? [] : readyPics();
    if ((!text && !pics.length) || state.busy || (!typed && uploading())) { return; }
    if (text.length > state.maxLen) { addMsg('error', t('too_long')); return; }
    if (!text) { text = t('img_caption'); }
    if (!typed) {
      el.input.value = ''; store.del(K_DRAFT); onInput();
      state.pics = []; renderThumbs();
    }
    toggleEmoji(false);
    dispatch(text, pics, { images: pics.map(function (p) { return p.id; }) });
  }
  function dispatch(text, pics, opts) {
    if (needAccept()) { state.pending = { text: text, pics: pics, opts: opts }; showPrivacy(true); addSys(t('accept_first')); return; }
    removeChips();
    state.sent = true; hideInvite();
    if (!state.privacy || !state.privacy.require) { el.priv.hidden = true; }
    var sent = addMsg('user', text, { images: pics.map(function (p) { return { src: p.url }; }) });
    state.lastUserWrap = sent.wrap;
    setBusy(true);
    if (!human()) { setAvatarState('thinking'); showTyping(); }
    state.okReply = false;
    sendMessage(text, opts).then(function (r) {
      if (r && r.challenge) { return runChallenge(text, opts, r.message); }
      if (r && r.privacy) { // the server wants the notice accepted first: take the message back, resend after "I agree"
        if (sent.wrap.parentNode) { sent.wrap.parentNode.removeChild(sent.wrap); }
        state.accepted = false; store.del(K_ACCEPT);
        if (state.privacy) { state.privacy.require = true; } else { state.privacy = { text: r.message || t('accept_first'), url: null, require: true }; }
        state.pending = { text: text, pics: pics, opts: opts };
        showPrivacy(true); syncMenu();
      }
    }).then(function () {}, function () {}).then(function () {
      hideTyping(); setBusy(false);
      if (state.okReply) { setAvatarState('happy'); setTimeout(function () { if (!state.busy && state.avState === 'happy') { setAvatarState('idle'); } }, 1800); }
      else { setAvatarState('idle'); }
    });
  }

  function payload(text, stream, opts) {
    var o = { token: state.token, message: text, url: location.href, stream: stream ? 1 : 0 };
    if (opts && opts.images && opts.images.length) { o.images = opts.images; }
    if (opts && opts.turnstile) { o.turnstile = opts.turnstile; }
    if (state.accepted) { o.privacy_accepted = 1; }
    return JSON.stringify(o);
  }
  function gotReply(wrap, id) {
    state.okReply = true;
    if (id) { state.lastId = Math.max(state.lastId, id); addRate(wrap, id, 0); }
    notifyIncoming();
  }
  function showError(msg) { hideTyping(); addMsg('error', msg || t('error')); }

  function sendMessage(text, opts) {
    var canStream = window.fetch && window.ReadableStream && window.TextDecoder;
    if (!canStream) { return sendPlain(text, opts); }
    return sendStream(text, opts).catch(function (err) {
      if (err && err.fallback) { return sendPlain(text, opts); }
      showError(err && err.userMessage);
    });
  }
  function renderReply(j) {
    var m = addMsg('bot', j.reply);
    addProducts(m.wrap, j.products, j.products_label);
    addCards(m.wrap, j.cards);
    if (j.mode) { setMode(j.mode, undefined, waitOf(j)); }
    if (j.notice) { showNotice(j.notice); }
    gotReply(m.wrap, j.message_id);
  }
  function sendPlain(text, opts) {
    return fetch(endpoint('send'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: payload(text, false, opts)
    }).then(function (r) { return r.json(); }).then(function (j) {
      hideTyping();
      if (j && j.error && j.error.code === 'challenge') { return { challenge: true, message: j.error.message }; }
      if (j && j.error && j.error.code === 'privacy_required') { return { privacy: true, message: j.error.message }; }
      if (j && !j.error && typeof j.reply !== 'string' && j.mode) { if (j.notice) { showNotice(j.notice); } setMode(j.mode, undefined, waitOf(j)); return; }
      if (!j || j.error || typeof j.reply !== 'string') { showError(j && j.error && j.error.message); return; }
      renderReply(j);
    }).catch(function () { showError(); });
  }

  function sendStream(text, opts) {
    return new Promise(function (resolve, reject) {
      fetch(endpoint('send'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
        body: payload(text, true, opts)
      }).then(function (res) {
        var ct = res.headers.get('Content-Type') || '';
        if (!res.ok && ct.indexOf('event-stream') < 0 && ct.indexOf('json') < 0) { return reject({ fallback: true }); }
        if (ct.indexOf('event-stream') < 0) {
          return res.json().then(function (j) {
            hideTyping();
            if (j && j.error) {
              if (j.error.code === 'challenge') { return resolve({ challenge: true, message: j.error.message }); }
              if (j.error.code === 'privacy_required') { return resolve({ privacy: true, message: j.error.message }); }
              return reject({ userMessage: j.error.message });
            }
            if (j && typeof j.reply === 'string') { renderReply(j); return resolve(); }
            if (j && j.mode) { if (j.notice) { showNotice(j.notice); } setMode(j.mode, undefined, waitOf(j)); return resolve(); }
            reject({ fallback: true });
          }, function () { reject({ fallback: true }); });
        }
        if (!res.body || !res.body.getReader) { return reject({ fallback: true }); }

        var reader = res.body.getReader(), dec = new TextDecoder('utf-8'), buf = '';
        var msg = null, full = '', pending = false, got = false, finished = false, challenge = null;
        function ensureMsg() { if (!msg) { hideTyping(); msg = addMsg('bot', ''); setAvatarState('talking'); } return msg; }
        function paint() {
          pending = false;
          if (msg) { msg.bubble.innerHTML = render(full); scrollDown(false); }
        }
        function handle(ev, data) {
          var d;
          try { d = JSON.parse(data); } catch (e) { return; }
          if (ev === 'delta') {
            got = true; ensureMsg();
            full += d.text || '';
            if (!pending) { pending = true; (window.requestAnimationFrame || setTimeout)(paint); }
          } else if (ev === 'products') {
            got = true; addProducts(ensureMsg().wrap, d.items, d.label);
          } else if (ev === 'cards') {
            got = true; addCards(ensureMsg().wrap, d.items);
          } else if (ev === 'notice') {
            showNotice(d);
          } else if (ev === 'done') {
            finished = true; paint();
            if (d.mode) { setMode(d.mode, undefined, waitOf(d)); }
            if (d.notice) { showNotice(d.notice); }
            if (msg) { gotReply(msg.wrap, d.message_id); }
          } else if (ev === 'error') {
            finished = true; paint(); hideTyping();
            if (msg && !full && !msg.wrap.querySelector('.aichat-products,.aichat-cards')) { msg.wrap.parentNode.removeChild(msg.wrap); }
            if (d.code === 'challenge') { challenge = { challenge: true, message: d.message }; }
            else if (d.code === 'privacy_required') { challenge = { privacy: true, message: d.message }; }
            else { addMsg('error', d.message || t('error')); }
          }
        }
        function pump() {
          reader.read().then(function (r) {
            if (r.done) {
              buf += dec.decode();
              flush(true);
              if (!finished) {
                if (!got) { return reject({ fallback: true }); }
                paint(); hideTyping();
              }
              return resolve(challenge || undefined);
            }
            buf += dec.decode(r.value, { stream: true });
            flush(false);
            pump();
          }, function () {
            if (!got) { reject({ fallback: true }); } else { paint(); showError(); resolve(); }
          });
        }
        function flush(end) {
          buf = buf.replace(/\r\n/g, '\n');
          var parts = buf.split('\n\n');
          buf = end ? '' : parts.pop();
          if (end && parts.length && !parts[parts.length - 1]) { parts.pop(); }
          parts.forEach(function (blk) {
            var ev = 'message', data = [];
            blk.split('\n').forEach(function (ln) {
              if (ln.indexOf('event:') === 0) { ev = ln.slice(6).trim(); }
              else if (ln.indexOf('data:') === 0) { data.push(ln.slice(5).replace(/^ /, '')); }
            });
            if (data.length) { handle(ev, data.join('\n')); }
          });
        }
        pump();
      }).catch(function () { reject({ fallback: true }); });
    });
  }

  /* ---------- Turnstile (loaded only when the server asks for a challenge) ---------- */
  var tsLoad = null;
  function loadTurnstile() {
    if (window.turnstile) { return Promise.resolve(window.turnstile); }
    if (!tsLoad) {
      tsLoad = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        s.async = true;
        var to = setTimeout(function () { reject(new Error('timeout')); }, 10000);
        s.onload = function () { clearTimeout(to); window.turnstile ? resolve(window.turnstile) : reject(new Error('missing')); };
        s.onerror = function () { clearTimeout(to); reject(new Error('load')); };
        document.head.appendChild(s);
      });
      tsLoad.catch(function () { tsLoad = null; });
    }
    return tsLoad;
  }
  function getTurnstileToken(site) {
    return loadTurnstile().then(function (ts) {
      return new Promise(function (resolve, reject) {
        var id = null, done = false, to;
        function finish(ok, v) {
          if (done) { return; } done = true; clearTimeout(to);
          el.ts.hidden = true;
          try { if (id !== null && ts.remove) { ts.remove(id); } } catch (e) { /* ignore */ }
          ok ? resolve(v) : reject(v);
        }
        el.ts.hidden = false; el.tsBox.innerHTML = '';
        try {
          id = ts.render(el.tsBox, {
            sitekey: site, appearance: 'interaction-only', size: 'flexible',
            callback: function (tok) { finish(true, tok); },
            'error-callback': function () { finish(false, new Error('ts')); },
            'timeout-callback': function () { finish(false, new Error('ts')); }
          });
        } catch (e) { return finish(false, e); }
        to = setTimeout(function () { finish(false, new Error('timeout')); }, 60000);
      });
    });
  }
  function runChallenge(text, opts, serverMsg) {
    var site = state.boot && state.boot.turnstile_site;
    var tokP = site ? getTurnstileToken(site) : Promise.reject(new Error('nosite'));
    return tokP.catch(function () { return 'unavailable'; }).then(function (tok) {
      var o = { images: opts && opts.images, turnstile: tok };
      return sendMessage(text, o).then(function (r2) {
        if (r2 && r2.challenge) { hideTyping(); addMsg('error', r2.message || serverMsg || t('error')); }
      });
    });
  }

  /* ---------- boot / history ---------- */
  function removeChips() { if (el.chips && el.chips.parentNode) { el.chips.parentNode.removeChild(el.chips); } el.chips = null; }
  function addChips() {
    removeChips();
    var q = state.boot && state.boot.quick_replies;
    if (!q || !q.length) { return; }
    var box = h('div', 'aichat-chips');
    q.forEach(function (s) {
      if (!s) { return; }
      var b = txt('button', 'aichat-chip', s, { type: 'button' });
      b.addEventListener('click', function () { submit(String(s)); });
      box.appendChild(b);
    });
    appendToList(box); el.chips = box;
  }
  function addHistoryItem(m) {
    var id = m.message_id || m.id || 0;
    if (id) { state.lastId = Math.max(state.lastId, id); }
    var role = m.role;
    if (role === 'system') { addSys(m.content || ''); return; }
    if (role === 'user') { addMsg('user', m.content || '', { images: m.images }); return; }
    if (role === 'staff') { var s = addMsg('staff', m.content || '', { name: m.staff_name }); return s; }
    var x = addMsg('bot', m.content || '');
    if (m.products) { addProducts(x.wrap, m.products); }
    if (m.cards) { addCards(x.wrap, m.cards); }
    if (id && role === 'assistant') { addRate(x.wrap, id, m.rating); }
  }
  function renderConversation(b) {
    el.list.innerHTML = ''; el.chips = null; el.typing = null; el.wait = null; clearInterval(waitTimer);
    state.lastId = 0;
    var hist = b.history || [];
    hist.forEach(addHistoryItem);
    var botMode = !b.mode || b.mode === 'bot' || b.mode === 'closed';
    if (!hist.length) { addMsg('bot', (b.returning && b.welcome_back) || b.greeting || t('greeting')); if (botMode) { addChips(); } }
    else if (b.returning && b.welcome_back && botMode && !state.sent) { addMsg('bot', b.welcome_back); addChips(); }
    setMode(b.mode || 'bot', b.staff_name, waitOf(b));
    showPrivacy(false);
    scrollDown(true);
  }
  function applyBoot(b) {
    var first = !el.root;
    state.boot = b;
    state.strings = b.strings || {};
    state.maxLen = b.max_len || 1000;
    state.botName = b.bot_name || 'AI';
    state.features = b.features || {};
    state.appearance = b.appearance || {};
    if (b.token) { state.token = b.token; store.set(K_TOKEN, b.token); }
    var prevReq = state.privacy && state.privacy.require;
    state.privacy = privacyOf(b);
    // accepted earlier for this conversation (server flag or this browser), or the visitor already wrote in it
    state.accepted = !!(state.privacy && (state.privacy.accepted || (state.token && store.get(K_ACCEPT) === state.token) ||
      (!prevReq && (b.history || []).some(function (m) { return m.role === 'user'; }))));
    var newAv = b.avatar || 'default';
    if (first) { state.avatar = newAv; build(); }
    else if (newAv !== state.avatar) { state.avatar = newAv; avs.forEach(function (n) { if (n.hasAttribute('data-loaded')) { n.removeAttribute('data-loaded'); n.innerHTML = renderAvatar().innerHTML; } setAvatarOf(n, newAv); }); }
    el.root.hidden = false;
    el.name.textContent = state.botName;
    el.panel.setAttribute('aria-label', state.botName + ' - ' + t('ai_label'));
    el.input.maxLength = state.maxLen;
    el.attachBtn.hidden = !state.features.images;
    applyAppearance();
    syncMenu();
    renderConversation(b);
    state.lastBoot = Date.now();
    if (first) {
      if (store.get(K_OPEN) === '1' && !isFull()) { setOpen(true); } else { scheduleInvite(); }
    }
  }
  function doBoot(force) {
    if (state.gated && !consented()) { return Promise.resolve(); }
    if (state.booting || (state.busy && !force)) { return Promise.resolve(); }
    state.booting = true;
    var bh = { 'Accept': 'application/json' };
    if (state.token) { bh['X-Aichat-Token'] = state.token; } // header, not URL: URLs end up in server logs
    return fetch(endpoint('boot', { url: location.href }), { credentials: 'same-origin', headers: bh })
      .then(function (r) { return r.json(); })
      .then(function (b) {
        state.booting = false;
        if (!b || !b.enabled) { if (el.root) { el.root.hidden = true; } return; }
        if (gateWanted(b) && !consented()) { // nothing is shown or stored before the shop's cookie consent
          state.gated = true;
          if (el.root) { el.root.hidden = true; }
          whenConsented(function () { state.gated = false; loadCss(); doBoot(true); });
          return;
        }
        applyBoot(b);
      })
      .catch(function () { state.booting = false; /* stay silent; the token is kept for the next attempt */ });
  }
  function refreshIfStale(persisted) {
    if (!el.root) { doBoot(); return; }
    if (state.busy || state.booting) { return; }
    if (persisted || !el.list.children.length || Date.now() - state.lastBoot > 60000) { doBoot(); }
  }

  function loadCss() {
    if (!scriptSrc || document.querySelector('link[data-aichat]')) { return; }
    var l = document.createElement('link');
    l.rel = 'stylesheet'; l.setAttribute('data-aichat', '1');
    l.href = scriptSrc.replace(/widget\.js(\?.*)?$/, 'widget.css$1');
    document.head.appendChild(l);
  }

  function start() {
    if (gateWanted(null) && !consented()) {
      state.gated = true;
      whenConsented(function () { state.gated = false; start(); });
      return;
    }
    if (start.done) { return; }
    start.done = true;
    loadCss(); doBoot();
    window.addEventListener('pageshow', function (e) { if (e.persisted) { refreshIfStale(true); pollKick(); } });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { poll.hiddenAt = Date.now(); attnStop(); }
      else { refreshIfStale(false); pollKick(); attnSchedule(); }
    });
    window.addEventListener('online', function () { if (!el.root) { doBoot(); } });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }

  window.AIChat = { renderAvatar: renderAvatar, open: function () { if (el.panel) { setOpen(true); } } };
})();
