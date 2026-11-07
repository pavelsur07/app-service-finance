// Браузерный тест модалов маппинга Stage 2 PR E (tests/e2e/mapping-modals.html). Запуск из site/:
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/mapping-modals.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/mapping-modals.html', '/assets/api/client': 'tests/e2e/api-client-stub.js' };
const requests = [];
const json = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
const costPreview = {
  ok: true,
  summary: { will_create: 1, will_fill_empty: 0, skipped_existing: 1, missing_cost_category: 1 },
  hasBlockingIssues: false,
  items: [
    { status: 'will_create', confidence: 'high', costCode: 'LOG', costCategoryName: '<img src=x onerror="window.pwned=1">', plCode: 'PL1', plCategoryName: 'Логистика', note: 'n', message: 'm' },
    { status: 'skipped_existing', confidence: 'low', costCode: 'ADS', costCategoryName: 'Реклама', plCode: 'PL2', plCategoryName: 'Маркетинг', note: '', message: '' },
  ],
};
const salePreview = {
  ok: true,
  summary: { will_create: 2 },
  hasBlockingIssues: false,
  warnings: ['Конфликт источников <b>'],
  items: [
    { status: 'will_create', statusLabel: 'Будет создано', operationType: 'sale', amountSource: 'sale_revenue', amountSourceLabel: 'Выручка <i>', plCode: 'REV', plCategoryName: 'Выручка', isNegative: false, signMismatch: false, message: 'ok' },
    { status: 'skipped_existing', statusLabel: 'Уже настроено', operationType: 'return', amountSource: 'return_amount', amountSourceLabel: 'Возвраты', existingPlCategoryName: 'Возвраты ручные', isNegative: false, expectedNegative: true, signMismatch: true, message: 'exists' },
  ],
};

