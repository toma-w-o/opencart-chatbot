// Round 6 (GDPR) admin + widget checks in a real browser against the rig (setup_oc.sh):
// personal-data permission split (a second user group with AI Chat but without
// extension/module/aichat_pd), the GDPR page (search, export ZIP, restrict, erase with typed
// confirmation, request log with due date, access log), the compliance page, the new settings
// fields, and the widget's privacy notice / "I agree" flow / masked-data notice / consent gate.
//   node tests/gdpr_admin.js <rig dir> [--shots]
// --shots writes docs/preview/admin-gdpr.png, admin-compliance.png, widget-privacy.png, widget-privacy-phone.png
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const WORK = process.argv[2];
const SHOTS = process.argv.includes('--shots');
const SITE = 'http://127.0.0.1:8080/';
const ADMIN = SITE + 'admin/index.php';
const PREVIEW = path.join(__dirname, '..', 'docs', 'preview');
const sql = (q) => execFileSync('mysql', ['-uroot', 'oc', '--default-character-set=utf8mb4', '-N', '-B', '-r', '-e', q]).toString().trim();
const esc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const getSetting = (sid, key) => sql(`SELECT value FROM oc_setting WHERE store_id=${sid} AND \`key\`='module_aichat_${key}' LIMIT 1`);
const setting = (sid, key, value) => {
  sql(`DELETE FROM oc_setting WHERE store_id=${sid} AND code='module_aichat' AND \`key\`='module_aichat_${key}'`);
  if (value !== null) sql(`INSERT INTO oc_setting SET store_id=${sid}, code='module_aichat', \`key\`='module_aichat_${key}', value='${esc(value)}', serialized=0`);
};
// exact snapshot / restore of a setting row (value + serialized flag)
const snap = (sid, key) => {
  const r = sql(`SELECT serialized FROM oc_setting WHERE store_id=${sid} AND \`key\`='module_aichat_${key}' LIMIT 1`);
  return r === '' ? null : { v: getSetting(sid, key), s: r };
};
const restore = (sid, key, row) => {
  sql(`DELETE FROM oc_setting WHERE store_id=${sid} AND code='module_aichat' AND \`key\`='module_aichat_${key}'`);
  if (row) sql(`INSERT INTO oc_setting SET store_id=${sid}, code='module_aichat', \`key\`='module_aichat_${key}', value='${esc(row.v)}', serialized=${row.s}`);
};
const results = [];
const check = (name, ok, detail) => { results.push((ok ? 'ok   ' : 'FAIL ') + name + (ok || detail === undefined ? '' : ' :: ' + String(detail).slice(0, 400))); };

const STAMP = Date.now().toString(36);
const EMAIL = `gdpr-${STAMP}@example.com`;
const PHONE = '0888 12 34 ' + String(10 + Math.floor(Math.random() * 89));
const STAFF = 'aichatstaff';

function seedConversation() {
  const pub = 'gdpr' + STAMP + Math.floor(Math.random() * 1e6);
  sql(`INSERT INTO oc_aichat_conversation SET store_id=0, language_id=1, customer_id=0, public_id='${pub}', ip='203.0.113.7', user_agent='TestBrowser', mode='bot', first_url='${SITE}', last_url='${SITE}', message_count=2, date_added=NOW(), date_modified=NOW()`);
  const cid = Number(sql(`SELECT conversation_id FROM oc_aichat_conversation WHERE public_id='${pub}'`));
  sql(`INSERT INTO oc_aichat_message SET conversation_id=${cid}, role='user', content='${esc(`Hi, I am Maria, my e-mail is ${EMAIL} and phone ${PHONE}, ул. Витоша 15`)}', meta='{}', date_added=NOW()`);
  sql(`INSERT INTO oc_aichat_message SET conversation_id=${cid}, role='assistant', content='Thank you, Maria!', meta='{}', date_added=NOW()`);
  sql(`INSERT INTO oc_aichat_request SET store_id=0, conversation_id=${cid}, type='contact', email='${EMAIL}', phone='${esc(PHONE)}', data='{"note":"call me"}', status='new', date_added=NOW()`);
  return cid;
}

