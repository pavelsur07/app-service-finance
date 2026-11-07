// Браузерный тест модулей маркетплейсов Stage 2 PR D (tests/e2e/marketplace-modules.html). Запуск из site/:
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/marketplace-modules.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/marketplace-modules.html', '/assets/api/client': 'tests/e2e/api-client-stub.js' };
const requests = [];
const json = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(body === undefined ? '' : JSON.stringify(body)); };
const preflight = {
  can_close: true,
  checks: [
    { label: '<img src=x onerror="window.pwned=1">', message: 'ok', passed: true, blocking: true, details: [] },
    { label: 'Себестоимость', message: 'нет у 2 SKU', passed: false, blocking: false, details: [
      { marketplace_sku: 'SKU<1>', supplier_sku: 'ART-1', count: 3 }, { orphan: true, count: 1 }, { unknown_shape: 1 },
    ] },
  ],
};

const server = http.createServer((req, res) => {
  const [pathname] = req.url.split('?');
  if (req.method !== 'GET') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      requests.push({ method: req.method, url: req.url, body, csrf: req.headers['x-csrf-token'] ?? null, type: req.headers['content-type'] ?? '' });
      if (pathname === '/preflight') return json(res, 200, preflight);
      if (pathname === '/rebuild') return json(res, requests.filter((r) => r.url === '/rebuild').length === 1 ? 202 : 429, requests.filter((r) => r.url === '/rebuild').length === 1 ? { queued: true } : { error: 'Подождите минуту.' });
      if (pathname === '/api/bulk') return json(res, 422, { error: 'Invalid or missing month' });
      if (pathname === '/api/cost-mappings' && req.method === 'POST') return json(res, 422, { type: 'DOMAIN_ERROR', message: 'Такой маппинг уже есть' });
      if (pathname === '/api/cost-mappings/m-1' && req.method === 'DELETE') return json(res, 204);
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
  const alerts = () => p.evaluate(() => window.alerts.slice());
  const last = (urlPart) => [...requests].reverse().find((r) => r.url.startsWith(urlPart));

  // month_close: preflight
  await t('preflight posts form fields with token and renders checks via textContent', async () => {
    await p.click('#preflight');
    await p.waitForFunction(() => !document.querySelector('[data-preflight-result="costs"]').hidden);
    const r = last('/preflight');
    const s = await p.evaluate(() => ({
      items: document.querySelectorAll('[data-preflight-result="costs"] .list-group-item').length,
      label: document.querySelector('[data-preflight-result="costs"] .fw-medium').textContent,
      details: [...document.querySelectorAll('[data-preflight-result="costs"] li')].map((li) => li.textContent),
      pwned: window.pwned === 1,
      img: document.querySelectorAll('[data-preflight-result="costs"] img').length,
      closeEnabled: !document.querySelector('[data-close-stage="costs"]').disabled,
      button: document.querySelector('#preflight').textContent,
      svg: document.querySelectorAll('#preflight svg').length,
      busy: document.querySelector('#preflight').disabled,
    }));
    const ok = r && r.type.startsWith('multipart/form-data') && /name="_token"\r\n\r\npf-token/.test(r.body) && /name="stage"\r\n\r\ncosts/.test(r.body)
      && s.items === 2 && s.label === '<img src=x onerror="window.pwned=1">' && !s.pwned && s.img === 0
      && s.details.join('|') === 'SKU SKU<1> (артикул: ART-1) — 3 шт.|Без привязки к листингу — 1 шт.'
      && s.closeEnabled && s.button === 'Проверить готовность' && s.svg === 1 && !s.busy;
    return ok || JSON.stringify({ r, s });
  });

  // month_close: rebuild
  await t('rebuild posts JSON with token; 202 → alert, 429 → server message', async () => {
    await p.click('#rebuild');
    await p.waitForFunction(() => window.alerts.length === 1);
    await p.click('#rebuild');
    await p.waitForFunction(() => window.alerts.length === 2);
    const r = last('/rebuild');
    const a = await alerts();
    const restored = await p.evaluate(() => document.querySelector('#rebuild').textContent === 'Пересчитать сейчас' && !document.querySelector('#rebuild').disabled);
    const ok = r && r.type === 'application/json' && JSON.parse(r.body)._token === 'rb-token' && JSON.parse(r.body).year === 2026
      && a[0] === 'Запущен пересчёт. Обновите страницу через минуту.' && a[1] === 'Подождите минуту.' && restored;
    return ok || JSON.stringify({ r, a, restored });
  });

  // marketplace/index: apply realization modal
  await t('apply-realization modal takes action and token from trigger', async () => {
    const s = await p.evaluate(() => {
      const modal = document.querySelector('#modal-apply-realization');
      modal.dispatchEvent(Object.assign(new Event('show.bs.modal', { bubbles: true, cancelable: true }), { relatedTarget: document.querySelector('#apply-trigger') }));
      return { action: new URL(modal.querySelector('form').action).pathname, token: modal.querySelector('input').value };
    });
    return (s.action === '/raw/doc-1/apply' && s.token === 'apply-token') || JSON.stringify(s);
  });
  await t('apply-realization modal without trigger is not opened', async () => p.evaluate(() => {
    const ev = new Event('show.bs.modal', { bubbles: true, cancelable: true });
    document.querySelector('#modal-apply-realization').dispatchEvent(ev);
    return ev.defaultPrevented;
  }));

  // marketplace/index: bulk process
  await t('bulk process validates inputs, posts JSON, shows server error and resets button', async () => {
    await p.click('#bulk-submit');
    await p.select('[data-bulk-marketplace]', 'ozon');
    await p.evaluate(() => { document.querySelector('[data-bulk-month]').value = '2026-05'; });
    await p.click('#bulk-submit');
    await p.waitForFunction(() => window.alerts.length === 4);
    const r = last('/api/bulk');
    const a = await alerts();
    const s = await p.evaluate(() => ({ hidden: window.hidden.slice(), label: document.querySelector('#bulk-submit').textContent, disabled: document.querySelector('#bulk-submit').disabled }));
    const ok = a[2] === 'Выберите маркетплейс и месяц' && r && JSON.stringify(JSON.parse(r.body)) === '{"marketplace":"ozon","year":2026,"month":5}'
      && a[3] === 'Ошибка: Invalid or missing month' && s.hidden.join() === 'bulkProcessModal' && s.label === 'Запустить обработку' && !s.disabled;
    return ok || JSON.stringify({ r, a, s });
  });

  // cost_mappings: add
  await t('add mapping validates, posts JSON with category name and shows server message', async () => {
    await p.click('#add');
    await p.select('[data-cost-mapping-type]', 'logistics_to');
    await p.select('[data-cost-mapping-category]', 'cat-1');
    await p.click('#add');
    await p.waitForFunction(() => window.alerts.length === 6);
    const r = last('/api/cost-mappings');
    const a = await alerts();
    const enabled = await p.evaluate(() => !document.querySelector('#add').disabled);
    const ok = a[4] === 'Выберите статью и категорию затрат' && r && r.method === 'POST'
      && JSON.stringify(JSON.parse(r.body)) === '{"marketplace":"wildberries","unitEconomyCostType":"logistics_to","costCategoryId":"cat-1","costCategoryName":"Логистика <b>"}'
      && a[5] === 'Такой маппинг уже есть' && enabled;
    return ok || JSON.stringify({ r, a, enabled });
  });

  // cost_mappings: delete through confirm dialog
  await t('confirm dialog submit → DELETE with token, row removed, form not posted', async () => {
    await p.evaluate(() => {
      const modal = document.querySelector('#cost-mapping-confirm');
      modal.dispatchEvent(Object.assign(new Event('show.bs.modal', { bubbles: true, cancelable: true }), { relatedTarget: document.querySelector('#del-1') }));
      modal.querySelector('form').requestSubmit();
    });
    await p.waitForFunction(() => !document.querySelector('#row-1'));
    const r = last('/api/cost-mappings/m-1');
    const s = await p.evaluate(() => ({ submits: window.submits.map((x) => x.prevented).join(), hidden: window.hidden.slice(-1)[0] }));
    const ok = r && r.method === 'DELETE' && r.csrf === 'del-token' && s.submits === 'true' && s.hidden === 'cost-mapping-confirm';
    return ok || JSON.stringify({ r, s });
  });

  // cost_mappings: marketplace switch navigates (last: leaves the page)
  await t('marketplace select change navigates to index url with query', async () => {
    await Promise.all([p.waitForNavigation(), p.select('[data-cost-mapping-marketplace]', 'ozon')]);
    return new URL(p.url()).search === '?marketplace=ozon' || p.url();
  });

  await t('no page errors', async () => errs.length === 0 || errs.join('\n'));
  console.log(`\n${passed} passed, ${failed} failed`);
  await browser.close();
  server.close();
  process.exit(failed ? 1 : 0);
})();
