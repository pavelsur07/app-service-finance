// Браузерный тест страничных модулей Stage 2 PR C (tests/e2e/page-modules.html). Запуск из site/:
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/page-modules.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.ts': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/page-modules.html' };
// Ответы «API статуса импорта»: сначала processing, затем done с сообщением, похожим на XSS.
const statusResponses = [
  { status: 'processing' },
  { status: 'done', rowsTotal: 3, rowsCreated: 2, rowsSkipped: 1, errors: [{ row: 2, reason: 'invalid', message: '<img src=x onerror="window.pwned=1">' }] },
];

const server = http.createServer((req, res) => {
  if (req.url === '/api/status') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(statusResponses.length > 1 ? statusResponses.shift() : statusResponses[0]));
    return;
  }
  // apiFetch импортируется из assets/api/client.ts — браузер TS не исполняет: подменяем модуль.
  if (req.url === '/assets/api/client') {
    res.writeHead(200, { 'Content-Type': 'text/javascript' });
    res.end('export async function apiFetch(url) { const r = await fetch(url, { headers: { Accept: "application/json" } }); if (!r.ok) throw new Error(String(r.status)); return r.json(); }');
    return;
  }
  const rel = ROUTES[req.url] || req.url.replace(/^\//, '');
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
  p.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
  await p.goto(url, { waitUntil: 'networkidle0' });
  await p.waitForFunction(() => window.ready === true);

  let failed = 0;
  let passed = 0;
  const t = async (name, fn) => {
    let r;
    try { r = await fn(); } catch (e) { r = e.message; }
    if (r === true) { passed += 1; console.log('OK  ', name); } else { failed += 1; console.log('FAIL', name, r); }
  };
  const submits = () => p.evaluate(() => window.submits.slice());

  // tax profile fields
  await t('tax: VAT options limited by regime on init', async () => {
    const s = await p.evaluate(() => [...document.querySelector('[data-vat-mode]').options].map((o) => `${o.value}:${o.hidden ? 'h' : 'v'}`).join());
    return s === ':v,none:v,vat5:v,vat20:h' || s;
  });
  await t('tax: regime change re-filters and picks first allowed', async () => {
    await p.select('[data-tax-regime]', 'osno');
    const s = await p.evaluate(() => { const v = document.querySelector('[data-vat-mode]'); return `${v.value}|${[...v.options].filter((o) => !o.hidden).map((o) => o.value).join()}`; });
    return s === 'vat20|,vat20' || s;
  });
  // money account
  await t('money account: number field hidden for cash, shown for bank', async () => {
    const before = await p.$eval('[data-money-account-number-field]', (n) => `${n.classList.contains('d-none')}/${n.querySelector('input').disabled}`);
    await p.click('[data-money-account-type="bank"]');
    const after = await p.$eval('[data-money-account-number-field]', (n) => `${n.classList.contains('d-none')}/${n.querySelector('input').disabled}`);
    return (before === 'true/true' && after === 'false/false') || `${before} ${after}`;
  });
  await t('money account: default badge follows checkbox', async () => {
    await p.click('[data-money-account-default]');
    return (await p.$eval('[data-money-account-default-badge]', (n) => !n.classList.contains('d-none'))) || 'badge hidden';
  });
  // pl category
  await t('pl: leaf → formula disabled, hint, LEAF badge visible', async () => {
    const s = await p.evaluate(() => `${document.querySelector('[data-pl-category-formula]').disabled}|${document.querySelector('[data-pl-category-formula-hint]').textContent}|${[...document.querySelectorAll('[data-pl-category-badge]')].filter((b) => !b.hidden).map((b) => b.dataset.plCategoryBadge).join()}`);
    return s === 'true|Лист: без формулы|LEAF_INPUT' || s;
  });
  await t('pl: KPI → formula enabled, KPI badge; expense type follows flow', async () => {
    await p.select('[data-pl-category-type]', 'KPI');
    await p.select('[data-pl-category-flow]', 'EXPENSE');
    const s = await p.evaluate(() => `${document.querySelector('[data-pl-category-formula]').disabled}|${[...document.querySelectorAll('[data-pl-category-badge]')].filter((b) => !b.hidden).map((b) => b.dataset.plCategoryBadge).join()}|${document.querySelector('[data-pl-category-expense-type]').disabled}`);
    return s === 'false|KPI|false' || s;
  });
  await t('pl: insert code button puts code into formula at cursor', async () => {
    await p.click('#ins');
    await p.click('#ins');
    return (await p.$eval('#formula', (n) => n.value)) === 'REVREV' || await p.$eval('#formula', (n) => n.value);
  });
  // import status
  await t('import status: polls until done and renders result as text (no XSS)', async () => {
    await p.waitForFunction(() => !document.getElementById('progress-block'), { timeout: 3000 });
    const s = await p.evaluate(() => ({
      badge: document.getElementById('status-badge').textContent,
      created: document.querySelector('#import-body .text-success')?.textContent,
      msg: document.querySelector('#import-body tbody td:last-child')?.textContent,
      img: !!document.querySelector('#import-body img'),
      pwned: window.pwned === 1,
      links: [...document.querySelectorAll('#import-body a')].map((a) => a.getAttribute('href')).join(),
    }));
    return (s.badge === 'Завершён' && s.created === '2' && s.msg === '<img src=x onerror="window.pwned=1">' && !s.img && !s.pwned && s.links === '/import,/products') || JSON.stringify(s);
  });
  // report filters
  await t('report filters: month-from change sets exact dates and submits', async () => {
    await p.$eval('[data-pl-month-from]', (n) => { n.value = '2026-03'; n.dispatchEvent(new Event('change', { bubbles: true })); });
    const s = await p.evaluate(() => `${document.querySelector('[data-pl-exact-from]').value}|${document.querySelector('[data-pl-exact-to]').value}|${document.querySelector('[data-pl-month-to]').value}`);
    return (s === '2026-03-01|2026-03-31|2026-03' && (await submits()).join() === 'rf') || `${s} ${(await submits()).join()}`;
  });
  await t('report filters: plural filter activation renames inputs, drops legacy, submits', async () => {
    await p.click('[data-pl-filter-input="project"][value="2"]');
    const s = await p.evaluate(() => `${!!document.querySelector('[data-pl-legacy-filter]')}|${document.querySelector('[data-pl-filter-marker]').disabled}|${[...document.querySelectorAll('[data-pl-filter-input]')].map((i) => i.name).join()}`);
    return (s === 'false|false|projects[],projects[]' && (await submits()).length === 2) || `${s} ${(await submits()).join()}`;
  });
  await t('no page errors', async () => errs.length === 0 || errs.join('; '));

  await browser.close();
  server.close();
  console.log(`${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