function seedStaffUser() {
  // a group with everything the Administrator has, except the personal-data entry
  const perm = JSON.parse(sql(`SELECT permission FROM oc_user_group WHERE name='Administrator' LIMIT 1`));
  for (const t of ['access', 'modify']) perm[t] = (perm[t] || []).filter((p) => p !== 'extension/module/aichat_pd');
  sql(`DELETE FROM oc_user WHERE username='${STAFF}'`);
  sql(`DELETE FROM oc_user_group WHERE name='AI Chat staff (no PD)'`);
  sql(`INSERT INTO oc_user_group SET name='AI Chat staff (no PD)', permission='${esc(JSON.stringify(perm))}'`);
  const gid = sql(`SELECT user_group_id FROM oc_user_group WHERE name='AI Chat staff (no PD)'`);
  // OpenCart 3 also accepts an md5 password at login
  sql(`INSERT INTO oc_user SET user_group_id=${gid}, username='${STAFF}', password=MD5('staff-pass-1'), salt='', firstname='Staff', lastname='NoPD', email='staff@example.com', image='', code='', ip='', status=1, date_added=NOW()`);
  return gid;
}

async function login(browser, user, pass) {
  const ctx = await browser.newContext({ deviceScaleFactor: 2, viewport: { width: 1360, height: 900 }, acceptDownloads: true });
  const page = await ctx.newPage();
  await page.goto(ADMIN, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="username"]', user);
  await page.fill('input[name="password"]', pass);
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
  const token = new URL(page.url()).searchParams.get('user_token');
  return { ctx, page, token };
}

