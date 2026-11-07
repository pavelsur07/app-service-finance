// Браузерный тест страницы «Реклама маркетплейсов» Stage 2 PR F (tests/e2e/marketplace-ads.html). Запуск из site/:
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/marketplace-ads.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/marketplace-ads.html', '/assets/api/client': 'tests/e2e/api-client-stub.js' };
const requests = [];
const json = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
const jobs = { items: [
  { id: 'j-1', status: 'completed', dateFrom: '2026-10-01', dateTo: '2026-10-31', chunksTotal: 3, createdAt: '01.11.2026 10:00', finishedAt: '01.11.2026 11:00', lastError: '<img src=x onerror="window.pwned=1">', batchStats: { hasBatches: true, total: 4, ok: 3, failed: 1, pending: 0 }, extractToken: 'ext-1' },
  { id: 'j-2', status: 'running', dateFrom: '2026-11-01', dateTo: '2026-11-05', chunksTotal: 2, createdAt: '05.11.2026 09:00', finishedAt: null, lastError: null, batchStats: { hasBatches: false }, extractToken: null },
] };
const rawDocs = { items: [
  { id: 'd-1', reportDate: '2026-10-02', status: 'failed', loadedAt: '03.10.2026 04:00', processingError: 'bad <b>csv</b>', hasFile: true },
  { id: 'd-2', reportDate: '2026-10-03', status: 'processed', loadedAt: '04.10.2026 04:00', processingError: null, hasFile: false },
] };
let statusCalls = 0;

