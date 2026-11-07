// Браузерный тест общих поведений легаси-форм: data-autosubmit, data-busy-submit, подпись валюты.
// Запуск из site/ (Chrome в контейнере, node на хосте не нужен):
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/legacy-forms.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/legacy-forms.html' };

const server = http.createServer((req, res) => {
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
  const submits = () => p.evaluate(() => window.submits.map((s) => s.id));
  const prevented = () => p.evaluate(() => window.submits.map((s) => s.prevented));

  await t('select change in data-autosubmit form submits it', async () => {
    await p.select('#filter select', 'wb');
    return ((await submits()).join() === 'filter' && (await prevented()).join() === 'false') || (await submits()).join();
  });
  await t('checkbox change submits too', async () => {
    await p.click('#filter input[type="checkbox"]');
    return (await submits()).join() === 'filter,filter' || (await submits()).join();
  });
  await t('text input change does not submit', async () => {
    await p.type('#filter input[type="text"]', 'abc');
    await p.$eval('#filter input[type="text"]', (n) => n.dispatchEvent(new Event('change', { bubbles: true })));
    return (await submits()).length === 2 || (await submits()).join();
  });
  await t('form without data-autosubmit is untouched', async () => {
    await p.select('#plain select', '2');
    return (await submits()).length === 2 || (await submits()).join();
  });
  await t('busy submit: button disabled with label, cancel untouched', async () => {
    await p.click('#busy button[type="submit"]');
    const s = await p.evaluate(() => {
      const f = document.getElementById('busy');
      const b = f.querySelector('button[type="submit"]');
      return { busy: f.getAttribute('aria-busy'), disabled: b.disabled, text: b.textContent, spinner: !!b.querySelector('.spinner-border'), other: document.getElementById('other').disabled };
    });
    return (s.busy === 'true' && s.disabled && s.text === 'Пересчитываем...' && s.spinner && !s.other) || JSON.stringify(s);
  });
  await t('busy submit: first submit passes, second is cancelled by the module', async () => {
    await p.$eval('#busy', (f) => f.requestSubmit());
    const ids = await submits();
    const flags = await prevented();
    const busy = ids.map((id, i) => (id === 'busy' ? flags[i] : null)).filter((f) => f !== null);
    return busy.join() === 'false,true' || `${ids.join()} / ${flags.join()}`;
  });
  await t('currency output follows the select', async () => {
    const initial = await p.$eval('[data-currency-output]', (n) => n.value);
    await p.select('[data-currency-select]', 'a');
    const next = await p.$eval('[data-currency-output]', (n) => n.value);
    return (initial === 'USD' && next === 'RUB') || `${initial}/${next}`;
  });
  await t('no page errors', async () => errs.length === 0 || errs.join('; '));

  await browser.close();
  server.close();
  console.log(`${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