(async () => {
  const cid = seedConversation();
  const gid = seedStaffUser();
  const saved = {};
  const KEYS = ['s_max_msgs_minute', 's_privacy_accept', 's_wait_consent', 's_privacy_url', 's_tg_mask', 's_tg_delete_days', 's_privacy_text'];
  for (const sid of [0, 1]) for (const k of KEYS) saved[sid + k] = snap(sid, k);
  const browser = await chromium.launch();
  const errors = [];
  try {
    // ------------------------------------------------------------ administrator (has aichat_pd)
    const A = await login(browser, 'admin', 'admin');
    A.page.on('pageerror', (e) => errors.push('admin: ' + e.message));
    A.page.on('dialog', (d) => d.accept());
    check('admin login', !!A.token);
    const T = A.token;

    await A.page.goto(`${ADMIN}?route=extension/module/aichat/conversations&user_token=${T}`, { waitUntil: 'domcontentloaded' });
    let html = await A.page.content();
    check('admin: conversation list shows the e-mail in the preview', html.includes(EMAIL));
    check('admin: menu/subnav has the GDPR page', html.includes('route=extension/module/aichat/privacy'));

    await A.page.goto(`${ADMIN}?route=extension/module/aichat/conversation&conversation_id=${cid}&user_token=${T}`, { waitUntil: 'domcontentloaded' });
    html = await A.page.content();
    check('admin: transcript opens with full text', html.includes(EMAIL));
    const viewLogged = Number(sql(`SELECT COUNT(*) FROM oc_aichat_access_log WHERE conversation_id=${cid} AND action='view'`));
    check('transcript view written to aichat_access_log', viewLogged >= 1, viewLogged);

    // GDPR page: search by e-mail
    const gdpr = `${ADMIN}?route=extension/module/aichat/privacy&user_token=${T}`;
    await A.page.goto(gdpr, { waitUntil: 'domcontentloaded' });
    await A.page.fill('#dsr-email', EMAIL.toUpperCase());
    await A.page.fill('#dsr-received', '2026-11-01');
    await A.page.fill('#dsr-ref', 'test request ' + STAMP);
    await Promise.all([A.page.waitForNavigation(), A.page.click('#aichat-dsr-form button[type="submit"]')]);
    const rowFound = await A.page.$(`#aichat-dsr-act tr[data-id="${cid}"]`);
    check('GDPR search by e-mail (case-insensitive) finds the conversation', !!rowFound);
    const reqFound = await A.page.$$eval('input[name="request_ids[]"]', (els) => els.length);
    check('GDPR search finds the request', reqFound >= 1, reqFound);
    // phone search (different formatting, last 9 digits)
    const phoneUrl = `${gdpr}&search=1&phone=${encodeURIComponent('+359' + PHONE.replace(/\D/g, '').slice(1))}`;
    await A.page.goto(phoneUrl, { waitUntil: 'domcontentloaded' });
    check('GDPR search by phone in another format finds the conversation', !!(await A.page.$(`#aichat-dsr-act tr[data-id="${cid}"]`)));
    // back to the e-mail search for the actions
    await A.page.goto(`${gdpr}&search=1&email=${encodeURIComponent(EMAIL)}&received=2026-11-01&ref=${encodeURIComponent('test request ' + STAMP)}`, { waitUntil: 'domcontentloaded' });
    const dueText = await A.page.$eval('#aichat-dsr-log tbody tr', (tr) => tr.innerText).catch(() => '');
    check('request log: newest row is the search with received date and due date', /2026-11-01/.test(dueText) && /2026-12-01/.test(dueText), dueText);
    if (SHOTS) await A.page.screenshot({ path: path.join(PREVIEW, 'admin-gdpr.png'), fullPage: true });

    // export
    const [download] = await Promise.all([A.page.waitForEvent('download', { timeout: 20000 }), A.page.click('#aichat-dsr-export')]);
    const zipPath = path.join(WORK, 'gdpr-export-test.zip');
    await download.saveAs(zipPath);
    const zip = fs.readFileSync(zipPath);
    check('export downloads a ZIP', zip.slice(0, 2).toString() === 'PK', zip.slice(0, 4).toString('hex'));
    let unzipped = '';
    try { unzipped = execFileSync('unzip', ['-p', zipPath]).toString(); } catch (e) { unzipped = ''; }
    check('export ZIP contains the conversation text', unzipped.includes(EMAIL), unzipped.slice(0, 200));
    check('export written to access log', Number(sql(`SELECT COUNT(*) FROM oc_aichat_access_log WHERE conversation_id=${cid} AND action='export'`)) >= 1);

    // restrict
    await A.page.click(`#aichat-dsr-act tr[data-id="${cid}"] .aichat-restrict`);
    await A.page.waitForSelector('#aichat-dsr-msg .alert-success');
    check('restrict sets aichat_conversation.restricted = 1', sql(`SELECT restricted FROM oc_aichat_conversation WHERE conversation_id=${cid}`) === '1');

    // erase: wrong word first, then DELETE
    await A.page.fill('#aichat-erase-word', 'delete');
    await A.page.click('#aichat-dsr-erase');
    await A.page.waitForSelector('#aichat-dsr-msg .alert-danger');
    check('erase refuses without the exact typed word', sql(`SELECT COUNT(*) FROM oc_aichat_conversation WHERE conversation_id=${cid}`) === '1');
    // the server also refuses a direct POST without it
    const direct = await A.page.evaluate(async (u) => (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'conversation_ids[]=' + u.split('cid=')[1] })).json(), `${ADMIN}?route=extension/module/aichat/privacy_erase&user_token=${T}&cid=${cid}`);
    check('erase route refuses a POST without confirm', direct && direct.success === false, JSON.stringify(direct));
    await A.page.fill('#aichat-erase-word', 'DELETE');
    await A.page.click('#aichat-dsr-erase');
    await A.page.waitForSelector('#aichat-dsr-msg .alert-success', { timeout: 15000 });
    check('erase deletes the conversation and its messages', sql(`SELECT COUNT(*) FROM oc_aichat_conversation WHERE conversation_id=${cid}`) === '0' && sql(`SELECT COUNT(*) FROM oc_aichat_message WHERE conversation_id=${cid}`) === '0');
    check('erase deletes the contact request', sql(`SELECT COUNT(*) FROM oc_aichat_request WHERE email='${EMAIL}'`) === '0');
    check('erase written to access log', Number(sql(`SELECT COUNT(*) FROM oc_aichat_access_log WHERE conversation_id=${cid} AND action='delete'`)) >= 1);
    const actions = sql(`SELECT GROUP_CONCAT(action ORDER BY dsr_id) FROM oc_aichat_dsr WHERE query LIKE '%${STAMP}%'`);
    check('request log has search, export, restrict, erase', ['search', 'export', 'restrict', 'erase'].every((a) => actions.includes(a)), actions);
    await A.page.goto(gdpr, { waitUntil: 'domcontentloaded' });
    const closed = await A.page.$eval('#aichat-dsr-log', (t) => t.innerText).catch(() => '');
    check('request log shows the case as done after export/erase', /done|изпълнено/i.test(closed), closed.slice(0, 300));
    const accessRows = await A.page.$$eval('#aichat-access-log tbody tr', (r) => r.length);
    check('access log table is shown on the GDPR page', accessRows >= 3, accessRows);

    // compliance page
    await A.page.goto(`${ADMIN}?route=extension/module/aichat/compliance&user_token=${T}`, { waitUntil: 'domcontentloaded' });
    html = await A.page.content();
    check('compliance: processors table lists Anthropic, Telegram, Turnstile, hosting, mail', (await A.page.$$eval('#aichat-processors tbody tr', (r) => r.length)) === 5);
    const ropa = await A.page.$eval('#aichat-ropa', (t) => t.value);
    check('compliance: Art. 30 text carries the placeholders', ropa.includes('[ЮРИДИЧЕСКО ЛИЦЕ]') && ropa.includes('[ИМЕЙЛ ЗА ЛИЧНИ ДАННИ]'), ropa.slice(0, 200));
    check('compliance: retention table and checklist', (await A.page.$$eval('#aichat-retention-table tbody tr', (r) => r.length)) >= 8 && (await A.page.$$eval('#aichat-checklist li', (r) => r.length)) >= 10);
    if (SHOTS) await A.page.screenshot({ path: path.join(PREVIEW, 'admin-compliance.png'), fullPage: true });

    // settings: fields, warnings, validation, save
    const settingsUrl = `${ADMIN}?route=extension/module/aichat&user_token=${T}&tab=privacy`;
    await A.page.goto(settingsUrl, { waitUntil: 'domcontentloaded' });
    for (const f of ['s_privacy_accept', 's_wait_consent', 's_min_model', 's_tg_mask', 's_tg_delete_days']) {
      check(`settings field s[0][${f}]`, (await A.page.$(`[name="s[0][${f}]"]`)) !== null);
    }
    for (const f of ['g_retention_months', 'g_ip_days', 'g_image_retention_days', 'g_request_months', 'g_miss_days']) {
      check(`settings field g[${f}]`, (await A.page.$(`[name="g[${f}]"]`)) !== null);
    }
    check('settings: privacy notice text per language + preview', (await A.page.$('[name="s[0][s_privacy_text][1]"]')) !== null && (await A.page.$('.aichat-privacy-row[data-store="0"] .aichat-pp-text')) !== null);
    const pv = await A.page.$eval('.aichat-privacy-row[data-store="0"] .aichat-pp-text', (e) => e.textContent);
    check('settings: preview shows the notice text', /AI/.test(pv), pv);
    check('settings: consent snippet shown', (await A.page.content()).includes("window.dispatchEvent(new Event('aichat:consent'))"));
    await A.page.click('a[href="#tab-general"]');
    await A.page.fill('#input-g_retention_months', '24');
    check('settings: warning above 12 months', await A.page.isVisible('#input-g_retention_months >> xpath=ancestor::div[contains(@class,"form-group")] >> .aichat-retention-warn'));
    await A.page.fill('#input-g_retention_months', '6');

    // validation: a bad privacy URL is refused (nothing saved)
    setting(0, 's_max_msgs_minute', '6');
    setting(1, 's_max_msgs_minute', '6');
    await A.page.goto(settingsUrl, { waitUntil: 'domcontentloaded' });
    await A.page.click('a[href="#tab-privacy"]');
    await A.page.fill('[name="s[0][s_privacy_url][1]"]', 'javascript:alert(1)');
    await Promise.all([A.page.waitForNavigation(), A.page.click('button[form="form-aichat"]')]);
    check('settings: invalid privacy URL refused', (await A.page.content()).includes('aichat-err') && getSetting(0, 's_privacy_url') === (saved['0s_privacy_url'] ? saved['0s_privacy_url'].v : ''));
    await A.page.fill('[name="s[0][s_privacy_url][1]"]', '/index.php?route=information/information&information_id=4');
    await A.page.selectOption('[name="s[0][s_tg_mask]"]', '1');
    await A.page.fill('[name="s[0][s_tg_delete_days]"]', '3');
    await Promise.all([A.page.waitForNavigation(), A.page.click('button[form="form-aichat"]')]);
    check('settings: saved (path URL, Telegram mask, topic days)', getSetting(0, 's_tg_mask') === '1' && getSetting(0, 's_tg_delete_days') === '3' && getSetting(0, 's_privacy_url').includes('information_id=4'), getSetting(0, 's_privacy_url'));

    // ------------------------------------------------------------ staff without aichat_pd
    const cid2 = seedConversation();
    const S = await login(browser, STAFF, 'staff-pass-1');
    S.page.on('pageerror', (e) => errors.push('staff: ' + e.message));
    check('staff login', !!S.token, S.page.url());
    const ST = S.token;
    await S.page.goto(`${ADMIN}?route=extension/module/aichat/conversations&user_token=${ST}`, { waitUntil: 'domcontentloaded' });
    html = await S.page.content();
    check('staff: conversation list loads', html.includes('aichat-table'));
    check('staff: preview has no e-mail or phone', !html.includes(EMAIL) && !html.includes(PHONE));
    check('staff: no transcript link', !html.includes(`conversation_id=${cid2}`));
    check('staff: no GDPR page in the sub-menu', !html.includes('route=extension/module/aichat/privacy&'));
    await S.page.goto(`${ADMIN}?route=extension/module/aichat/conversation&conversation_id=${cid2}&user_token=${ST}`, { waitUntil: 'domcontentloaded' });
    html = await S.page.content();
    check('staff: transcript route refused', !html.includes(EMAIL) && /aichat_pd/.test(html), S.page.url());
    check('staff: refused view not logged as access', sql(`SELECT COUNT(*) FROM oc_aichat_access_log WHERE conversation_id=${cid2}`) === '0');
    await S.page.goto(`${ADMIN}?route=extension/module/aichat/privacy&user_token=${ST}`, { waitUntil: 'domcontentloaded' });
    check('staff: GDPR page refused', !S.page.url().includes('route=extension/module/aichat/privacy'), S.page.url());
    const live = await S.page.evaluate(async (u) => (await fetch(u)).json(), `${ADMIN}?route=extension/module/aichat/live&user_token=${ST}`);
    check('staff: live list has no message text and no links', live.pd === false && live.items.every((i) => !i.last && !i.href), JSON.stringify(live).slice(0, 300));
    const liveA = await A.page.evaluate(async (u) => (await fetch(u)).json(), `${ADMIN}?route=extension/module/aichat/live&user_token=${T}`);
    const mine = liveA.items.find((i) => i.id === cid2);
    check('admin: live list shows masked text', !!mine && !mine.last.includes(EMAIL) && mine.last.includes('[…]'), JSON.stringify(mine));
    const csv = await S.page.goto(`${ADMIN}?route=extension/module/aichat/export&user_token=${ST}`, { waitUntil: 'domcontentloaded' });
    check('staff: CSV export refused', !String(csv.headers()['content-type'] || '').includes('csv'));
    const del = await S.page.evaluate(async (u) => (await fetch(u.split('|')[0], { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'conversation_id=' + u.split('|')[1] })).json(), `${ADMIN}?route=extension/module/aichat/delete&user_token=${ST}|${cid2}`);
    check('staff: delete refused', del.success === false && sql(`SELECT COUNT(*) FROM oc_aichat_conversation WHERE conversation_id=${cid2}`) === '1', JSON.stringify(del));
    const exp = await S.page.evaluate(async (u) => (await fetch(u.split('|')[0], { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'conversation_ids[]=' + u.split('|')[1] })).json(), `${ADMIN}?route=extension/module/aichat/privacy_export&user_token=${ST}|${cid2}`);
    check('staff: GDPR export refused', exp.success === false, JSON.stringify(exp));
    const upload = sql(`SELECT upload_id FROM oc_aichat_upload ORDER BY upload_id DESC LIMIT 1`);
    if (upload) {
      const img = await S.page.goto(`${ADMIN}?route=extension/module/aichat/image&upload_id=${upload}&user_token=${ST}`);
      check('staff: uploaded image refused', img.status() === 404, img.status());
      const imgA = await A.page.goto(`${ADMIN}?route=extension/module/aichat/image&upload_id=${upload}&user_token=${T}`);
      check('admin: uploaded image served', imgA.status() === 200, imgA.status());
    }
    await S.page.goto(`${ADMIN}?route=extension/module/aichat/requests&user_token=${ST}`, { waitUntil: 'domcontentloaded' });
    html = await S.page.content();
    check('staff: requests page masks e-mail and phone', !html.includes(EMAIL) && !html.includes(PHONE));
    await S.page.goto(`${ADMIN}?route=extension/module/aichat/compliance&user_token=${ST}`, { waitUntil: 'domcontentloaded' });
    check('staff: compliance page (no personal data) opens', (await S.page.$('#aichat-processors')) !== null);
    await S.page.goto(`${ADMIN}?route=user/user_permission/edit&user_group_id=${gid}&user_token=${T}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await A.page.goto(`${ADMIN}?route=user/user_permission/edit&user_group_id=${gid}&user_token=${T}`, { waitUntil: 'domcontentloaded' });
    check('user group form lists the aichat_pd permission', (await A.page.content()).includes('extension/module/aichat_pd'));
    await S.ctx.close();
    sql(`DELETE FROM oc_aichat_message WHERE conversation_id=${cid2}`);
    sql(`DELETE FROM oc_aichat_request WHERE conversation_id=${cid2}`);
    sql(`DELETE FROM oc_aichat_conversation WHERE conversation_id=${cid2}`);
    await A.ctx.close();

    // ------------------------------------------------------------ widget
    const widgetCtx = async (opts) => {
      const ctx = await browser.newContext(Object.assign({ deviceScaleFactor: 2, viewport: { width: 1280, height: 820 } }, opts || {}));
      const p = await ctx.newPage();
      p.on('pageerror', (e) => errors.push('widget: ' + e.message));
      return { ctx, p };
    };
    let W = await widgetCtx();
    await W.p.goto(SITE, { waitUntil: 'domcontentloaded' });
    await W.p.waitForSelector('.aichat-launcher', { timeout: 15000 });
    await W.p.click('.aichat-launcher');
    await W.p.waitForSelector('.aichat-privacy:not([hidden])', { timeout: 10000 });
    const note = await W.p.$eval('.aichat-privacy', (e) => e.textContent);
    check('widget: privacy notice shown on first open', /AI/.test(note), note);
    check('widget: notice links to the policy', (await W.p.$('.aichat-privacy a.aichat-privacy-link')) !== null);
    check('widget: input enabled without require_accept', !(await W.p.$eval('.aichat-input', (e) => e.disabled)));
    if (SHOTS) await W.p.screenshot({ path: path.join(PREVIEW, 'widget-privacy.png') });
    // "⋯" menu has the notice
    await W.p.click('.aichat-hbtn[aria-label]:not(.aichat-close)');
    await W.p.click('.aichat-menu .aichat-mitem:has-text("Privacy")');
    check('widget: notice in the "⋯" menu', await W.p.isVisible('.aichat-privacy-menu'));
    await W.p.keyboard.press('Escape');
    // masked-data notice
    await W.p.fill('.aichat-input', 'my card is 4111 1111 1111 1111, can I pay?');
    await W.p.keyboard.press('Enter');
    const warn = await W.p.waitForSelector('.aichat-sys--warn', { timeout: 30000 }).then(() => true).catch(() => false);
    check('widget: masked-data notice shown', warn);
    const bubble = await W.p.$eval('.aichat-msg--user .aichat-bubble', (e) => e.textContent).catch(() => '');
    check('widget: own message shows the masked text', !bubble.includes('4111 1111 1111 1111'), bubble);
    check('widget: notice hidden after the first message', !(await W.p.isVisible('.aichat-privacy')));
    await W.ctx.close();

    // "I agree" required (phone)
    setting(0, 's_privacy_accept', '1');
    W = await widgetCtx({ viewport: { width: 390, height: 780 }, isMobile: true, hasTouch: true });
    await W.p.goto(SITE, { waitUntil: 'domcontentloaded' });
    await W.p.waitForSelector('.aichat-launcher', { timeout: 15000 });
    await W.p.click('.aichat-launcher');
    await W.p.waitForSelector('.aichat-privacy-accept', { timeout: 10000 });
    check('widget: input locked until "I agree"', await W.p.$eval('.aichat-input', (e) => e.disabled));
    if (SHOTS) await W.p.screenshot({ path: path.join(PREVIEW, 'widget-privacy-phone.png') });
    await W.p.click('.aichat-privacy-accept');
    check('widget: "I agree" unlocks the input', !(await W.p.$eval('.aichat-input', (e) => e.disabled)));
    await W.p.fill('.aichat-input', 'Hello');
    await W.p.keyboard.press('Enter');
    const accepted = await W.p.waitForSelector('.aichat-msg--bot >> nth=1', { timeout: 30000 }).then(() => true).catch(() => false);
    const acceptedAt = sql(`SELECT COUNT(*) FROM oc_aichat_conversation WHERE privacy_accepted_at IS NOT NULL AND date_added > NOW() - INTERVAL 2 MINUTE`);
    check('widget: message after "I agree" is answered and acceptance stored', accepted && Number(acceptedAt) >= 1, acceptedAt);
    await W.ctx.close();
    // a stale browser (accepted flag gone) gets privacy_required and the notice again
    W = await widgetCtx();
    await W.p.goto(SITE, { waitUntil: 'domcontentloaded' });
    await W.p.waitForSelector('.aichat-launcher', { timeout: 15000 });
    await W.p.evaluate(() => { /* pretend an older widget state: no acceptance, input usable */ });
    const r = await W.p.evaluate(async () => {
      const tok = localStorage.getItem('aichat_token');
      const res = await fetch('index.php?route=extension/module/aichat/send', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Aichat-Token': tok }, body: JSON.stringify({ token: tok, message: 'hi', url: location.href, stream: 0 }) });
      return res.json();
    });
    check('server: send without privacy_accepted answers privacy_required', r && r.error && r.error.code === 'privacy_required', JSON.stringify(r));
    await W.ctx.close();
    setting(0, 's_privacy_accept', '0');

    // consent gate
    setting(0, 's_wait_consent', '1');
    W = await widgetCtx();
    await W.p.goto(SITE, { waitUntil: 'domcontentloaded' });
    await W.p.waitForTimeout(2500);
    const before = await W.p.$('.aichat-launcher');
    const tokBefore = await W.p.evaluate(() => localStorage.getItem('aichat_token'));
    check('widget: no launcher and nothing stored before consent', !before && !tokBefore, String(tokBefore));
    await W.p.evaluate(() => { window.AICHAT_CONSENT = true; window.dispatchEvent(new Event('aichat:consent')); });
    const after = await W.p.waitForSelector('.aichat-launcher', { timeout: 15000 }).then(() => true).catch(() => false);
    check('widget: launcher appears after aichat:consent', after);
    await W.ctx.close();
    setting(0, 's_wait_consent', '0');
  } catch (e) {
    check('no exception', false, e.stack);
  } finally {
    for (const sid of [0, 1]) for (const k of Object.keys(saved).filter((x) => x.startsWith(String(sid))).map((x) => x.slice(1))) restore(sid, k, saved[sid + k]);
    sql(`DELETE FROM oc_user WHERE username='${STAFF}'`);
    sql(`DELETE FROM oc_user_group WHERE name='AI Chat staff (no PD)'`);
    check('no page errors', errors.length === 0, errors.join(' | '));
    await browser.close();
    console.log(results.join('\n'));
    const failed = results.filter((l) => l.startsWith('FAIL')).length;
    console.log(`\n${results.length - failed}/${results.length} passed`);
    process.exit(failed ? 1 : 0);
  }
})();
