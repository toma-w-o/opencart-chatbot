// Browser-level check of the widget against the real storefront (8080) with the fake model + fake Telegram.
// node tests/browser_check.js <oc>/system/storage/aichat <small.png> [screenshot.png]  (rig from setup_oc.sh running; restores the settings it changes)
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');

const BASE = 'http://127.0.0.1:8080/';
const SVC = 'http://127.0.0.1:8098';
const STORAGE = process.argv[2];
const PNG = process.argv[3];
const sql = (q) => execFileSync('mysql', ['-uroot', 'oc', '-N', '-B', '-e', q]).toString().trim();
const setting = (sid, key, value, ser) => {
  sql(`DELETE FROM oc_setting WHERE store_id=${sid} AND code='module_aichat' AND \`key\`='module_aichat_${key}'`);
  if (value !== null) sql(`INSERT INTO oc_setting SET store_id=${sid}, code='module_aichat', \`key\`='module_aichat_${key}', value='${value}', serialized=${ser ? 1 : 0}`);
};
const results = [];
const check = (name, ok, detail) => { results.push((ok ? 'ok   ' : 'FAIL ') + name + (ok || !detail ? '' : ' :: ' + detail)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  // fixtures: Telegram configured, staff working all day, no per-minute limit
  fs.writeFileSync(STORAGE + '/secret_tg_token.php', "<?php\nreturn '123456:TEST-token-abcdefghijklmnop';\n");
  setting(0, 'g_tg_chat_id', '-100123');
  const open = {}; ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].forEach((d) => { open[d] = { from: '00:00', to: '24:00' }; });
  const hoursBefore = sql("SELECT value FROM oc_setting WHERE store_id=0 AND `key`='module_aichat_s_work_hours'");
  setting(0, 's_work_hours', JSON.stringify(open), true);
  setting(0, 's_max_msgs_minute', '0');
  sql('DELETE FROM oc_aichat_rate');
  await fetch(SVC + '/tg-reset', { method: 'POST' });
  const tgSecret = sql("SELECT value FROM oc_setting WHERE `key`='module_aichat_g_tg_secret'");

  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  try {
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.aichat-launcher', { timeout: 15000 });
    await page.click('.aichat-launcher');
    await page.waitForSelector('textarea.aichat-input', { state: 'visible' });
    check('chat opens', true);

    // 1. message -> streamed answer + product cards
    await page.fill('textarea.aichat-input', 'show me iphone');
    await page.click('.aichat-send');
    await page.waitForSelector('.aichat-card', { timeout: 20000 });
    const cards = await page.$$eval('.aichat-card .aichat-card-name', (n) => n.map((x) => x.textContent));
    check('message answered with product cards', cards.length > 0, JSON.stringify(cards));
    const token = await page.evaluate(() => { for (let i = 0; i < localStorage.length; i++) { const v = localStorage.getItem(localStorage.key(i)); if (/^[a-f0-9]{32}\.[\w-]{22}$/.test(v || '')) return v; } return ''; });
    const pub = token.slice(0, 32);
    const cid = sql(`SELECT conversation_id FROM oc_aichat_conversation WHERE public_id='${pub}'`);
    check('conversation created', !!cid, token);

    // 2. rate the reply
    await page.waitForSelector('.aichat-vote', { timeout: 10000 });
    const votes = await page.$$('.aichat-vote');
    await votes[votes.length - 2].click(); // "helpful" of the last reply
    await sleep(800);
    check('rating stored', sql(`SELECT MAX(rating) FROM oc_aichat_message WHERE conversation_id=${cid} AND role='assistant'`) === '1');

    // 3. add a product to the cart from a card
    const btn = await page.$('.aichat-card-act button');
    if (btn) {
      await btn.click();
      await page.waitForFunction(() => { const s = document.querySelector('.aichat-card-act .aichat-status, .aichat-card .aichat-status, .aichat-opts'); return s && s.textContent.trim() !== ''; }, null, { timeout: 10000 }).catch(() => {});
      const status = await page.$$eval('.aichat-card', (n) => n.map((x) => x.innerText).join(' | '));
      const cartPage = await page.request.get(BASE + 'index.php?route=checkout/cart');
      const html = await cartPage.text();
      check('add to cart (OpenCart cart page shows it)', /added|cart/i.test(status) && /iPhone|iPod|Apple|HTC|Palm|MacBook|Samsung|Canon/.test(html.split('id="content"')[1] || ''), status.slice(0, 200));
    } else {
      check('add to cart button present', false);
    }

    // 4. upload a small image and send it
    await page.setInputFiles('.aichat-file', PNG);
    await page.waitForFunction(() => document.querySelector('.aichat-thumbs') && !document.querySelector('.aichat-thumb--up'), null, { timeout: 15000 });
    await page.click('.aichat-send');
    await page.waitForFunction(() => document.body.innerText.indexOf('SAW 1 IMAGES') !== -1, null, { timeout: 20000 });
    const up = sql(`SELECT COUNT(*) FROM oc_aichat_upload WHERE conversation_id=${cid}`);
    const imgOk = await page.$$eval('img', (n) => n.some((i) => /aichat\/image/.test(i.src) && i.naturalWidth > 0) || n.some((i) => i.src.startsWith('blob:') && i.naturalWidth > 0));
    check('image uploaded (downscaled, re-encoded) and seen by the model', up === '1' && imgOk, up);

    // 5. handover through the fake Telegram
    await page.fill('textarea.aichat-input', 'tool:request_human {"reason":"browser test"}');
    await page.click('.aichat-send');
    await page.waitForSelector('.aichat-wait', { state: 'visible', timeout: 20000 });
    const mode = sql(`SELECT mode FROM oc_aichat_conversation WHERE conversation_id=${cid}`);
    const tg = await (await fetch(SVC + '/tg-log')).json();
    const topic = tg.calls.find((c) => c.method === 'createForumTopic');
    check('handover: waiting indicator + forum topic', mode === 'waiting' && !!topic, mode);
    const hook = async (u) => (await fetch(BASE + 'index.php?route=extension/module/aichat/telegram&s=' + tgSecret, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Telegram-Bot-Api-Secret-Token': tgSecret }, body: JSON.stringify(u) })).json();
    const chat = { id: -100123, type: 'supergroup', is_forum: true };
    const from = { id: 9, is_bot: false, first_name: 'Maria', last_name: 'Petrova' };
    await hook({ update_id: Date.now(), callback_query: { id: 'x1', from, data: 'take:' + cid, message: { message_id: 1, message_thread_id: 100, chat } } });
    await hook({ update_id: Date.now() + 1, message: { message_id: 2, message_thread_id: 100, from, chat, date: 1, text: 'Hello from the team!' } });
    await page.waitForFunction(() => document.body.innerText.indexOf('Hello from the team!') !== -1, null, { timeout: 15000 });
    const joined = await page.evaluate(() => document.body.innerText.indexOf('Maria P joined the chat') !== -1);
    check('handover: Take + staff message shown via poll', joined, '');
    await page.fill('textarea.aichat-input', 'Thanks, one question');
    await page.click('.aichat-send');
    await sleep(1500);
    const tg2 = await (await fetch(SVC + '/tg-log')).json();
    check('handover: visitor message reaches Telegram', tg2.calls.some((c) => c.method === 'sendMessage' && /Thanks, one question/.test(c.params.text || '')));
    await hook({ update_id: Date.now() + 2, callback_query: { id: 'x2', from, data: 'done:' + cid, message: { message_id: 1, message_thread_id: 100, chat } } });
    await page.waitForFunction(() => document.body.innerText.indexOf('The assistant is back') !== -1, null, { timeout: 15000 });
    await page.fill('textarea.aichat-input', 'tool:list_categories {}');
    await page.click('.aichat-send');
    await page.waitForFunction(() => document.body.innerText.indexOf('Desktops') !== -1, null, { timeout: 20000 });
    check('handover: Done -> bot answers again', sql(`SELECT mode FROM oc_aichat_conversation WHERE conversation_id=${cid}`) === 'bot');
    await page.screenshot({ path: process.argv[4] || '/tmp/aichat.png', fullPage: false });
  } catch (e) {
    check('browser run', false, e.message.split('\n')[0]);
  } finally {
    check('no page errors', errors.length === 0, errors.join('; '));
    await browser.close();
    fs.unlinkSync(STORAGE + '/secret_tg_token.php');
    setting(0, 'g_tg_chat_id', null);
    setting(0, 's_work_hours', hoursBefore, true);
    setting(0, 's_max_msgs_minute', '6');
    console.log(results.join('\n'));
  }
})();
