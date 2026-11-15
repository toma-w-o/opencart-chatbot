/*! AI Chat widget - dependency-free. Stage 1. */
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
  var base = cfg.endpoint || (function () {
    var m = scriptSrc.match(/^(.*?)catalog\/view\/javascript\/aichat\//);
    return (m ? m[1] : '/') + 'index.php?route=extension/module/aichat/';
  })();
  function endpoint(name, params) {
    var u = base + name;
    var q = [];
    for (var k in params) { if (params[k] !== undefined && params[k] !== null) { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); } }
    return u + (q.length ? (u.indexOf('?') > -1 ? '&' : '?') + q.join('&') : '');
  }

  /* ---------- safe storage ---------- */
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* ignore */ } }
  };
  var K_TOKEN = 'aichat_token', K_OPEN = 'aichat_open';

  /* ---------- strings ---------- */
  var FALLBACK = {
    en: { ai_label: 'AI assistant', placeholder: 'Type your message…', send: 'Send', open: 'Open chat', close: 'Close chat',
      emoji: 'Emoji', typing: 'The assistant is typing', error: 'Something went wrong. Please try again.',
      too_long: 'Message is too long', chars: 'characters', greeting: 'Hello! How can I help you?', messages: 'Conversation' },
    bg: { ai_label: 'AI асистент', placeholder: 'Напишете съобщение…', send: 'Изпрати', open: 'Отвори чата', close: 'Затвори чата',
      emoji: 'Емоджита', typing: 'Асистентът пише', error: 'Нещо се обърка. Моля, опитайте отново.',
      too_long: 'Съобщението е твърде дълго', chars: 'символа', greeting: 'Здравейте! С какво мога да помогна?', messages: 'Разговор' },
    el: { ai_label: 'Βοηθός AI', placeholder: 'Γράψτε το μήνυμά σας…', send: 'Αποστολή', open: 'Άνοιγμα συνομιλίας', close: 'Κλείσιμο συνομιλίας',
      emoji: 'Emoji', typing: 'Ο βοηθός πληκτρολογεί', error: 'Κάτι πήγε στραβά. Δοκιμάστε ξανά.',
      too_long: 'Το μήνυμα είναι πολύ μεγάλο', chars: 'χαρακτήρες', greeting: 'Γεια σας! Πώς μπορώ να βοηθήσω;', messages: 'Συνομιλία' }
  };
  var lang = (document.documentElement.lang || navigator.language || 'en').toLowerCase().slice(0, 2);
  if (lang === 'gr') { lang = 'el'; }
  var strings = FALLBACK[lang] || FALLBACK.en;
  function t(key) { return (state.strings && state.strings[key]) || strings[key] || FALLBACK.en[key] || key; }

  /* ---------- state ---------- */
  var state = {
    boot: null, strings: {}, token: store.get(K_TOKEN) || '', open: false, busy: false,
    maxLen: 1000, mode: 'bot', avatar: 'default', botName: ''
  };
  var el = {};

  /* ---------- helpers ---------- */
  function h(tag, cls, attrs) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (attrs) { for (var k in attrs) { if (attrs[k] !== null && attrs[k] !== undefined) { n.setAttribute(k, attrs[k]); } } }
    return n;
  }
  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 640px)').matches; }

  /* ---------- safe text rendering ---------- */
  function safeHref(url) {
    try {
      var u = new URL(url, location.href);
      if ((u.protocol === 'http:' || u.protocol === 'https:') && u.host === location.host) { return u.href; }
    } catch (e) { /* ignore */ }
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

  /* ---------- avatar (swap this later for a library) ---------- */
  function renderAvatar(name, st) {
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
  function setAvatarState(st) {
    if (!el.root) { return; }
    var list = el.root.querySelectorAll('.aichat-av[data-live]');
    for (var i = 0; i < list.length; i++) { list[i].setAttribute('data-state', st); }
  }
  function liveAvatar(name) {
    var a = renderAvatar(name, 'idle');
    a.setAttribute('data-live', '1');
    return a;
  }

  /* ---------- emoji ---------- */
  var EMOJI = [
    ['😀', '😀 😃 😄 😁 😆 😅 😂 🙂 😉 😊 😇 😍 😘 😋 😎 🤗 🤔 😐 😴 😢 😭 😡 😱 🙄'],
    ['👍', '👍 👎 👌 ✌️ 🤞 👏 🙌 🙏 💪 👋 🤝 ☝️ 👉 👈'],
    ['❤️', '❤️ 🧡 💛 💚 💙 💜 🖤 💔 💕 ✨ ⭐ 🔥 💯 ✅ ❌ ❓ ❗ 🎉 🎁 🌟'],
    ['🛒', '🛒 🛍️ 📦 🚚 💳 💰 🏷️ 📞 ✉️ 🕐 🏠 🎈 🧸 🚲 ⚽ 🎮 📚 🎨']
  ].map(function (g) { return { icon: g[0], items: g[1].split(' ') }; });

  /* ---------- DOM ---------- */
  function build() {
    var root = h('div', 'aichat-root', { id: 'aichat-root', 'data-state': 'idle' });
    el.root = root;

    el.launcher = h('button', 'aichat-launcher', { type: 'button', 'aria-label': t('open'), 'aria-expanded': 'false', 'aria-controls': 'aichat-panel' });
    el.launcher.appendChild(liveAvatar(state.avatar));
    el.launcher.addEventListener('click', function () { setOpen(true); });

    var panel = h('div', 'aichat-panel', { id: 'aichat-panel', role: 'dialog', 'aria-label': state.botName + ' - ' + t('ai_label'), hidden: '' });
    el.panel = panel;

    var head = h('div', 'aichat-head');
    head.appendChild(liveAvatar(state.avatar));
    var titles = h('div', 'aichat-titles');
    var nm = h('div', 'aichat-name'); nm.textContent = state.botName;
    var lb = h('div', 'aichat-sub'); lb.textContent = t('ai_label');
    titles.appendChild(nm); titles.appendChild(lb);
    head.appendChild(titles);
    el.close = h('button', 'aichat-close', { type: 'button', 'aria-label': t('close') });
    el.close.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none"/></svg>';
    el.close.addEventListener('click', function () { setOpen(false); });
    head.appendChild(el.close);
    panel.appendChild(head);

    el.list = h('div', 'aichat-list', { role: 'log', 'aria-live': 'polite', 'aria-label': t('messages'), tabindex: '0' });
    panel.appendChild(el.list);

    var foot = h('div', 'aichat-foot');
    el.emojiPop = h('div', 'aichat-emoji', { role: 'group', 'aria-label': t('emoji'), hidden: '' });
    buildEmoji();
    foot.appendChild(el.emojiPop);

    var row = h('div', 'aichat-row');
    el.emojiBtn = h('button', 'aichat-iconbtn', { type: 'button', 'aria-label': t('emoji'), 'aria-expanded': 'false' });
    el.emojiBtn.textContent = '🙂';
    el.emojiBtn.addEventListener('click', function () { toggleEmoji(); });
    el.input = h('textarea', 'aichat-input', { rows: '1', placeholder: t('placeholder'), 'aria-label': t('placeholder'), maxlength: String(state.maxLen), enterkeyhint: 'send' });
    el.input.addEventListener('input', onInput);
    el.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); }
    });
    el.sendBtn = h('button', 'aichat-send', { type: 'button', 'aria-label': t('send') });
    el.sendBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.4 20.4l17.4-8.4L3.4 3.6 3.3 10l12 2-12 2z" fill="currentColor"/></svg>';
    el.sendBtn.addEventListener('click', submit);
    row.appendChild(el.emojiBtn); row.appendChild(el.input); row.appendChild(el.sendBtn);
    foot.appendChild(row);
    el.count = h('div', 'aichat-count', { 'aria-live': 'polite' });
    foot.appendChild(el.count);
    panel.appendChild(foot);

    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (!el.emojiPop.hidden) { toggleEmoji(false); el.emojiBtn.focus(); } else { setOpen(false); }
      }
    });

    root.appendChild(panel);
    root.appendChild(el.launcher);
    document.body.appendChild(root);

    // keep panel aligned with the visual viewport (mobile keyboard)
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', fitViewport);
      window.visualViewport.addEventListener('scroll', fitViewport);
    }
    window.addEventListener('resize', fitViewport);
  }

  function fitViewport() {
    if (!el.root) { return; }
    var vv = window.visualViewport;
    if (vv && state.open && isMobile()) {
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
  function insertAtCaret(txt) {
    var i = el.input, s = i.selectionStart || 0, e = i.selectionEnd || 0;
    if (i.value.length - (e - s) + txt.length > state.maxLen) { return; }
    i.value = i.value.slice(0, s) + txt + i.value.slice(e);
    i.selectionStart = i.selectionEnd = s + txt.length;
    i.focus();
    onInput();
  }

  function onInput() {
    var i = el.input;
    i.style.height = 'auto';
    i.style.height = Math.min(i.scrollHeight, 120) + 'px';
    var len = i.value.length;
    el.count.textContent = len > state.maxLen * 0.8 ? len + ' / ' + state.maxLen : '';
    updateSend();
  }
  function updateSend() {
    el.sendBtn.disabled = state.busy || !el.input.value.trim();
  }

  /* ---------- open / close ---------- */
  function setOpen(open) {
    state.open = open;
    store.set(K_OPEN, open ? '1' : '0');
    el.panel.hidden = !open;
    el.root.classList.toggle('aichat-is-open', open);
    el.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    el.launcher.setAttribute('aria-label', open ? t('close') : t('open'));
    document.documentElement.classList.toggle('aichat-lock', open && isMobile());
    fitViewport();
    if (open) {
      scrollDown(true);
      setTimeout(function () { el.input.focus({ preventScroll: true }); }, 30);
    } else {
      toggleEmoji(false);
      el.launcher.focus({ preventScroll: true });
    }
  }

  /* ---------- messages ---------- */
  function scrollDown(force) {
    var l = el.list;
    if (force || l.scrollHeight - l.scrollTop - l.clientHeight < 140) { l.scrollTop = l.scrollHeight; }
  }
  function addMsg(role, text) {
    var wrap = h('div', 'aichat-msg aichat-msg--' + (role === 'user' ? 'user' : role === 'error' ? 'error' : 'bot'));
    var bubble = h('div', 'aichat-bubble');
    if (role === 'user') { bubble.textContent = text; } else if (role === 'error') { bubble.textContent = text; bubble.setAttribute('role', 'alert'); } else { bubble.innerHTML = render(text); }
    wrap.appendChild(bubble);
    el.list.appendChild(wrap);
    scrollDown(true);
    return { wrap: wrap, bubble: bubble };
  }
  function addProducts(wrap, items) {
    if (!items || !items.length) { return; }
    var box = h('div', 'aichat-products');
    items.forEach(function (p) {
      var href = safeHref(p.href);
      var a = h('a', 'aichat-card', href ? { href: href } : {});
      if (p.image) {
        var img = h('img', 'aichat-card-img', { src: p.image, alt: '', loading: 'lazy' });
        a.appendChild(img);
      }
      var info = h('span', 'aichat-card-info');
      var n = h('span', 'aichat-card-name'); n.textContent = p.name || '';
      info.appendChild(n);
      var pr = h('span', 'aichat-card-price');
      if (p.special) {
        var sp = h('span', 'aichat-price-now'); sp.textContent = p.special;
        var old = h('s', 'aichat-price-old'); old.textContent = p.price;
        pr.appendChild(sp); pr.appendChild(old);
      } else {
        var pn = h('span', 'aichat-price-now'); pn.textContent = p.price || '';
        pr.appendChild(pn);
      }
      info.appendChild(pr);
      a.appendChild(info);
      box.appendChild(a);
    });
    wrap.appendChild(box);
    scrollDown(true);
  }
  function showTyping() {
    hideTyping();
    var w = h('div', 'aichat-msg aichat-msg--bot aichat-typing', { role: 'status', 'aria-label': t('typing') });
    var b = h('div', 'aichat-bubble');
    b.innerHTML = '<span class="aichat-dot"></span><span class="aichat-dot"></span><span class="aichat-dot"></span>';
    w.appendChild(b);
    el.list.appendChild(w);
    el.typing = w;
    scrollDown(true);
  }
  function hideTyping() { if (el.typing && el.typing.parentNode) { el.typing.parentNode.removeChild(el.typing); } el.typing = null; }

  function setBusy(b) {
    state.busy = b;
    el.root.classList.toggle('aichat-busy', b);
    updateSend();
  }

  /* ---------- sending ---------- */
  function submit() {
    var text = el.input.value.trim();
    if (!text || state.busy) { return; }
    if (text.length > state.maxLen) { addMsg('error', t('too_long')); return; }
    el.input.value = '';
    onInput();
    toggleEmoji(false);
    addMsg('user', text);
    setBusy(true);
    setAvatarState('thinking');
    showTyping();
    sendMessage(text, true).then(function () {}, function () {}).then(function () {
      hideTyping(); setBusy(false); setAvatarState('idle');
    });
  }

  function payload(text, stream) {
    return JSON.stringify({ token: state.token, message: text, url: location.href, stream: stream ? 1 : 0 });
  }

  function sendMessage(text, tryStream) {
    var canStream = tryStream && window.fetch && window.ReadableStream && window.TextDecoder;
    if (!canStream) { return sendPlain(text); }
    return sendStream(text).catch(function (err) {
      if (err && err.fallback) { return sendPlain(text); }
      showError(err && err.userMessage);
    });
  }

  function showError(msg) {
    hideTyping();
    addMsg('error', msg || t('error'));
  }

  function sendPlain(text) {
    return fetch(endpoint('send'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: payload(text, false)
    }).then(function (r) { return r.json(); }).then(function (j) {
      hideTyping();
      if (!j || j.error || typeof j.reply !== 'string') { showError(j && j.error && j.error.message); return; }
      var m = addMsg('bot', j.reply);
      addProducts(m.wrap, j.products);
      if (j.mode) { state.mode = j.mode; }
    }).catch(function () { showError(); });
  }

  function sendStream(text) {
    return new Promise(function (resolve, reject) {
      fetch(endpoint('send'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
        body: payload(text, true)
      }).then(function (res) {
        var ct = res.headers.get('Content-Type') || '';
        if (!res.ok && ct.indexOf('event-stream') < 0 && ct.indexOf('json') < 0) { return reject({ fallback: true }); }
        if (ct.indexOf('event-stream') < 0) {
          // server answered plain JSON (e.g. streaming unavailable or an error)
          return res.json().then(function (j) {
            hideTyping();
            if (!j || j.error || typeof j.reply !== 'string') { return reject({ userMessage: j && j.error && j.error.message, fallback: !(j && j.error) }); }
            var m = addMsg('bot', j.reply); addProducts(m.wrap, j.products); resolve();
          }, function () { reject({ fallback: true }); });
        }
        if (!res.body || !res.body.getReader) { return reject({ fallback: true }); }

        var reader = res.body.getReader(), dec = new TextDecoder('utf-8'), buf = '';
        var msg = null, full = '', pending = false, got = false, finished = false, chunks = 0;
        function paint() {
          pending = false;
          if (msg) { msg.bubble.innerHTML = render(full); scrollDown(false); }
        }
        function handle(ev, data) {
          var d;
          try { d = JSON.parse(data); } catch (e) { return; }
          if (ev === 'delta') {
            got = true;
            if (!msg) { hideTyping(); msg = addMsg('bot', ''); setAvatarState('talking'); }
            full += d.text || '';
            if (!pending) { pending = true; (window.requestAnimationFrame || setTimeout)(paint); }
          } else if (ev === 'products') {
            got = true;
            if (!msg) { hideTyping(); msg = addMsg('bot', ''); }
            addProducts(msg.wrap, d.items);
          } else if (ev === 'done') {
            finished = true; paint();
            if (d.mode) { state.mode = d.mode; }
          } else if (ev === 'error') {
            finished = true; paint();
            hideTyping();
            if (msg && !full) { msg.wrap.parentNode.removeChild(msg.wrap); }
            addMsg('error', d.message || t('error'));
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
              return resolve();
            }
            chunks++;
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

  /* ---------- boot ---------- */
  function boot() {
    fetch(endpoint('boot', { url: location.href, token: state.token || undefined }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (b) {
        if (!b || !b.enabled) { return; }
        state.boot = b;
        state.strings = b.strings || {};
        state.maxLen = b.max_len || 1000;
        state.avatar = b.avatar || 'default';
        state.botName = b.bot_name || 'AI';
        state.mode = b.mode || 'bot';
        if (b.token) { state.token = b.token; store.set(K_TOKEN, b.token); }
        if (b.emoji === 0) { /* emoji level only steers the model; picker stays */ }
        build();
        var hist = b.history || [];
        if (hist.length) {
          hist.forEach(function (m) {
            var r = m.role === 'user' ? 'user' : 'bot';
            var x = addMsg(r, m.content || '');
            if (m.products) { addProducts(x.wrap, m.products); }
          });
        } else {
          addMsg('bot', b.greeting || t('greeting'));
        }
        if (store.get(K_OPEN) === '1' && !isMobile()) { setOpen(true); }
      })
      .catch(function () { /* stay silent: no widget when boot fails */ });
  }

  function loadCss() {
    if (!scriptSrc || document.querySelector('link[data-aichat]')) { return; }
    var l = document.createElement('link');
    l.rel = 'stylesheet'; l.setAttribute('data-aichat', '1');
    l.href = scriptSrc.replace(/widget\.js(\?.*)?$/, 'widget.css$1');
    document.head.appendChild(l);
  }

  function start() { loadCss(); boot(); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }

  window.AIChat = { renderAvatar: renderAvatar, open: function () { if (el.panel) { setOpen(true); } } };
})();