const server = http.createServer((req, res) => {
  const [pathname] = req.url.split('?');
  if (req.method === 'GET' && pathname === '/api/jobs') return json(res, 200, jobs);
  if (req.method === 'GET' && pathname === '/api/raw') { requests.push({ method: 'GET', url: req.url }); return json(res, 200, req.url.includes('dateFrom=2026-09-01') ? { items: [] } : rawDocs); }
  if (req.method === 'GET' && pathname === '/api/status') { statusCalls += 1; return json(res, 200, { status: 'running', progress: 40, completedChunks: 2, chunksTotal: 5, totalDocs: 10, processedDocs: 7, failedDocs: 1 }); }
  if (req.method !== 'GET') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      requests.push({ method: req.method, url: req.url, body, type: req.headers['content-type'] ?? '' });
      if (pathname === '/api/raw/d-1/reprocess') return json(res, 200, { status: 'reset_to_draft' });
      if (pathname === '/api/load-range') return json(res, 400, { message: 'Период больше 62 дней' });
      if (pathname === '/api/admin/j-2/mark-failed') return json(res, 404, { error: 'Job not found or already finalized' });
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
  p.on('console', (m) => m.type() === 'error' && !m.text().startsWith('Failed to load resource') && errs.push(m.text()));
  // Поллер прогресса не даёт сети стать idle — ждём только готовности страницы.
  await p.goto(url, { waitUntil: 'domcontentloaded' });
  await p.waitForFunction(() => window.ready === true);

  let failed = 0;
  let passed = 0;
  const t = async (name, fn) => {
    let r;
    try { r = await fn(); } catch (e) { r = e.message; }
    if (r === true) { passed += 1; console.log('OK  ', name); } else { failed += 1; console.log('FAIL', name, r); }
  };
  const last = (urlPart) => [...requests].reverse().find((r) => r.url.startsWith(urlPart));
  const alerts = () => p.evaluate(() => window.alerts.slice());

  await t('jobs table rendered via textContent; extract → confirm trigger, mark-failed only for running', async () => {
    await p.waitForFunction(() => document.querySelectorAll('[data-ad-jobs] tbody tr').length === 2);
    const s = await p.evaluate(() => {
      const rows = [...document.querySelectorAll('[data-ad-jobs] tbody tr')];
      const extract = rows[0].querySelector('[data-confirm-action]');
      return {
        err: rows[0].children[5].textContent, img: document.querySelectorAll('img').length, pwned: window.pwned === 1,
        progress0: rows[0].children[2].textContent.replace(/\s+/g, ' ').trim(), progress1: rows[1].children[2].textContent.trim(),
        badge0: rows[0].querySelector('.badge').textContent, badge1: rows[1].querySelector('.badge').textContent,
        extract: extract && [extract.dataset.bsTarget, extract.dataset.confirmAction, extract.dataset.confirmToken, extract.type].join('|'),
        markFailed0: rows[0].querySelectorAll('[data-job-id]').length, markFailed1: rows[1].querySelector('[data-job-id]')?.dataset.jobId,
        finished1: rows[1].children[4].textContent,
      };
    });
    return (s.err === '<img src=x onerror="window.pwned=1">' && s.img === 0 && !s.pwned && s.progress0 === '3/4 OK1 ошибок' && s.progress1 === 'Чанки: 2'
      && s.badge0 === 'Выполнено' && s.badge1 === 'Выполняется' && s.extract === '#ads-confirm|/jobs/j-1/extract|ext-1|button'
      && s.markFailed0 === 0 && s.markFailed1 === 'j-2' && s.finished1 === '—') || JSON.stringify(s);
  });
  await t('extract confirm dialog posts the job form (no page handler cancels it)', async () => {
    const s = await p.evaluate(() => {
      const modal = document.querySelector('#ads-confirm');
      window.bs('ads-confirm', 'show.bs.modal', document.querySelector('[data-ad-jobs] [data-confirm-action]'));
      const form = modal.querySelector('form');
      form.id = 'ads-confirm-form';
      form.requestSubmit();
      return { action: new URL(form.action).pathname, token: form.querySelector('[name=_token]').value, title: modal.querySelector('[data-confirm-title]').textContent, submits: window.submits.map((x) => `${x.id}:${x.prevented}`).join() };
    });
    return (s.action === '/jobs/j-1/extract' && s.token === 'ext-1' && s.title.startsWith('Запустить обработку') && s.submits === 'ads-confirm-form:false') || JSON.stringify(s);
  });
  await t('raw documents rendered: download link only with file, error text as text', async () => {
    await p.waitForFunction(() => document.querySelectorAll('[data-ad-raw-docs] tbody tr').length === 2);
    const s = await p.evaluate(() => {
      const rows = [...document.querySelectorAll('[data-ad-raw-docs] tbody tr')];
      return { href: new URL(rows[0].querySelector('a').href).pathname, links1: rows[1].querySelectorAll('a').length, err: rows[0].children[3].textContent, b: document.querySelectorAll('[data-ad-raw-docs] b').length, badge1: rows[1].querySelector('.badge').textContent, reprocess: rows[0].querySelector('[data-reprocess-doc]').dataset.reprocessDoc };
    });
    return (s.href === '/raw/d-1/download' && s.links1 === 0 && s.err === 'bad <b>csv</b>' && s.b === 0 && s.badge1 === 'Обработан' && s.reprocess === 'd-1') || JSON.stringify(s);
  });
  await t('filter submit reloads raw documents with dates, empty → «Нет данных»', async () => {
    await p.evaluate(() => { document.querySelector('[data-raw-date-from]').value = '2026-09-01'; document.querySelector('[data-raw-date-to]').value = '2026-09-30'; });
    await p.click('#raw-show');
    await p.waitForFunction(() => document.querySelector('[data-ad-raw-docs] tbody').textContent.includes('Нет данных'));
    const r = last('/api/raw?');
    const prevented = await p.evaluate(() => window.submits.filter((x) => x.id === 'raw-filter').map((x) => x.prevented).join());
    return (r && r.url === '/api/raw?dateFrom=2026-09-01&dateTo=2026-09-30' && prevented === 'true') || JSON.stringify({ r, prevented });
  });
  await t('reprocess posts to templated url and reloads the list', async () => {
    await p.evaluate(() => { document.querySelector('[data-raw-date-from]').value = '2026-10-01'; });
    await p.click('#raw-show');
    await p.waitForFunction(() => document.querySelectorAll('[data-ad-raw-docs] tbody tr').length === 2);
    const before = requests.filter((r) => r.url.startsWith('/api/raw?')).length;
    // Ждём оба ответа: POST reprocess и перечитку списка, иначе проверка зависит от скорости сети.
    const reprocessed = p.waitForResponse((res) => res.url().endsWith('/api/raw/d-1/reprocess'));
    const reloaded = p.waitForResponse((res) => res.url().includes('/api/raw?') && res.request().method() === 'GET');
    await p.click('[data-reprocess-doc="d-1"]');
    await Promise.all([reprocessed, reloaded]);
    await p.waitForFunction(() => document.querySelectorAll('[data-ad-raw-docs] tbody tr').length === 2);
    const r = last('/api/raw/d-1/reprocess');
    const after = requests.filter((r2) => r2.url.startsWith('/api/raw?')).length;
    return (r && r.method === 'POST' && after === before + 1) || JSON.stringify({ r, before, after });
  });
  await t('load range: validation alert, then server error → modal hidden, alert, button restored', async () => {
    await p.click('#range-submit');
    await p.evaluate(() => { document.querySelector('[data-range-from]').value = '2026-08-01'; });
    await p.click('#range-submit');
    await p.waitForFunction(() => window.alerts.length === 2);
    const r = last('/api/load-range');
    const a = await alerts();
    const s = await p.evaluate(() => ({ hidden: window.hidden.slice(), label: document.querySelector('#range-submit').textContent, disabled: document.querySelector('#range-submit').disabled }));
    return (a[0] === 'Укажите обе даты' && r && JSON.stringify(JSON.parse(r.body)) === '{"dateFrom":"2026-08-01","dateTo":"2026-10-31"}' && a[1] === 'Ошибка: Период больше 62 дней'
      && s.hidden.join() === 'modal-ozon-ad-load-range' && s.label === 'Загрузить' && !s.disabled) || JSON.stringify({ r, a, s });
  });
  await t('mark failed: job id from trigger, reason required, server {error} shown', async () => {
    await p.evaluate(() => window.bs('modal-mark-ad-load-job-failed', 'show.bs.modal', document.querySelector('[data-job-id="j-2"]')));
    await p.click('#mark-failed');
    await p.evaluate(() => { document.querySelector('[data-mark-failed-reason]').value = ' stuck '; });
    await p.click('#mark-failed');
    await p.waitForFunction(() => window.alerts.length === 4);
    const r = last('/api/admin/j-2/mark-failed');
    const a = await alerts();
    const s = await p.evaluate(() => ({ hidden: window.hidden.slice(-1)[0], label: document.querySelector('#mark-failed').textContent }));
    return (a[2] === 'Укажите причину' && r && JSON.stringify(JSON.parse(r.body)) === '{"reason":"stuck"}' && a[3] === 'Ошибка: Job not found or already finalized'
      && s.hidden === 'modal-mark-ad-load-job-failed' && s.label === 'Пометить FAILED') || JSON.stringify({ r, a, s });
  });
  await t('progress poller updates bar and counters while running', async () => {
    await p.waitForFunction(() => document.querySelector('[data-ad-counters]').textContent.startsWith('Чанков: 2/5'));
    const s = await p.evaluate(() => ({ width: document.querySelector('[data-ad-progress-bar]').style.width, text: document.querySelector('[data-ad-counters]').textContent }));
    return (s.width === '40%' && s.text === 'Чанков: 2/5, Документов: 10 (7 обработано, 1 ошибок)' && statusCalls >= 1) || JSON.stringify(s);
  });

  await t('no page errors', async () => errs.length === 0 || errs.join('\n'));
  console.log(`\n${passed} passed, ${failed} failed`);
  await browser.close();
  server.close();
  process.exit(failed ? 1 : 0);
})();