const server = http.createServer((req, res) => {
  const [pathname] = req.url.split('?');
  if (req.method !== 'GET') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      requests.push({ method: req.method, url: req.url, body, csrf: req.headers['x-csrf-token'] ?? null, type: req.headers['content-type'] ?? '' });
      if (pathname === '/api/update/c-1') return json(res, 200, { costCategoryId: 'c-1', plCategoryId: 'pl-2', includeInPl: false, sortOrder: 9 });
      if (pathname === '/api/update/c-2') return json(res, 422, { error: { code: 'sort_order_invalid', message: 'Порядок — целое число от 0 до 1000.' } });
      if (pathname === '/cost/preview') return json(res, 200, costPreview);
      if (pathname === '/cost/apply') return json(res, 200, { ok: false, message: 'Нет прав <b>' });
      if (pathname === '/sale/preview') return json(res, 200, salePreview);
      if (pathname === '/sale/apply') return json(res, 200, { ok: true, summary: { created: 2, skipped: 0 } });
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
  const url = `http://127.0.0.1:${server.address().port}/`;
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium-browser', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const p = await browser.newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.message));
  // Ответы 4xx сервера-заглушки — ожидаемые ветки ошибок, не ошибки страницы.
  p.on('console', (m) => m.type() === 'error' && !m.text().startsWith('Failed to load resource') && errs.push(m.text()));
  await p.goto(url, { waitUntil: 'networkidle0' });
  await p.waitForFunction(() => window.ready === true);

  let failed = 0;
  let passed = 0;
  const t = async (name, fn) => {
    let r;
    try { r = await fn(); } catch (e) { r = e.message; }
    if (r === true) { passed += 1; console.log('OK  ', name); } else { failed += 1; console.log('FAIL', name, r); }
  };
  const last = (urlPart) => [...requests].reverse().find((r) => r.url.startsWith(urlPart));
  const text = (sel) => p.evaluate((s) => document.querySelector(s)?.textContent.trim(), sel);
  const visible = (sel) => p.evaluate((s) => !document.querySelector(s).classList.contains('d-none'), sel);

  // delete modal
  await t('delete modal takes name and action from trigger; opens only with trigger', async () => {
    const s = await p.evaluate(() => ({
      prevented: window.bs('modal-delete-cost-category', 'show.bs.modal', document.querySelector('#del-1')),
      name: document.querySelector('[data-delete-cost-category-name]').textContent,
      action: new URL(document.querySelector('[data-delete-cost-category-form]').action).pathname,
      noTrigger: window.bs('modal-delete-cost-category', 'show.bs.modal', null),
    }));
    return (!s.prevented && s.name === '"Доставка <b>"' && s.action === '/cost/c-1/delete' && s.noTrigger) || JSON.stringify(s);
  });

  // edit modal
  await t('edit modal fills fields from row; dangling pl category → warning and empty select', async () => {
    const s = await p.evaluate(() => {
      window.bs('modal-edit-cost-mapping', 'show.bs.modal', document.querySelector('#edit-1'));
      const a = { name: document.querySelector('[data-edit-mapping-name]').textContent, pl: document.querySelector('[data-edit-mapping-pl]').value, inc: document.querySelector('[data-edit-mapping-include]').checked, sort: document.querySelector('[data-edit-mapping-sort]').value, dangling: !document.querySelector('[data-edit-mapping-dangling]').classList.contains('d-none') };
      window.bs('modal-edit-cost-mapping', 'show.bs.modal', document.querySelector('#edit-2'));
      const b = { pl: document.querySelector('[data-edit-mapping-pl]').value, inc: document.querySelector('[data-edit-mapping-include]').checked, dangling: !document.querySelector('[data-edit-mapping-dangling]').classList.contains('d-none') };
      return { a, b };
    });
    return (s.a.name === 'Доставка <b>' && s.a.pl === 'pl-1' && s.a.inc && s.a.sort === '5' && !s.a.dangling && s.b.pl === '' && !s.b.inc && s.b.dangling) || JSON.stringify(s);
  });
  await t('edit submit error → message shown, modal stays, submit enabled again', async () => {
    await p.evaluate(() => { document.querySelector('[data-edit-mapping-sort]').value = '7'; document.querySelector('[data-edit-mapping-form]').requestSubmit(); });
    await p.waitForFunction(() => !document.querySelector('[data-edit-mapping-error]').classList.contains('d-none'));
    const r = last('/api/update/c-2');
    const s = await p.evaluate(() => ({ err: document.querySelector('[data-edit-mapping-error]').textContent, disabled: document.querySelector('#edit-submit').disabled, hidden: window.hidden.slice(), prevented: window.submits.map((x) => x.prevented).join() }));
    return (r && r.csrf === 'upd-token' && JSON.stringify(JSON.parse(r.body)) === '{"plCategoryId":null,"includeInPl":false,"sortOrder":7}'
      && s.err === 'Порядок — целое число от 0 до 1000.' && !s.disabled && s.hidden.length === 0 && s.prevented === 'true') || JSON.stringify({ r, s });
  });
  await t('edit submit success → row re-rendered via textContent, modal hidden', async () => {
    await p.evaluate(() => {
      window.bs('modal-edit-cost-mapping', 'show.bs.modal', document.querySelector('#edit-1'));
      document.querySelector('[data-edit-mapping-pl]').value = 'pl-2';
      document.querySelector('[data-edit-mapping-include]').checked = false;
      document.querySelector('[data-edit-mapping-sort]').value = '9';
      document.querySelector('[data-edit-mapping-form]').requestSubmit();
    });
    await p.waitForFunction(() => window.hidden.includes('modal-edit-cost-mapping'));
    const r = last('/api/update/c-1');
    const s = await p.evaluate(() => {
      const row = document.querySelector('#row-1');
      return { pl: row.querySelector('[data-cell-pl]').textContent, i: row.querySelectorAll('i').length, inc: row.querySelector('[data-cell-include]').textContent, sort: row.querySelector('[data-cell-sort]').textContent, attrs: [row.dataset.plCategoryId, row.dataset.includeInPl, row.dataset.sortOrder].join() };
    });
    return (r && JSON.parse(r.body).plCategoryId === 'pl-2' && s.pl === 'Реклама <i>' && s.i === 0 && s.inc === 'Нет' && s.sort === '9' && s.attrs === 'pl-2,0,9') || JSON.stringify({ r, s });
  });

  // default cost mapping modal
  await t('cost preview on shown: posts JSON with token, renders cards/tabs via textContent, apply enabled', async () => {
    await p.evaluate(() => window.bs('modal-default-cost-mapping', 'shown.bs.modal'));
    await p.waitForFunction(() => !document.querySelector('#cost-apply').disabled);
    const r = last('/cost/preview');
    const s = await p.evaluate(() => ({
      cards: [...document.querySelectorAll('[data-default-mapping-preview] .card .h2')].map((n) => n.textContent).join(),
      tabs: document.querySelectorAll('[data-default-mapping-preview] .nav-link').length,
      first: document.querySelector('#map-tab-will_create .small').textContent,
      pwned: window.pwned === 1, img: document.querySelectorAll('img').length,
      warning: !document.querySelector('[data-default-mapping-warning]').classList.contains('d-none'),
      hint: document.querySelector('[data-default-mapping-apply-hint]').textContent,
      loading: document.querySelector('[data-default-mapping-loading]').classList.contains('d-none'),
    }));
    return (r && r.type === 'application/json' && JSON.stringify(JSON.parse(r.body)) === '{"marketplace":"ozon","_token":"cost-token"}'
      && s.cards === '1,0,1,0,1,0,0' && s.tabs === 7 && s.first.includes('LOG — <img src=x onerror="window.pwned=1">') && !s.pwned && s.img === 0 && s.warning && s.hint === '' && s.loading) || JSON.stringify({ r, s });
  });
  await t('cost apply: {ok:false} with 200 → error text, button label restored', async () => {
    await p.click('#cost-apply');
    await p.waitForFunction(() => !document.querySelector('[data-default-mapping-error]').classList.contains('d-none'));
    const s = await p.evaluate(() => ({ err: document.querySelector('[data-default-mapping-error]').textContent, label: document.querySelector('#cost-apply').textContent, b: document.querySelectorAll('[data-default-mapping-error] b').length }));
    return (s.err === 'Нет прав <b>' && s.b === 0 && s.label === 'Применить базовый маппинг') || JSON.stringify(s);
  });
  await t('cost preview without marketplace → error, no request', async () => {
    const before = requests.length;
    await p.evaluate(() => { document.querySelector('[data-marketplace-select]').value = ''; window.bs('modal-default-cost-mapping', 'hidden.bs.modal'); window.bs('modal-default-cost-mapping', 'shown.bs.modal'); });
    const s = await p.evaluate(() => ({ err: document.querySelector('[data-default-mapping-error]').textContent, disabled: document.querySelector('#cost-apply').disabled, hint: document.querySelector('[data-default-mapping-apply-hint]').textContent }));
    return (requests.length === before && s.err.startsWith('Выберите маркетплейс') && s.disabled && s.hint === 'Сначала загрузите preview.') || JSON.stringify(s);
  });

  // default sale mapping modal
  await t('sale preview: form-data with token, table via textContent, conflicts/mismatch alerts, hint', async () => {
    await p.evaluate(() => window.bs('modal-default-sale-mapping', 'shown.bs.modal'));
    await p.waitForFunction(() => !document.querySelector('#sale-apply').disabled);
    const r = last('/sale/preview');
    const s = await p.evaluate(() => ({
      rows: document.querySelectorAll('[data-default-sale-mapping-preview] tbody tr').length,
      cells: [...document.querySelectorAll('[data-default-sale-mapping-preview] tbody tr:first-child td')].map((td) => td.textContent.replace(/\s+/g, ' ').trim()).join('|'),
      second: document.querySelector('[data-default-sale-mapping-preview] tbody tr:nth-child(2)').textContent.replace(/\s+/g, ' '),
      i: document.querySelectorAll('[data-default-sale-mapping-preview] i, [data-default-sale-mapping-conflicts] b').length,
      conflicts: document.querySelector('[data-default-sale-mapping-conflicts]').textContent,
      mismatch: !document.querySelector('[data-default-sale-mapping-mismatch]').classList.contains('d-none'),
      hint: document.querySelector('[data-default-sale-mapping-hint]').textContent,
    }));
    return (r && r.type.startsWith('multipart/form-data') && /name="_token"\r\n\r\nsale-token/.test(r.body) && /name="marketplace"\r\n\r\nwildberries/.test(r.body)
      && s.rows === 2 && s.cells === 'Продажа|Выручка <i>sale_revenue|Выручка (REV)|плюс|Будет созданоok'
      && s.second.includes('Возвраты ручные') && s.second.includes('настроено вручную') && s.second.includes('ожидается «минус»')
      && s.i === 0 && s.conflicts === 'Конфликт источников <b>' && s.mismatch && s.hint === 'Будет создано правил: 2') || JSON.stringify({ r, s });
  });
  await t('sale apply success → summary text, button stays disabled until reload', async () => {
    await p.click('#sale-apply');
    await p.waitForFunction(() => !document.querySelector('[data-default-sale-mapping-success]').classList.contains('d-none'));
    const s = await p.evaluate(() => ({ ok: document.querySelector('[data-default-sale-mapping-success]').textContent, disabled: document.querySelector('#sale-apply').disabled }));
    return (s.ok === 'Готово. Создано правил: 2, пропущено: 0' && s.disabled) || JSON.stringify(s);
  });

  await t('no page errors', async () => errs.length === 0 || errs.join('\n'));
  console.log(`\n${passed} passed, ${failed} failed`);
  await browser.close();
  server.close();
  process.exit(failed ? 1 : 0);
})();
