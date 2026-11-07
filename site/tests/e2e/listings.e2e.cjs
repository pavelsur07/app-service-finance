// Браузерный тест реестра листингов и «Управления тегами» Stage 2 PR G (tests/e2e/listings.html). Запуск из site/:
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/listings.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/listings.html', '/assets/api/client': 'tests/e2e/api-client-stub.js' };
const requests = [];
const json = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };

const server = http.createServer((req, res) => {
  const [pathname] = req.url.split('?');
  if (req.method === 'GET' && pathname === '/api/products') return json(res, 200, [{ id: 'p1', sku: 'SKU-1', name: 'Плед <b>x</b>' }]);
  if (req.method !== 'GET') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      requests.push({ method: req.method, url: req.url, body, type: req.headers['content-type'] ?? '' });
      const data = body ? JSON.parse(body) : {};
      if (pathname === '/api/listings/l-1/map') return json(res, 400, { error: 'Товар не найден' });
      if (pathname === '/api/tags/assign' && data.filter) return json(res, 422, { error: { code: 'filter_too_broad', message: 'Сузьте фильтр' } });
      if (pathname === '/api/tags/assign') return json(res, 200, { tagName: 'Новинки', matched: 3, assigned: 2 });
      if (pathname === '/api/tags/detach') return json(res, 404, { error: { code: 'not_found', message: 'Тег не найден' } });
      if (pathname === '/api/tags/create') return json(res, 409, { error: { code: 'duplicate', message: 'Тег уже есть' } });
      if (pathname === '/api/tags/t1/rename') return json(res, 400, { error: { code: 'invalid', message: 'Слишком длинно' } });
      if (pathname === '/api/tags/t1/delete') return json(res, 409, { error: { code: 'in_use', message: 'Тег используется' } });
      if (pathname === '/api/tags/merge') return json(res, 200, { ok: true });
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
  const ready = () => p.waitForFunction(() => window.ready === true);
  // page=2 — чтобы проверить, что после назначения тега страница перечитывается с первой.
  await p.goto(`${base}?page=2&q=плед`, { waitUntil: 'networkidle0' });
  await ready();

  let failed = 0;
  const t = async (name, fn) => {
    try {
      const r = await fn();
      if (r !== true) throw new Error(typeof r === 'string' ? r : 'assertion failed');
      console.log(`ok   ${name}`);
    } catch (e) { failed += 1; console.log(`FAIL ${name}: ${e.message}`); }
  };
  const lastReq = () => requests[requests.length - 1];

  await t('filter: select with data-autosubmit submits the form, empty fields are disabled', async () => {
    await p.select('#filter-select', 'mapped');
    const s = await p.evaluate(() => ({ submits: window.submits.map((x) => x.id).join(), q: document.querySelector('#filter-q').disabled, f: document.querySelector('#filter-select').disabled }));
    await p.evaluate(() => document.querySelectorAll('#listings-filter-form [name]').forEach((f) => { f.disabled = false; }));
    return (s.submits === 'listings-filter-form' && s.q === true && s.f === false) || JSON.stringify(s);
  });

  await t('map modal: products loaded as options (textContent), sku shown, modal shown', async () => {
    await p.click('#map-1');
    await p.waitForFunction(() => document.querySelectorAll('#product-select option').length === 2);
    const s = await p.evaluate(() => ({
      sku: document.querySelector('#map-sku').textContent,
      opts: Array.from(document.querySelectorAll('#product-select option')).map((o) => `${o.value}:${o.textContent}`),
      shown: window.shown.join(), disabled: document.querySelector('#product-select').disabled,
    }));
    return (s.sku === 'WB-1' && s.opts.join('|') === ':— выберите товар —|p1:SKU-1 — Плед <b>x</b>' && s.shown === 'mapListingModal' && !s.disabled) || JSON.stringify(s);
  });

  await t('map modal: empty product → validation; server 400 → legacy {error} text, button restored', async () => {
    await p.click('#map-submit');
    const v = await p.evaluate(() => document.querySelector('#map-error').textContent);
    await p.select('#product-select', 'p1');
    await p.click('#map-submit');
    await p.waitForFunction(() => document.querySelector('#map-submit').textContent === 'Привязать' && !document.querySelector('#map-submit').disabled);
    const r = lastReq();
    const e = await p.evaluate(() => ({ text: document.querySelector('#map-error').textContent, hidden: document.querySelector('#map-error').classList.contains('d-none') }));
    return (v === 'Выберите товар' && r.method === 'POST' && r.url === '/api/listings/l-1/map' && r.body === '{"productId":"p1"}' && r.type.startsWith('application/json')
      && e.text === 'Товар не найден' && !e.hidden) || JSON.stringify({ v, r, e });
  });

  await t('bulk panel: row selection shows the panel, select-all offers the filter scope, suggestions by input', async () => {
    await p.click('#row-1');
    const one = await p.evaluate(() => ({ hidden: document.querySelector('#bulk-tag-panel').classList.contains('d-none'), count: document.querySelector('#bulk-count').textContent }));
    await p.click('#select-all');
    const all = await p.evaluate(() => ({ count: document.querySelector('#bulk-count').textContent, offer: !document.querySelector('#scope-offer').classList.contains('d-none'), r2: document.querySelector('#row-2').checked }));
    await p.type('#tag-name', 'нов');
    const s = await p.evaluate(() => Array.from(document.querySelector('#suggest').children).map((c) => `${c.className}:${c.textContent}`));
    return (!one.hidden && one.count === '1' && all.count === '2' && all.offer && all.r2 && s.join('|') === 'suggest-item:Новинки|suggest-create:Создать «нов»') || JSON.stringify({ one, all, s });
  });

  await t('filter scope: confirm dialog with texts from the module; confirm → JSON POST with filter; 422 → error box, dialog hidden', async () => {
    await p.click('#select-filter');
    const scope = await p.evaluate(() => ({ count: document.querySelector('#bulk-count').textContent, filter: !document.querySelector('#scope-filter').classList.contains('d-none') }));
    await p.click('#suggest .suggest-item');
    const dlg = await p.evaluate(() => ({
      shown: window.shown.slice(-1)[0],
      title: document.querySelector('#listings-confirm [data-confirm-title]').textContent,
      body: document.querySelector('#listings-confirm [data-confirm-body]').textContent,
      submit: document.querySelector('#listings-confirm [data-confirm-submit]').textContent,
      token: document.querySelector('#listings-confirm [data-confirm-token]').value,
      suggestHidden: document.querySelector('#suggest').classList.contains('d-none'),
    }));
    await p.evaluate(() => document.querySelector('#listings-confirm-form').requestSubmit());
    await p.waitForFunction(() => !document.querySelector('[data-listings-error]').classList.contains('d-none'));
    const r = lastReq();
    const s = await p.evaluate(() => ({ text: document.querySelector('#error-text').textContent, hidden: window.hidden.slice(-1)[0], prevented: window.submits.slice(-1)[0].prevented, disabled: document.querySelector('#listings-confirm [data-confirm-submit]').disabled }));
    return (scope.count === '5' && scope.filter && dlg.shown === 'listings-confirm' && dlg.title === 'Присвоить тег «Новинки» 5 листингам?'
      && dlg.body === 'Выборка: поиск «плед». Тег получат все листинги по этому фильтру, а не только строки текущей страницы.'
      && dlg.submit === 'Присвоить' && dlg.token === 'tok-assign' && dlg.suggestHidden
      && r.url === '/api/tags/assign' && r.body === '{"filter":{"q":"плед"},"tagId":"t1"}' && s.prevented === true
      && s.text === 'Сузьте фильтр' && s.hidden === 'listings-confirm' && !s.disabled) || JSON.stringify({ scope, dlg, r, s });
  });

  await t('detach: POST with listing and tag ids; 404 → error box', async () => {
    await p.click('#detach-1');
    await p.waitForFunction(() => document.querySelector('#error-text').textContent === 'Тег не найден');
    const r = lastReq();
    return (r.url === '/api/tags/detach' && r.body === '{"listingIds":["l-1"],"tagId":"t1"}') || JSON.stringify(r);
  });

  await t('tags: merge menus are built from data-tags as confirm triggers (textContent)', async () => {
    const s = await p.evaluate(() => Array.from(document.querySelectorAll('[data-merge-menu] button')).map((b) => [b.closest('tr').dataset.tagId, b.textContent, b.dataset.bsTarget, b.dataset.targetTagId, b.dataset.confirmTitle, b.dataset.confirmAction, b.dataset.confirmToken].join('~')));
    return (s.length === 2 && s[0] === 't1~Склад <b>1</b>~#tags-confirm~t2~Слить «Новинки» в «Склад <b>1</b>»?~/api/tags/merge~tok-tags'
      && s[1] === 't2~Новинки~#tags-confirm~t1~Слить «Склад <b>1</b>» в «Новинки»?~/api/tags/merge~tok-tags') || JSON.stringify(s);
  });

  await t('tags: create — empty name → inline error; server 409 → message under the field, button restored', async () => {
    await p.evaluate(() => document.querySelector('#create-form').requestSubmit());
    const v = await p.evaluate(() => ({ text: document.querySelector('#create-error').textContent, invalid: document.querySelector('#create-name').classList.contains('is-invalid') }));
    await p.type('#create-name', 'Новинки');
    const cleared = await p.evaluate(() => document.querySelector('#create-name').classList.contains('is-invalid'));
    await p.evaluate(() => document.querySelector('#create-form').requestSubmit());
    await p.waitForFunction(() => document.querySelector('#create-error').textContent === 'Тег уже есть');
    const r = lastReq();
    const s = await p.evaluate(() => ({ disabled: document.querySelector('#create-submit').disabled, prevented: window.submits.slice(-1)[0].prevented }));
    return (v.text === 'Введите название тега.' && v.invalid && !cleared && r.url === '/api/tags/create' && r.body === '{"name":"Новинки"}' && !s.disabled && s.prevented) || JSON.stringify({ v, cleared, r, s });
  });

  await t('tags: rename — inline input, Enter → POST by id template; 400 → alert', async () => {
    await p.click('#rename-1');
    const i = await p.evaluate(() => { const el = document.querySelector('#cell-1 input'); return { value: el.value, max: el.maxLength, focused: document.activeElement === el }; });
    await p.keyboard.press('End');
    await p.type('#cell-1 input', ' 2026');
    await p.keyboard.press('Enter');
    await p.waitForFunction(() => window.alerts.length === 1);
    const r = lastReq();
    const a = await p.evaluate(() => window.alerts.slice());
    // Убираем поле: его blur после следующего клика повторил бы запрос (Chrome шлёт blur и при удалении узла).
    await p.evaluate(() => document.querySelector('#cell-1').replaceChildren());
    return (i.value === 'Новинки' && i.max === 50 && i.focused && r.url === '/api/tags/t1/rename' && r.body === '{"name":"Новинки 2026"}' && a[0] === 'Слишком длинно') || JSON.stringify({ i, r, a });
  });

  await t('tags: delete — dialog texts from the trigger; confirm → dialog hidden, JSON POST, 409 → alert', async () => {
    const before = await p.evaluate(() => window.alerts.length);
    await p.evaluate(() => window.bs('tags-confirm', 'show.bs.modal', document.querySelector('#delete-1')));
    const dlg = await p.evaluate(() => ({ title: document.querySelector('#tags-confirm [data-confirm-title]').textContent, body: document.querySelector('#tags-confirm [data-confirm-body]').textContent, danger: document.querySelector('#tags-confirm [data-confirm-submit]').classList.contains('btn-danger') }));
    await p.evaluate(() => document.querySelector('#tags-confirm-form').requestSubmit());
    await p.waitForFunction((n) => window.alerts.length > n, {}, before);
    const r = requests.find((x) => x.url === '/api/tags/t1/delete');
    const s = await p.evaluate(() => ({ hidden: window.hidden.slice(-1)[0], prevented: window.submits.slice(-1)[0].prevented, alert: window.alerts.slice(-1)[0] }));
    return (dlg.title === 'Удалить тег «Новинки»?' && dlg.body === 'Тег снимется с 3 листинг(ов).' && dlg.danger && r.url === '/api/tags/t1/delete' && r.body === '{}'
      && s.hidden === 'tags-confirm' && s.prevented && s.alert === 'Тег используется') || JSON.stringify({ dlg, r, s });
  });

  await t('tags: merge — confirm → POST source/target, success → reload', async () => {
    await p.evaluate(() => window.bs('tags-confirm', 'show.bs.modal', document.querySelector('#menu-1 button')));
    const nav = p.waitForNavigation({ waitUntil: 'networkidle0' });
    await p.evaluate(() => document.querySelector('#tags-confirm-form').requestSubmit());
    await nav;
    await ready();
    const r = requests.find((x) => x.url === '/api/tags/merge');
    return (r && r.body === '{"sourceTagId":"t1","targetTagId":"t2"}') || JSON.stringify(r);
  });

  await t('assign (page scope): success → flash remembered, reload from the first page keeps filters', async () => {
    await p.click('#row-1');
    await p.type('#tag-name', 'Нов');
    const nav = p.waitForNavigation({ waitUntil: 'networkidle0' });
    await p.click('#suggest .suggest-item');
    await nav;
    await ready();
    const r = requests.filter((x) => x.url === '/api/tags/assign').slice(-1)[0];
    const s = await p.evaluate(() => ({ url: decodeURIComponent(window.location.search), flash: document.querySelector('#flash-text').textContent, hidden: document.querySelector('[data-listings-flash]').classList.contains('d-none') }));
    return (r.body === '{"listingIds":["l-1"],"tagId":"t1"}' && s.url === '?q=плед' && s.flash === 'Тег «Новинки»: назначен 2 листингам, у 1 уже был.' && !s.hidden) || JSON.stringify({ r, s });
  });

  await t('no page errors', async () => errs.length === 0 || errs.join('\n'));

  await browser.close();
  server.close();
  console.log(failed ? `${failed} failed` : 'all passed');
  process.exit(failed ? 1 : 0);
})();
