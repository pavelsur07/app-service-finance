// Браузерный тест страницы «Операции ДДС» Stage 2 PR H (tests/e2e/cash-transactions.html). Запуск из site/:
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/cash-transactions.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/cash-transactions.html', '/assets/api/client': 'tests/e2e/api-client-stub.js' };
const requests = [];
const json = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
const applyForm = (id) => `<p>Правило <b>R</b></p><form method="post" data-auto-rule-apply action="/auto-rule/apply/${id}">`
  + '<input type="hidden" name="ruleId" value="r1"><input type="hidden" name="_token" value="tok-ar">'
  + '<button type="submit" id="ar-submit">Применить</button></form>';

const server = http.createServer((req, res) => {
  const [pathname] = req.url.split('?');
  if (req.method === 'GET' && pathname.startsWith('/auto-rule/match/')) {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(applyForm(pathname.split('/').pop()));
  }
  if (req.method !== 'GET') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      requests.push({ method: req.method, url: req.url, body, type: req.headers['content-type'] ?? '' });
      // Значение поля multipart-формы: name="x"\r\n\r\nvalue
      const field = (name) => (body.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)`)) || [])[1];
      // Ответ с задержкой — чтобы второй submit успел прийти «в полёте».
      if (pathname === '/auto-rule/apply/tx-1') return setTimeout(() => json(res, 200, { ok: true, changed: true, ruleName: 'Аренда <b>x</b>', action: 'set_category' }), 300);
      if (pathname === '/auto-rule/apply/tx-2') return json(res, 403, { ok: false, changed: false, reason: 'invalid_csrf_token', message: 'Недействительный CSRF-токен.' });
      if (pathname === '/create-from/tx-1' && field('confirmed') === '1') return json(res, 200, { redirect: '/documents/d1/edit' });
      if (pathname === '/create-from/tx-1') return json(res, 200, { needsConfirmation: true, warningMessage: 'Категория без маппинга <b>ОПиУ</b>' });
      if (pathname === '/create-from/tx-2') return json(res, 200, { success: true, flash: 'Документ ОПиУ создан.' });
      if (pathname === '/create-from/tx-3') return json(res, 422, { error: true, message: 'Транзакция уже полностью разнесена.' });
      return json(res, 404, {});
    });
    return;
  }
  const rel = ROUTES[pathname] || pathname.replace(/^\//, '');
  const file = path.resolve(ROOT, rel);
  if (!file.startsWith(ROOT) || !fs.existsSync(file)) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${server.address().port}/`;
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium-browser', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const p = await browser.newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.message));
  p.on('console', (m) => m.type() === 'error' && !m.text().startsWith('Failed to load resource') && errs.push(m.text()));
  await p.goto(base, { waitUntil: 'networkidle0' });
  await p.waitForFunction(() => window.ready === true);

  let failed = 0;
  const t = async (name, fn) => {
    try {
      const r = await fn();
      if (r !== true) throw new Error(typeof r === 'string' ? r : 'assertion failed');
      console.log(`ok   ${name}`);
    } catch (e) { failed += 1; console.log(`FAIL ${name}: ${e.message}`); }
  };
  const field = (body, name) => (body.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)`)) || [])[1];
  const text = (sel) => p.$eval(sel, (n) => n.textContent.trim());
  const state = () => p.evaluate(() => ({
    selectionHidden: document.querySelector('#selection').hidden,
    summaryHidden: document.querySelector('#summary').hidden,
    count: document.querySelector('#sel-count').textContent,
    label: document.querySelector('#del-label').textContent,
    disabled: document.querySelector('#bulk-delete-button').disabled,
    allChecked: document.querySelector('#select-all').checked,
    indeterminate: document.querySelector('#select-all').indeterminate,
    active: Array.from(document.querySelectorAll('tr.table-active')).map((tr) => tr.id).join(),
    bdCount: document.querySelector('#bd-count').textContent,
  }));

  await t('pop: shown.bs.dropdown focuses the search field', async () => {
    await p.evaluate(() => window.bs('pop-toggle', 'shown.bs.dropdown'));
    const id = await p.evaluate(() => document.activeElement.id);
    return id === 'pop-search' || id;
  });
  await t('pop: search filters items and toggles the empty hint', async () => {
    await p.type('#pop-search', 'Зар');
    const a = await p.evaluate(() => [document.querySelector('#pop-1').hidden, document.querySelector('#pop-2').hidden, document.querySelector('#pop-empty').hidden]);
    await p.type('#pop-search', 'xx');
    const b = await p.evaluate(() => [document.querySelector('#pop-2').hidden, document.querySelector('#pop-empty').hidden]);
    return (a.join() === 'true,false,true' && b.join() === 'true,false') || JSON.stringify({ a, b });
  });
  await t('pop: ArrowDown moves focus to the first visible item, or to the first item when the search is empty', async () => {
    await p.$eval('#pop-search', (i) => { i.value = 'зар'; i.dispatchEvent(new Event('input')); });
    await p.focus('#pop-search');
    await p.keyboard.press('ArrowDown');
    const first = await p.evaluate(() => document.activeElement.id);
    await p.$eval('#pop-search', (i) => { i.value = ''; i.dispatchEvent(new Event('input')); });
    await p.focus('#pop-search');
    await p.keyboard.press('ArrowDown');
    const second = await p.evaluate(() => document.activeElement.id);
    return (first === 'pop-2' && second === 'pop-all') || `${first} ${second}`;
  });

  await t('bulk: one row selected shows the selection bar, label, count and row highlight', async () => {
    await p.click('#row-1');
    const s = await state();
    return (s.selectionHidden === false && s.summaryHidden === true && s.count === 'Выбрано: 1' && s.label === 'Удалить (1)'
      && s.disabled === false && s.indeterminate === true && s.allChecked === false && s.active === 'tr-1' && s.bdCount === '1') || JSON.stringify(s);
  });
  await t('bulk: select-all checks every row; show.bs.modal renders hidden ids', async () => {
    await p.click('#select-all');
    await p.evaluate(() => window.bs('bulkDeleteModal', 'show.bs.modal'));
    const s = await state();
    const ids = await p.$$eval('#bd-ids input[type="hidden"][name="transaction_ids[]"]', (list) => list.map((i) => i.value).join());
    return (s.label === 'Удалить (2)' && s.allChecked === true && s.indeterminate === false && s.active === 'tr-1,tr-2' && ids === 'tx-1,tx-2') || JSON.stringify({ s, ids });
  });
  await t('bulk: form submit re-renders ids from the current selection', async () => {
    await p.click('#row-2');
    await p.evaluate(() => document.querySelector('#bd-form').requestSubmit());
    const ids = await p.$$eval('#bd-ids input', (list) => list.map((i) => i.value).join());
    const submitted = await p.evaluate(() => window.submits.map((x) => x.id).join());
    return (ids === 'tx-1' && submitted === 'bd-form') || `${ids} ${submitted}`;
  });
  await t('bulk: clear selection hides the bar and disables the button', async () => {
    await p.click('#clear');
    const s = await state();
    return (s.selectionHidden === true && s.summaryHidden === false && s.disabled === true && s.label === 'Удалить' && s.active === '') || JSON.stringify(s);
  });

  await t('auto-rule: click shows the modal and loads the server HTML into the body', async () => {
    await p.click('#auto-1');
    await p.waitForSelector('#ar-body [data-auto-rule-apply] #ar-submit');
    const shown = await p.evaluate(() => window.shown.join());
    return shown === 'autoRuleModal' || shown;
  });
  await t('auto-rule: double submit sends one POST, the button is locked, success renders the result', async () => {
    await p.evaluate(() => { const f = document.querySelector('[data-auto-rule-apply]'); f.requestSubmit(); f.requestSubmit(); });
    const locked = await p.$eval('#ar-submit', (b) => b.disabled);
    await p.waitForFunction(() => document.querySelector('#ar-body .alert-success'));
    const posts = requests.filter((r) => r.url === '/auto-rule/apply/tx-1');
    const body = await p.$eval('#ar-body', (n) => ({ alert: n.querySelector('.alert').textContent, rule: n.querySelector('strong').textContent, close: n.querySelector('[data-bs-dismiss="modal"]').className }));
    return (locked === true && posts.length === 1 && field(posts[0].body, 'ruleId') === 'r1' && field(posts[0].body, '_token') === 'tok-ar'
      && body.alert === 'Применено: да' && body.rule === 'Аренда <b>x</b>' && body.close === 'btn btn-primary') || JSON.stringify({ locked, posts: posts.length, body });
  });
  await t('auto-rule: 403 with ok:false shows the server message as a warning', async () => {
    await p.click('#auto-2');
    await p.waitForFunction(() => document.querySelector('#ar-body [data-auto-rule-apply][action="/auto-rule/apply/tx-2"]'));
    await p.click('#ar-submit');
    await p.waitForFunction(() => document.querySelector('#ar-body .alert-warning'));
    const txt = await text('#ar-body .alert-warning');
    return txt === 'Недействительный CSRF-токен.' || txt;
  });

  await t('create-from: needsConfirmation opens the warning modal with text and form action; the button stays enabled', async () => {
    await p.click('#cf-1');
    await p.waitForFunction(() => window.shown.includes('createFromWarningModal'));
    const s = await p.evaluate(() => ({
      body: document.querySelector('#cf-body').textContent,
      action: new URL(document.querySelector('#cf-form').action).pathname,
      token: document.querySelector('#cf-form [name="_token"]').value,
      disabled: document.querySelector('#cf-1').disabled,
    }));
    const req = requests.find((r) => r.url === '/create-from/tx-1');
    return (s.body === 'Категория без маппинга <b>ОПиУ</b>' && s.action === '/create-from/tx-1' && s.token === 'tok-cf' && s.disabled === false
      && field(req.body, '_token') === 'tok-cf' && field(req.body, 'confirmed') === '0') || JSON.stringify(s);
  });
  await t('create-from: confirm submit hides the modal, opens the document, marks the row and disables the button', async () => {
    await p.evaluate(() => document.querySelector('#cf-form').requestSubmit());
    await p.waitForFunction(() => window.opened.length === 1);
    const s = await p.evaluate(() => ({
      opened: window.opened[0], hidden: window.hidden.join(),
      icon: document.querySelector('#icon-1 i').className, title: document.querySelector('#icon-1 i').title,
      disabled: document.querySelector('#cf-1').disabled && document.querySelector('#cf-1').classList.contains('disabled'),
    }));
    const req = requests.filter((r) => r.url === '/create-from/tx-1').pop();
    return (s.opened === '/documents/d1/edit' && s.hidden === 'createFromWarningModal' && s.icon === 'ti ti-alert-triangle text-warning'
      && s.title.startsWith('Документ создан без полного маппинга') && s.disabled === true && field(req.body, 'confirmed') === '1') || JSON.stringify(s);
  });
  await t('create-from: silent success shows a flash and the green icon', async () => {
    await p.click('#cf-2');
    await p.waitForSelector('[data-create-from-flash]');
    const s = await p.evaluate(() => ({
      flash: document.querySelector('[data-create-from-flash]').textContent.trim(), cls: document.querySelector('[data-create-from-flash]').className,
      before: document.querySelector('#page').firstElementChild.hasAttribute('data-create-from-flash'),
      icon: document.querySelector('#icon-2 i').className, disabled: document.querySelector('#cf-2').disabled,
    }));
    return (s.flash === 'Документ ОПиУ создан.' && s.cls.includes('alert-success') && s.before && s.icon === 'ti ti-file-check text-success' && s.disabled) || JSON.stringify(s);
  });
  await t('create-from: server error replaces the flash with the message and re-enables the button', async () => {
    await p.click('#cf-3');
    await p.waitForFunction(() => document.querySelector('[data-create-from-flash].alert-danger'));
    const s = await p.evaluate(() => ({
      flashes: document.querySelectorAll('[data-create-from-flash]').length,
      text: document.querySelector('[data-create-from-flash]').textContent.trim(),
      disabled: document.querySelector('#cf-3').disabled, icon: document.querySelector('#icon-3 i').className,
    }));
    return (s.flashes === 1 && s.text === 'Ошибка: Транзакция уже полностью разнесена.' && s.disabled === false && s.icon === 'ti ti-file-plus text-secondary') || JSON.stringify(s);
  });

  await t('no page errors', async () => errs.length === 0 || errs.join('\n'));

  await browser.close();
  server.close();
  console.log(failed === 0 ? 'ALL PASSED' : `${failed} FAILED`);
  process.exit(failed === 0 ? 0 : 1);
})();
