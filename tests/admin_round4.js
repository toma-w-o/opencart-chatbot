// Round 4 admin checks in a real browser against the rig (setup_oc.sh): new settings fields, the
// courier "advanced" pattern field, the avatar fallback, the per-store "Send test e-mail" button
// through OpenCart's real Mail class (fake SMTP servers), and the Requests page.
//   node tests/admin_round4.js <rig dir>
// Expects mail_smtp_test.php to have configured store 0 -> smtp 2525 and store 1 -> smtp 2526.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');

const WORK = process.argv[2];
const ADMIN = 'http://127.0.0.1:8080/admin/index.php';
const sql = (q) => execFileSync('mysql', ['-uroot', 'oc', '--default-character-set=utf8mb4', '-N', '-B', '-r', '-e', q]).toString().trim();
const setting = (sid, key, value) => {
  sql(`DELETE FROM oc_setting WHERE store_id=${sid} AND code='module_aichat' AND \`key\`='module_aichat_${key}'`);
  if (value !== null) sql(`INSERT INTO oc_setting SET store_id=${sid}, code='module_aichat', \`key\`='module_aichat_${key}', value='${value}', serialized=0`);
};
const cfg = (sid, key, value) => {
  sql(`DELETE FROM oc_setting WHERE store_id=${sid} AND code='config' AND \`key\`='${key}'`);
  sql(`INSERT INTO oc_setting SET store_id=${sid}, code='config', \`key\`='${key}', value='${value}', serialized=0`);
};
const smtpLog = () => { try { return fs.readFileSync(WORK + '/smtp.log', 'utf8'); } catch (e) { return ''; } };
const results = [];
const check = (name, ok, detail) => { results.push((ok ? 'ok   ' : 'FAIL ') + name + (ok || !detail ? '' : ' :: ' + String(detail).slice(0, 600))); };

