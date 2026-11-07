// Браузерный тест модуля страницы «Документ ОПиУ» Stage 2 PR I (tests/e2e/pnl-document.html). Запуск из site/:
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/pnl-document.e2e.cjs
// Сценарии на живом стенде — tests/e2e/pnl-document-{page,new}.e2e.cjs.
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };

const server = http.createServer((req, res) => {
  const [pathname] = req.url.split('?');
  const rel = pathname === '/' ? 'tests/e2e/pnl-document.html' : pathname.replace(/^\//, '');
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
  const open = async (query = '') => {
    await p.goto(base + query, { waitUntil: 'networkidle0' });
    await p.waitForFunction(() => window.ready === true);
  };
  await open();

  let failed = 0;
  const t = async (name, fn) => {
    try {
      const r = await fn();
      if (r !== true) throw new Error(typeof r === 'string' ? r : 'assertion failed');
      console.log(`ok   ${name}`);
    } catch (e) { failed += 1; console.log(`FAIL ${name}: ${e.message}`); }
  };
  const NBSP = ' ';
  const text = (sel) => p.$eval(sel, (n) => n.textContent);
  const val = (sel) => p.$eval(sel, (n) => n.value);
  const hidden = (sel) => p.$eval(sel, (n) => n.hidden);
  const totals = () => p.evaluate(() => ({
    income: document.querySelector('#t-income').textContent, expense: document.querySelector('#t-expense').textContent,
    result: document.querySelector('#t-result').textContent, count: document.querySelector('#ops-count').textContent,
    rest: document.querySelector('#alloc-rest')?.textContent, footer: document.querySelector('#alloc-rest-footer').textContent,
    rows: document.querySelectorAll('[data-operation-row]').length,
  }));

  await t('init: totals, formatted amounts, inherit marks, save disabled, allocation rest', async () => {
    const s = await totals();
    const x = await p.evaluate(() => ({
      amount0: document.querySelector('#row-0 [data-operation-amount]').value, flow0: document.querySelector('#row-0 [data-operation-flow-label]').textContent,
      mark0: document.querySelector('#row-0 [data-inherit-mark="project"]').hidden, mark1: document.querySelector('#row-1 [data-inherit-mark="project"]').hidden,
      save: document.querySelector('#save').disabled, badge: document.querySelector('#dirty-badge').hidden,
      fill: document.querySelector('#alloc-fill').hidden, okText: document.querySelector('#alloc-ok-text').textContent, live: document.querySelector('#alloc-live').hidden,
      remove: document.querySelector('#row-0 .remove-item').disabled, aria: document.querySelector('#row-1 [data-operation-comment]').getAttribute('aria-label'),
    }));
    return (s.income === `100${NBSP}000,00${NBSP}₽` && s.expense === `70${NBSP}000,00${NBSP}₽` && s.result === `30${NBSP}000,00${NBSP}₽` && s.count === '2'
      && s.rest === `30${NBSP}000,00` && s.footer === s.rest && x.amount0 === `70${NBSP}000,00` && x.flow0 === 'Расход'
      && x.mark0 === true && x.mark1 === false && x.save === true && x.badge === true && x.fill === false
      && x.okText === 'Остаток можно распределить другими документами' && x.live === true && x.remove === false && x.aria === 'Описание, операция 2')
      || JSON.stringify({ s, x });
  });
  await t('header project change: only inheriting rows follow, notice counts manual ones, page becomes dirty', async () => {
    await p.select('#hdr-project', 'p2');
    const x = await p.evaluate(() => ({
      r0: document.querySelector('#row-0 [data-operation-project-direction]').value, r1: document.querySelector('#row-1 [data-operation-project-direction]').value,
      notice: document.querySelector('#notice').textContent, noticeHidden: document.querySelector('#notice').hidden,
      mark0: document.querySelector('#row-0 [data-inherit-mark="project"]').hidden, mark1: document.querySelector('#row-1 [data-inherit-mark="project"]').hidden,
      save: document.querySelector('#save').disabled, badge: document.querySelector('#dirty-badge').hidden, dirtyText: document.querySelector('#dirty-text').textContent,
      live: document.querySelector('#alloc-live').hidden,
    }));
    return (x.r0 === 'p2' && x.r1 === 'p2' && x.notice === 'Проект «Проект 2» подставлен в 1 из 2 операций. В 1 задан вручную.' && x.noticeHidden === false
      && x.mark0 === true && x.mark1 === true && x.save === false && x.badge === false && x.dirtyText === 'Есть несохранённые изменения' && x.live === false) || JSON.stringify(x);
  });
  await t('inherit reset: manual CFO back to header value, mark hides', async () => {
    await p.select('#row-1 [data-operation-responsibility-center]', 'r2');
    const before = await hidden('#row-1 [data-inherit-mark="cfo"]');
    await p.click('#row-1 [data-inherit-reset="cfo"]');
    const x = await p.evaluate(() => ({ v: document.querySelector('#row-1 [data-operation-responsibility-center]').value, m: document.querySelector('#row-1 [data-inherit-mark="cfo"]').hidden }));
    return (before === false && x.v === 'r1' && x.m === true) || JSON.stringify({ before, x });
  });
  await t('add operation: next index above existing, header values inherited, count updated', async () => {
    await p.click('#add-op');
    const x = await p.evaluate(() => {
      const row = document.querySelectorAll('[data-operation-row]')[2];
      return { name: row.querySelector('[data-operation-amount]').name, project: row.querySelector('[data-operation-project-direction]').value, cfo: row.querySelector('[data-operation-responsibility-center]').value, count: document.querySelector('#ops-count').textContent, empty: document.querySelector('#ops-empty').hidden };
    });
    return (x.name === 'document[operations][2][amount]' && x.project === 'p2' && x.cfo === 'r1' && x.count === '3' && x.empty === true) || JSON.stringify(x);
  });
  await t('amount: formatted on focusout, counted as uncategorized, allocation rest shrinks', async () => {
    await p.type('[name="document[operations][2][amount]"]', '1 500,5');
    await p.$eval('[name="document[operations][2][amount]"]', (i) => i.blur());
    const x = await p.evaluate(() => ({ v: document.querySelector('[name="document[operations][2][amount]"]').value, uncat: document.querySelector('#t-uncat').textContent, uh: document.querySelector('#t-uncat').hidden, rest: document.querySelector('#alloc-rest').textContent }));
    return (x.v === `1${NBSP}500,50` && x.uncat === '1 без статьи — не входят в итог' && x.uh === false && x.rest === `28${NBSP}499,50`) || JSON.stringify(x);
  });
  await t('duplicate: copy placed right after source with the same values and a fresh index', async () => {
    await p.click('#row-1 [data-operation-duplicate]');
    const x = await p.evaluate(() => {
      const rows = Array.from(document.querySelectorAll('[data-operation-row]'));
      const copy = rows[2];
      return { afterSource: rows[1].id === 'row-1', name: copy.querySelector('[data-operation-amount]').name, cat: copy.querySelector('[data-operation-category]').value, project: copy.querySelector('[data-operation-project-direction]').value, amount: copy.querySelector('[data-operation-amount]').value, income: document.querySelector('#t-income').textContent, rows: rows.length };
    });
    return (x.afterSource && x.name === 'document[operations][3][amount]' && x.cat === 'c-inc' && x.project === 'p2' && x.amount === `100${NBSP}000,00` && x.income === `200${NBSP}000,00${NBSP}₽` && x.rows === 4) || JSON.stringify(x);
  });
  await t('remove: rows drop out, the last remaining row cannot be removed', async () => {
    await p.evaluate(() => { document.querySelectorAll('[data-operation-row] .remove-item').forEach((b, i) => { if (i > 0) b.click(); }); });
    const x = await p.evaluate(() => ({ rows: document.querySelectorAll('[data-operation-row]').length, disabled: document.querySelector('#row-0 .remove-item').disabled, count: document.querySelector('#ops-count').textContent, expense: document.querySelector('#t-expense').textContent }));
    return (x.rows === 1 && x.disabled === true && x.count === '1' && x.expense === `70${NBSP}000,00${NBSP}₽`) || JSON.stringify(x);
  });
  await t('allocation fill: adds a row for the rest; rest becomes zero, state «Всё распределено»', async () => {
    await p.click('#alloc-fill');
    const x = await p.evaluate(() => {
      const rows = document.querySelectorAll('[data-operation-row]');
      return { amount: rows[1].querySelector('[data-operation-amount]').value, rest: document.querySelector('#alloc-rest').textContent, ok: document.querySelector('#alloc-ok-text').textContent, fill: document.querySelector('#alloc-fill').hidden, over: document.querySelector('#alloc-over').hidden };
    });
    return (x.amount === `130${NBSP}000,00` && x.rest === '0,00' && x.ok === 'Всё распределено' && x.fill === true && x.over === true) || JSON.stringify(x);
  });
  await t('allocation over: negative rest shows the warning and the red minus', async () => {
    await p.evaluate(() => { const i = document.querySelectorAll('[data-operation-row]')[1].querySelector('[data-operation-amount]'); i.value = '200000'; i.dispatchEvent(new Event('input', { bubbles: true })); });
    const x = await p.evaluate(() => ({ rest: document.querySelector('#alloc-rest').textContent, danger: document.querySelector('#alloc-rest').classList.contains('text-danger'), over: document.querySelector('#alloc-over').hidden, ok: document.querySelector('#alloc-ok').hidden }));
    return (x.rest === `−70${NBSP}000,00` && x.danger === true && x.over === false && x.ok === true) || JSON.stringify(x);
  });
  await t('description counter', async () => {
    await p.type('#descr', 'Аренда');
    const c = await text('#descr-counter');
    return c === '6 из 500' || c;
  });
  await t('leave: page link opens the dialog with its href; data-leave-safe link passes through', async () => {
    await p.click('#link-out');
    await p.click('#link-safe');
    const x = await p.evaluate(() => ({ clicks: window.clicks, shown: window.shown, href: new URL(document.querySelector('#leave-confirm').href).pathname }));
    return (x.clicks.length === 2 && x.clicks[0].id === 'link-out' && x.clicks[0].prevented === true && x.clicks[1].prevented === false
      && x.shown.join() === 'document-leave-modal' && x.href === '/documents') || JSON.stringify(x);
  });
  await t('submit: amounts normalized to server format', async () => {
    await p.evaluate(() => document.querySelector('#doc-form').requestSubmit());
    const x = await p.evaluate(() => ({ submits: window.submits.join(), amounts: Array.from(document.querySelectorAll('[data-operation-amount]')).map((i) => i.value).join() }));
    return (x.submits === 'doc-form' && x.amounts === '70000.00,200000.00') || JSON.stringify(x);
  });

  await open('?mode=new');
  await t('new mode: one quiet row, save enabled, page not dirty', async () => {
    const x = await p.evaluate(() => ({ rows: document.querySelectorAll('[data-operation-row]').length, name: document.querySelector('[data-operation-amount]').name, save: document.querySelector('#save').disabled, badge: document.querySelector('#dirty-badge').hidden, count: document.querySelector('#ops-count').textContent, remove: document.querySelector('.remove-item').disabled }));
    return (x.rows === 1 && x.name === 'document[operations][0][amount]' && x.save === false && x.badge === true && x.count === '1' && x.remove === true) || JSON.stringify(x);
  });

  await open('?mode=error');
  await t('after failed save: dirty from the start, focus on the invalid field, summary link scrolls to it', async () => {
    const x = await p.evaluate(() => ({ badge: document.querySelector('#dirty-badge').hidden, save: document.querySelector('#save').disabled, live: document.querySelector('#alloc-live').hidden, focus: document.activeElement.id }));
    await p.focus('#summary');
    await p.click('#summary-link');
    const after = await p.evaluate(() => ({ focus: document.activeElement.id, prevented: window.clicks[0]?.prevented }));
    return (x.badge === false && x.save === false && x.live === false && x.focus === 'row-1-amount' && after.focus === 'row-1-amount' && after.prevented === true) || JSON.stringify({ x, after });
  });

  await t('no page errors', async () => errs.length === 0 || errs.join('\n'));

  await browser.close();
  server.close();
  console.log(failed === 0 ? 'ALL PASSED' : `${failed} FAILED`);
  process.exit(failed === 0 ? 0 : 1);
})();
