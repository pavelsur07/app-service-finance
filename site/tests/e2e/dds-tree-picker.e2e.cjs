// Браузерный тест виджета «Статья ДДС» на проектных файлах (assets/dds_tree_picker.js,
// assets/styles/components/dds-pickers.css). Порт эталона
// docs/design-system/components/dds-tree-picker/dds-tree-picker.e2e.js на puppeteer.
// Запуск из site/ (Chrome в контейнере, node на хосте не нужен):
//   docker run --rm -e NODE_PATH=/usr/src/app/node_modules -v $PWD:/site -w /site \
//     --entrypoint node zenika/alpine-chrome:with-puppeteer tests/e2e/dds-tree-picker.e2e.cjs [папка-скриншотов]
const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const ROOT = path.resolve(__dirname, '../..');
const SHOTS = process.argv[2] || '';
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const ROUTES = { '/': 'tests/e2e/dds-tree-picker.html' };

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
  await p.setViewport({ width: 700, height: 800 });
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.message));
  p.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
  await p.goto(url);
  await p.waitForSelector('.dds-field');

  let failed = 0;
  let passed = 0;
  const t = async (name, fn) => {
    let r;
    try { r = await fn(); } catch (e) { r = e.message; }
    if (r === true) { passed += 1; console.log('OK  ', name); } else { failed += 1; console.log('FAIL', name, r); }
  };
  const text = (sel) => p.$eval(sel, (n) => n.textContent);
  const val = () => p.$eval('#cat', (n) => n.value);
  const hidden = (sel) => p.$eval(sel, (n) => n.hidden || getComputedStyle(n).display === 'none');
  const groupCount = (name) => p.$$eval('.dds-tree__group', (ns, s) => ns.filter((n) => n.textContent.includes(s)).length, name);
  const shot = async (name) => { if (SHOTS) await p.screenshot({ path: path.join(SHOTS, name) }); };

  await t('initial label', async () => (await text('.dds-field__value')) === 'Комиссия банка' || await text('.dds-field__value'));
  await t('initial path', async () => (await text('.dds-field__path')) === 'Выбытие' || await text('.dds-field__path'));
  await t('native select hidden only after init', async () => (await p.$eval('#p', (n) => n.classList.contains('is-ready'))) || 'no is-ready');
  await t('button ARIA', async () => (await p.$eval('.dds-field__main', (b) => b.getAttribute('aria-haspopup') === 'dialog' && b.getAttribute('aria-expanded') === 'false')) || 'bad aria');
  await shot('field.png');
  await p.click('.dds-field__main');
  await t('popover open + input focused', async () => (await p.evaluate(() => document.activeElement.className)).includes('dds-pop__input') || 'focus elsewhere');
  await t('aria-expanded true, role=tree/treeitem', async () => (await p.evaluate(() => document.querySelector('.dds-field__main').getAttribute('aria-expanded') === 'true'
    && !!document.querySelector('[role=tree] [role=treeitem]'))) || 'bad aria');
  await t('inflow groups hidden for out', async () => (await groupCount('Поступления')) === 0 || 'Поступления visible');
  await t('group counter = leaves incl. nested', async () => (await p.$$eval('.dds-tree__group', (ns) => ns.find((n) => n.textContent.includes('Выбытие')).querySelector('.dds-tree__count').textContent)) === '3' || 'bad count');
  // Эталон: группа 12 + 22·d, лист 36 + 22·d; 4 уровня групп (d 0–3) + лист на d 4.
  await t('4 levels of groups + leaf, step 22px', async () => {
    const pads = await p.$$eval('.dds-tree__row', (ns) => ns.map((n) => `${n.classList.contains('dds-tree__group') ? 'g' : 'l'}${n.dataset.d}:${getComputedStyle(n).paddingLeft}`));
    return ['g0:12px', 'g1:34px', 'g2:56px', 'g3:78px', 'l4:124px'].every((x) => pads.includes(x)) || pads.join(',');
  });
  await t('guide lines: one per level above', async () => (await p.$eval('.dds-tree__leaf[data-d="4"]', (n) => getComputedStyle(n).backgroundSize)) === '88px 100%' || 'bad guides');
  await shot('tree-open.png');
  await t('group click does not select', async () => { await p.click('.dds-tree__group[data-d="1"]'); return (await val()) === '3' || 'changed'; });
  await t('group click collapses', async () => (await p.$eval('.dds-tree__group[data-d="1"]', (n) => n.getAttribute('aria-expanded'))) === 'false' || 'still open');
  await p.click('.dds-tree__group[data-d="1"]');
  await p.type('.dds-pop__input', 'свар');
  await t('search shows leaf with hit', async () => (await text('.dds-tree__hit')) === 'Свар' || await text('.dds-tree__hit'));
  await t('search: first row active + activedescendant', async () => (await p.evaluate(() => {
    const cur = document.querySelector('[data-cur="true"]');
    return !!cur && document.querySelector('.dds-pop__input').getAttribute('aria-activedescendant') === cur.id;
  })) || 'no active row');
  await shot('search.png');
  await p.keyboard.press('Enter');
  await t('Enter picks leaf', async () => (await val()) === '5' || await val());
  await t('change fired on select', async () => (await p.evaluate(() => window.__changes)) === 1 || 'no change');
  await t('popover closed, focus back on button', async () => (await hidden('.dds-pop')) && (await p.evaluate(() => document.activeElement.classList.contains('dds-field__main'))) || 'bad');
  await t('path nested 4 levels', async () => (await text('.dds-field__path')) === 'Выбытие › Заработная плата › Производство › Цех №1' || await text('.dds-field__path'));
  await p.select('#dir', '0');
  await t('direction change resets category', async () => (await val()) === '' || await val());
  await t('reset note shown', async () => /не подходит для притока/.test(await text('.dds-field__note')) || await text('.dds-field__note'));
  await shot('direction-reset.png');
  await p.click('.dds-field__main');
  await t('inflow tree has Поступления, not Выбытие', async () => ((await groupCount('Поступления')) === 1 && (await groupCount('Выбытие')) === 0) || 'bad');
  await t('flow=both always visible', async () => (await groupCount('Технические')) === 1 || 'both hidden');
  await p.keyboard.press('ArrowDown');
  await p.keyboard.press('ArrowDown');
  await p.keyboard.press('Enter');
  await t('keyboard select', async () => (await val()) === '2' || await val());
  await p.click('.dds-field__main');
  await p.keyboard.press('Escape');
  await t('Esc closes', async () => (await hidden('.dds-pop')) || 'open');
  await p.click('.dds-field__main');
  await p.type('.dds-pop__input', 'комиссия');
  await t('empty search state offers show all', async () => /Статья не найдена\. Проверьте название или покажите все статьи\./.test(await text('.dds-pop__empty')) || await text('.dds-tree'));
  await p.$$eval('.dds-link', (ns) => ns.find((n) => n.textContent === 'Показать все').click());
  await t('show all reveals outflow', async () => (await groupCount('Выбытие')) === 1 || 'no');
  await p.mouse.click(650, 760);
  await t('outside click closes', async () => (await hidden('.dds-pop')) || 'open');
  await p.click('.dds-field__main');
  await p.type('.dds-pop__input', 'script');
  await t('names are text, not HTML', async () => (await p.evaluate(() => window.__xss === undefined && !document.querySelector('.dds-tree script'))) || 'html injected');
  await p.keyboard.press('Escape');
  await t('clear button empties value', async () => { await p.click('.dds-field__clear'); return (await val()) === '' && (await text('.dds-field__value')) === 'Не распределено' || await val(); });
  await t('no console errors', async () => errs.length === 0 || errs.join('|'));

  await browser.close();
  server.close();
  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