(async () => {
  setting(0, 's_avatar', 'ghost-removed-avatar');
  // the smoke tests switch the per-minute limit off (0), which the admin form does not accept
  setting(0, 's_max_msgs_minute', '6');
  setting(1, 's_max_msgs_minute', '6');
  // couriers as schema 4 seeds them (earlier smoke parts overwrite g_couriers with test hosts)
  const seeds = execFileSync('php', ['-r', `spl_autoload_register(function ($c) { require '${WORK}/oc/system/library/' . str_replace('\\\\', '/', strtolower($c)) . '.php'; }); echo json_encode(\\Aichat\\Settings::defaultCouriers());`]).toString();
  sql(`DELETE FROM oc_setting WHERE store_id=0 AND \`key\`='module_aichat_g_couriers'`);
  sql(`INSERT INTO oc_setting SET store_id=0, code='module_aichat', \`key\`='module_aichat_g_couriers', value='${seeds.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}', serialized=1`);
  let manifestFirst = 'default';
  try { const m = JSON.parse(fs.readFileSync(WORK + '/oc/catalog/view/javascript/aichat/avatars/manifest.json', 'utf8')); const ids = m.map((x) => x.id); manifestFirst = ids.includes('default') ? 'default' : ids[0]; } catch (e) { /* no manifest: built-in default */ }
  fs.writeFileSync(WORK + '/mail_real.flag', '1');
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  try {
    await page.goto(ADMIN, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
    const token = new URL(page.url()).searchParams.get('user_token');
    check('admin login', !!token, page.url());
    const settingsUrl = `${ADMIN}?route=extension/module/aichat&user_token=${token}`;
    await page.goto(settingsUrl, { waitUntil: 'domcontentloaded' });

    // fields present
    for (const f of ['s_store_name', 's_notify_email', 's_attention', 's_attention_interval']) {
      check(`field s[0][${f}] present`, (await page.$(`[name="s[0][${f}]"]`)) !== null);
    }
    const checkedAvatar = await page.$eval('input[name="s[0][s_avatar]"]:checked', (e) => e.value).catch(() => '');
    check('avatar picker: unknown saved id shows the default (manifest default or its first avatar)', checkedAvatar === manifestFirst, checkedAvatar + ' vs ' + manifestFirst);
    const placeholder = await page.getAttribute('[name="s[0][s_store_name]"]', 'placeholder');
    check('store name field shows the automatic name', placeholder === 'Your Store', placeholder);
    const attentionInAppearance = await page.$eval('#tab-appearance [name="s[0][s_attention]"]', () => true).catch(() => false);
    check('attention switch is in the Appearance tab', attentionInAppearance);

    // courier advanced: pattern input behind <details>
    await page.click('#aichat-tabs a[href="#tab-couriers"]');
    const rows = await page.$$('#aichat-couriers .aichat-courier');
    check('courier rows (Speedy + NextLevel seeded)', rows.length >= 2, rows.length);
    const nl = await page.$$eval('#aichat-couriers .aichat-courier', (els) => els.map((e) => ({
      code: e.querySelector('input[name$="[code]"]').value,
      pattern: e.querySelector('input[name$="[pattern]"]') ? e.querySelector('input[name$="[pattern]"]').value : null,
      aliases: e.querySelector('input[name$="[aliases]"]') ? e.querySelector('input[name$="[aliases]"]').value : null,
      help: (e.querySelector('.aichat-courier-adv .help-block') || {}).textContent || '',
      open: e.querySelector('details.aichat-courier-adv') ? e.querySelector('details.aichat-courier-adv').open : null,
    })));
    const sp = nl.find((r) => r.code === 'speedy');
    const nx = nl.find((r) => r.code === 'nextlevel');
    check('pattern field per courier (seeded values), collapsed as "advanced"', sp && sp.pattern === '^6\\d{10}$' && nx && nx.pattern === '^1000\\d{9}$' && sp.open === false, JSON.stringify(nl));
    check('pattern help in plain language', sp && /6 followed by 10 more digits/.test(sp.help), sp && sp.help);
    const spIdx = nl.indexOf(sp);
    await page.click(`#aichat-couriers .aichat-courier:nth-child(${spIdx + 1}) details.aichat-courier-adv summary`);
    await page.fill(`#aichat-couriers .aichat-courier:nth-child(${spIdx + 1}) input[name$="[pattern]"]`, '([');

    // per-store values (store 0)
    await page.click('#aichat-tabs a[href="#tab-assistant"]');
    await page.fill('[name="s[0][s_store_name]"]', 'Админ Магазин');
    await page.click('#aichat-tabs a[href="#tab-appearance"]');
    await page.selectOption('[name="s[0][s_attention]"]', '0');
    await page.fill('[name="s[0][s_attention_interval]"]', '9');
    await page.click('#aichat-tabs a[href="#tab-contacts"]');
    await page.fill('[name="s[0][s_notify_email]"]', 'not-an-email');
    await Promise.all([page.waitForNavigation(), page.click('button[form="form-aichat"]')]);
    const html = await page.content();
    check('invalid courier pattern rejected with a message', html.includes('not a valid regular expression'));
    check('invalid notify e-mail rejected', (await page.$$eval('#tab-contacts .aichat-err', (e) => e.map((x) => x.textContent))).length > 0);
    // fix and save
    await page.click('#aichat-tabs a[href="#tab-couriers"]');
    const spRow = `#aichat-couriers .aichat-courier:nth-child(${spIdx + 1})`;
    if (!(await page.$eval(`${spRow} details.aichat-courier-adv`, (d) => d.open))) await page.click(`${spRow} details.aichat-courier-adv summary`);
    await page.fill(`${spRow} input[name$="[pattern]"]`, '^6\\d{10}$');
    await page.fill(`${spRow} input[name$="[aliases]"]`, 'Спиди, Speedy BG');
    await page.click('#aichat-tabs a[href="#tab-contacts"]');
    await page.fill('[name="s[0][s_notify_email]"]', 'requests@example.com');
    await Promise.all([page.waitForNavigation(), page.click('button[form="form-aichat"]')]);
    const saved = await page.content();
    check('settings saved', saved.includes('alert-success'), (await page.$$eval('.text-danger, .alert-danger', (e) => e.map((x) => x.textContent.trim()).filter(Boolean))).join(' | '));
    const v = (k, sid = 0) => sql(`SELECT value FROM oc_setting WHERE store_id=${sid} AND \`key\`='module_aichat_${k}'`);
    check('saved: s_store_name, s_notify_email, s_attention, s_attention_interval', v('s_store_name') === 'Админ Магазин' && v('s_notify_email') === 'requests@example.com' && v('s_attention') === '0' && v('s_attention_interval') === '9', [v('s_store_name'), v('s_notify_email'), v('s_attention'), v('s_attention_interval')].join('|'));
    check('saved: avatar fell back to the default', v('s_avatar') === manifestFirst, v('s_avatar'));
    const couriers = JSON.parse(v('g_couriers') || '[]');
    const spSaved = couriers.find((c) => c.code === 'speedy');
    check('saved: courier pattern + aliases', spSaved && spSaved.pattern === '^6\\d{10}$' && JSON.stringify(spSaved.aliases) === JSON.stringify(['Спиди', 'Speedy BG']), JSON.stringify(spSaved));

    // "Send test e-mail": store 0 (smtp 2525) with the saved notify address
    const before = smtpLog().length;
    await page.click('#aichat-tabs a[href="#tab-contacts"]');
    await page.click('.aichat-store[data-store="0"] .aichat-test-mail');
    await page.waitForFunction(() => { const e = document.querySelector('.aichat-store[data-store="0"] .aichat-test-mail-result'); return e && e.textContent && e.textContent !== '...'; }, null, { timeout: 20000 });
    let res = await page.textContent('.aichat-store[data-store="0"] .aichat-test-mail-result');
    let got = smtpLog().slice(before);
    check('test e-mail store 0: real result shown, arrived at SMTP 2525', /Sent to requests@example\.com \(engine: smtp\)/.test(res) && got.startsWith('=== 2525 ') && got.includes('RCPT TO: <requests@example.com>'), res + ' | ' + got.slice(0, 200));

    // store 1: its own SMTP settings, recipient = its config_email (notify empty)
    await page.selectOption('#aichat-store-select', '1');
    const before1 = smtpLog().length;
    await page.click('.aichat-store[data-store="1"] .aichat-test-mail');
    await page.waitForFunction(() => { const e = document.querySelector('.aichat-store[data-store="1"] .aichat-test-mail-result'); return e && e.textContent && e.textContent !== '...'; }, null, { timeout: 20000 });
    res = await page.textContent('.aichat-store[data-store="1"] .aichat-test-mail-result');
    got = smtpLog().slice(before1);
    const store1Email = sql("SELECT value FROM oc_setting WHERE store_id=1 AND `key`='config_email'");
    check('test e-mail store 1: store 1 SMTP (2526) from the admin (store 0 context)', res.includes('Sent to ' + store1Email) && got.startsWith('=== 2526 '), res + ' | ' + got.slice(0, 200));

    // failure is reported, not hidden
    cfg(1, 'config_mail_smtp_port', '1');
    await page.click('.aichat-store[data-store="1"] .aichat-test-mail');
    await page.waitForFunction(() => { const e = document.querySelector('.aichat-store[data-store="1"] .aichat-test-mail-result'); return e && /Not sent/.test(e.textContent); }, null, { timeout: 20000 }).catch(() => {});
    res = await page.textContent('.aichat-store[data-store="1"] .aichat-test-mail-result');
    check('test e-mail failure shows the real error', /Not sent to .* \(engine: smtp\): .*refused/i.test(res), res);
    cfg(1, 'config_mail_smtp_port', '2526');

    // Requests page: order type, "e-mail not sent"
    const failedId = fs.existsSync(WORK + '/round4_failed_request.txt') ? fs.readFileSync(WORK + '/round4_failed_request.txt', 'utf8').trim() : '';
    await page.goto(`${ADMIN}?route=extension/module/aichat/requests&user_token=${token}&filter_mail_failed=1`, { waitUntil: 'domcontentloaded' });
    const ids = await page.$$eval('tr.aichat-rq-row', (r) => r.map((x) => x.getAttribute('data-id')));
    const flagged = await page.$$eval('tr.aichat-rq-row .aichat-rq-mailfail', (r) => r.length);
    check('requests: "e-mail not sent" filter + label', failedId && ids.includes(failedId) && flagged === ids.length && ids.length > 0, JSON.stringify(ids) + ' ' + failedId);
    await page.click(`tr.aichat-rq-row[data-id="${failedId}"] .aichat-rq-details`);
    const detail = await page.textContent(`tr.aichat-rq-row[data-id="${failedId}"] + tr.aichat-rq-detail`);
    check('requests: error text in the details', /refused/i.test(detail), detail.replace(/\s+/g, ' ').slice(0, 300));
    await page.goto(`${ADMIN}?route=extension/module/aichat/requests&user_token=${token}&filter_type=order`, { waitUntil: 'domcontentloaded' });
    const orderRows = await page.$$eval('tr.aichat-rq-row', (r) => r.map((x) => x.textContent.replace(/\s+/g, ' ')));
    check('requests: order change type listed', orderRows.length > 0 && orderRows.every((t) => /Order change/.test(t)), JSON.stringify(orderRows).slice(0, 300));
    await page.click('tr.aichat-rq-row .aichat-rq-details');
    const od = (await page.textContent('tr.aichat-rq-row + tr.aichat-rq-detail')).replace(/\s+/g, ' ');
    check('requests: order request details (kind, verified, e-mailed)', /Verified/.test(od) && /(order number \+ phone match|logged-in customer)/.test(od) && /sent to/.test(od), od.slice(0, 400));
    check('no JS errors in the admin pages', errors.length === 0, errors.join(' | '));
  } catch (e) {
    check('admin browser run', false, e.stack);
  } finally {
    await browser.close();
    try { fs.unlinkSync(WORK + '/mail_real.flag'); } catch (e) { /* none */ }
    setting(0, 's_store_name', null);
    setting(0, 's_attention', null);
    setting(0, 's_attention_interval', null);
    setting(0, 's_notify_email', 'shop0@example.com');
  }
  console.log(results.join('\n'));
  const failed = results.filter((r) => r.startsWith('FAIL')).length;
  console.log(failed ? failed + ' FAILED' : 'admin round 4: all passed');
  process.exit(failed ? 1 : 0);
})();
