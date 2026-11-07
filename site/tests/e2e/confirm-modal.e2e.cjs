// Браузерный тест диалога подтверждения (assets/confirm_modal.js) на демо-странице
// tests/e2e/confirm-modal.html. Запуск из site/ (Chrome в контейнере, node на хосте не нужен):
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/confirm-modal.e2e.cjs
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/confirm-modal.html' };

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

  let failed = 0;
  let passed = 0;
  const t = async (name, fn) => {
    let r;
    try { r = await fn(); } catch (e) { r = e.message; }
    if (r === true) { passed += 1; console.log('OK  ', name); } else { failed += 1; console.log('FAIL', name, r); }
  };
  const open = (id) => p.evaluate((i) => window.openWith(i), id);
  const form = () => p.evaluate(() => {
    const f = document.querySelector('[data-confirm-form]');
    const submit = f.querySelector('[data-confirm-submit]');
    return {
      action: f.getAttribute('action'),
      token: f.querySelector('[data-confirm-token]').value,
      extra: [...f.querySelectorAll('[data-confirm-extra]')].map((i) => `${i.name}=${i.value}`),
      title: f.querySelector('[data-confirm-title]').textContent,
      body: f.querySelector('[data-confirm-body]').innerHTML,
      submitText: submit.textContent,
      submitDanger: submit.classList.contains('btn-danger'),
      submitDisabled: submit.disabled,
      submitHidden: submit.hidden,
      links: [...f.querySelectorAll('[data-confirm-links] a')].map((a) => `${a.getAttribute('href')}|${a.textContent}`),
      cancel: f.querySelector('[data-confirm-cancel]').textContent,
    };
  });

  await t('open with fields: action, token, version hidden input', async () => {
    if (!(await open('archive'))) return 'event cancelled';
    const f = await form();
    return (f.action === '/centers/42/archive' && f.token === 'tok-archive' && f.extra.join() === 'version=3'
      && f.title === 'Архивировать ЦФО?' && f.submitText === 'Архивировать' && f.submitDanger && !f.submitDisabled) || JSON.stringify(f);
  });
  await t('reopen without fields: extra inputs removed, body is text not HTML', async () => {
    await open('delete');
    const f = await form();
    return (f.action === '/profiles/7/delete' && f.token === 'tok-delete' && f.extra.length === 0
      && f.body === 'Пояснение &lt;b&gt;без&lt;/b&gt; HTML.' && f.submitText === 'Удалить' && !f.submitDanger) || JSON.stringify(f);
  });
  await t('blocked: submit hidden+disabled, links rendered, cancel is «Закрыть»', async () => {
    await open('blocked');
    const f = await form();
    return (f.submitHidden && f.submitDisabled && f.links.join() === '/ops?c=1|Показать операции' && f.cancel === 'Закрыть') || JSON.stringify(f);
  });
  await t('trigger without action: open is cancelled', async () => {
    const ok = await p.evaluate(() => {
      const b = document.createElement('button');
      b.dataset.confirmToken = 'x';
      document.body.append(b);
      b.id = 'noaction';
      return window.openWith('noaction');
    });
    return ok === false || 'opened';
  });
  await t('no page errors', async () => errs.length === 0 || errs.join('; '));

  await browser.close();
  server.close();
  console.log(`${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
