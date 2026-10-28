// Браузерный сценарий страницы «Новая операция ДДС» на поднятом стенде (не входит в phpunit).
// Нужны: приложение по адресу SITE_IP (Host: localhost), пользователь owner@app.ru/password
// с ролью владельца, у компании есть счёт, проект «Общие операции» и ЦФО. Запуск — как у dds-operation-page.e2e.cjs
// (без TX_ID). Сценарий создаёт операцию — гонять на стенде, не на проде.
const path = require('path');
const puppeteer = require('puppeteer');

const { SITE_IP } = process.env;
const SHOTS = process.argv[2] || '';
const NEW = '/finance/cash-transactions/new';
const STAMP = `e2e новая операция ${Date.now()}`;

(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/chromium-browser',
    ignoreDefaultArgs: ['--hide-scrollbars'],
    args: ['--no-sandbox', '--disable-dev-shm-usage', `--host-resolver-rules=MAP localhost ${SITE_IP}`],
  });
  const p = await browser.newPage();
  await p.setViewport({ width: 1440, height: 1000 });
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.message));
  p.on('dialog', (d) => d.accept());

  let failed = 0;
  let passed = 0;
  const t = async (name, fn) => {
    let r;
    try { r = await fn(); } catch (e) { r = e.message; }
    if (r === true) { passed += 1; console.log('OK  ', name); } else { failed += 1; console.log('FAIL', name, r); }
  };
  const $text = (sel) => p.$eval(sel, (n) => n.textContent.trim());
  const shot = async (name, full = false) => {
    if (!SHOTS) return;
    await p.screenshot({ path: path.join(SHOTS, name), fullPage: full });
  };
  const open = async () => {
    await p.goto(`http://localhost${NEW}`, { waitUntil: 'networkidle0' });
    await p.waitForSelector('[data-dds-op] .dds-field');
    await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
  };
  const modalShown = (sel) => p.waitForFunction((s) => document.querySelector(s)?.classList.contains('show'), { timeout: 3000 }, sel)
    .then(() => new Promise((r) => setTimeout(r, 400)))
    .then(() => true);

  await p.goto('http://localhost/login');
  await p.type('input[name="_username"]', 'owner@app.ru');
  await p.type('input[name="_password"]', 'password');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);

  await open();
  await shot('new-01-default-1440.png', true);
  await t('title and heading', async () => ((await p.title()).trim() === 'Новая операция ДДС' && (await $text('h1.dds-op__title')) === 'Новая операция ДДС') || await p.title());
  await t('save enabled at once, status', async () => (!(await p.$eval('[data-dds-op-save]', (n) => n.disabled)) && (await $text('[data-dds-op-status]')) === 'Операция ещё не сохранена') || 'bad');
  await t('direction defaults to Приток', async () => (await $text('.dds-seg__input:checked + .dds-seg__text')) === 'Приток' || 'bad');
  await t('widgets ready (category, ЦФО)', async () => (await p.$$eval('.dds-picker.is-ready', (n) => n.length)) === 2 || 'not ready');

  // Пустая сумма → проверка до отправки, страница не уходит.
  await p.click('[data-dds-op-save]');
  await t('summary: amount required', async () => (await $text('[data-dds-op-summary-list]')) === 'Сумма: Укажите сумму' || await $text('[data-dds-op-summary-list]'));
  await t('focus on amount', async () => (await p.evaluate(() => document.activeElement.id)) === 'cash_transaction_amount' || await p.evaluate(() => document.activeElement.id));
  await t('page not submitted', async () => p.url().endsWith(NEW) || p.url());
  await shot('new-02-validation-1440.png');

  // Уход с правками → модалка; без правок — сразу.
  await p.type('[data-dds-op-amount-input]', '1');
  await p.click('[data-dds-op-cancel]');
  await t('leave modal with changes', () => modalShown('[data-dds-op-leave]'));
  await shot('new-03-leave-modal-1440.png');
  await p.click('[data-dds-op-leave] [data-bs-dismiss="modal"]');
  await p.waitForFunction(() => !document.querySelector('[data-dds-op-leave]').classList.contains('show') && !document.querySelector('.modal-backdrop'));

  // Создание: Отток, сумма с запятой, статья-лист из виджета, описание.
  await open();
  await p.click('.dds-seg__opt--out');
  await p.type('[data-dds-op-amount-input]', '2750,40');
  await p.click('.dds-op__field--wide .dds-field__main');
  const leaf = await p.$('.dds-tree [role="treeitem"]:not([aria-expanded])');
  if (leaf) await leaf.click();
  // Проект и ЦФО сервер требует (прежнее правило): общий проект и единственный ЦФО компании стенда.
  await p.evaluate(() => {
    const project = document.querySelector('select[name="cash_transaction[projectDirection]"]');
    project.value = [...project.options].find((o) => o.textContent.trim() === 'Общие операции')?.value ?? '';
    project.dispatchEvent(new Event('change', { bubbles: true }));
    const center = document.querySelector('select[name="cash_transaction[responsibilityCenterId]"]');
    center.value = [...center.options].find((o) => o.value)?.value ?? '';
    center.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await p.type('[data-dds-op-description]', STAMP);
  await t('amount formatted on blur', async () => (await p.$eval('[data-dds-op-amount-input]', (n) => n.value)) === '2750,40' || await p.$eval('[data-dds-op-amount-input]', (n) => n.value));
  await Promise.all([p.waitForNavigation(), p.click('[data-dds-op-save]')]);
  await t('saved → list', async () => (p.url().includes('/finance/cash-transactions') && !p.url().includes('/new')) || `${p.url()} ${await p.evaluate(() => [document.querySelector('[data-dds-op-summary]')?.textContent.replace(/\s+/g, ' '), [...document.querySelectorAll('.alert')].map((a) => a.textContent.trim()).join('|')].join(' / '))}`);
  await t('created operation in list', async () => (await p.evaluate((s) => document.body.textContent.includes(s), STAMP)) || 'not in list');

  // Адаптив.
  await open();
  for (const width of [1280, 390]) {
    await p.setViewport({ width, height: 900 });
    await t(`no horizontal scroll at ${width}`, async () => (await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)) || await p.evaluate(() => document.documentElement.scrollWidth));
    await shot(`new-01-default-${width}.png`, true);
  }

  await t('no page errors', async () => errs.length === 0 || errs.join('; '));
  console.log(`\n${passed} passed, ${failed} failed`);
  await browser.close();
  process.exit(failed ? 1 : 0);
})();
