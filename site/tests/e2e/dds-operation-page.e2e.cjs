// Браузерный сценарий страницы «Операция ДДС» на поднятом стенде (не входит в phpunit).
// Нужны: приложение по адресу SITE_IP (Host: localhost), пользователь owner@app.ru/password
// с ролью владельца, операция-отток TX_ID с выбранной статьёй-оттоком и деревом статей.
// Запуск (Chrome в контейнере, сеть стенда):
//   docker run --rm --network <сеть> -e NODE_PATH=/usr/src/app/node_modules -e SITE_IP=<ip> -e TX_ID=<uuid> \
//     -v $PWD:/site -v <папка>:/shots -w /site --entrypoint node zenika/alpine-chrome:with-puppeteer \
//     tests/e2e/dds-operation-page.e2e.cjs /shots
// Сценарий меняет операцию (сумма, удаление/восстановление) — гонять на стенде, не на проде.
const path = require('path');
const puppeteer = require('puppeteer');

const { SITE_IP, TX_ID } = process.env;
const SHOTS = process.argv[2] || '';
const EDIT = `/finance/cash-transactions/${TX_ID}/edit`;

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
  const isHidden = (sel) => p.$eval(sel, (n) => n.hidden || getComputedStyle(n).display === 'none');
  const shot = async (name, full = false) => {
    if (!SHOTS) return;
    await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
    await p.screenshot({ path: path.join(SHOTS, name), fullPage: full });
  };
  const open = async () => {
    await p.goto(`http://localhost${EDIT}`, { waitUntil: 'networkidle0' });
    await p.waitForSelector('[data-dds-op] .dds-field');
    // Дебаг-панель Symfony (dev-стенд) перекрывает панель действий внизу.
    await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
  };
  // Класс show ставится в начале анимации; клики Bootstrap игнорирует, пока она идёт.
  const modalShown = (sel) => p.waitForFunction((s) => document.querySelector(s)?.classList.contains('show'), { timeout: 3000 }, sel)
    .then(() => new Promise((r) => setTimeout(r, 400)))
    .then(() => true);

  await p.goto('http://localhost/login');
  await p.type('input[name="_username"]', 'owner@app.ru');
  await p.type('input[name="_password"]', 'password');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);

  await open();
  await shot('01-edit-default-1440.png', true);
  await t('amount formatted 5300,00', async () => (await p.$eval('[data-dds-op-amount-input]', (n) => n.value)) === '5300,00' || await p.$eval('[data-dds-op-amount-input]', (n) => n.value));
  await t('save disabled without changes', async () => (await p.$eval('[data-dds-op-save]', (n) => n.disabled)) || 'enabled');
  await t('status: all saved, badge hidden', async () => ((await $text('[data-dds-op-status]')) === 'Все изменения сохранены' && await isHidden('[data-dds-op-dirty-badge]')) || 'bad');

  await p.type('[data-dds-op-description]', '!');
  await t('dirty: badge, status, save enabled, counter', async () => {
    const r = await p.evaluate(() => [
      document.querySelector('[data-dds-op-dirty-badge]').hidden,
      document.querySelector('[data-dds-op-status]').textContent,
      document.querySelector('[data-dds-op-save]').disabled,
      document.querySelector('[data-dds-op-counter]').textContent,
      document.querySelector('[data-dds-op-description]').value.length,
    ]);
    return (r[0] === false && r[1] === 'Есть несохранённые изменения' && r[2] === false && r[3] === `${r[4]} из 500`) || JSON.stringify(r);
  });
  await p.keyboard.press('Backspace');
  await t('revert → clean again', async () => (await p.$eval('[data-dds-op-save]', (n) => n.disabled)) || 'still dirty');

  // Смена счёта: валюта и подпись следуют за счётом (валюту опции подменяем — у фикстур все счета рублёвые).
  await t('currency follows account', async () => {
    const r = await p.evaluate(() => {
      const acc = document.querySelector('[data-dds-op-account]');
      const opt = [...acc.options].find((o) => o.value && !o.selected);
      opt.dataset.currency = 'USD';
      acc.value = opt.value;
      acc.dispatchEvent(new Event('change', { bubbles: true }));
      return [document.querySelector('[data-dds-op-currency-symbol]').textContent, document.querySelector('[data-dds-op-currency-hint]').textContent];
    });
    return (r[0] === '$' && r[1] === 'Валюта берётся из счёта: доллар США (USD)') || JSON.stringify(r);
  });
  await open();

  await p.click('.dds-op__field--wide .dds-field__main');
  await shot('02-category-picker-open-1440.png');
  await p.type('.dds-pop__input', 'цех');
  await shot('03-category-picker-search-nested-1440.png');
  await p.keyboard.press('Escape');

  // Валидация: пустая сумма и дата → сводка, рамки, фокус на первое поле.
  await p.$eval('[data-dds-op-amount-input]', (n) => { n.value = ''; n.dispatchEvent(new Event('input', { bubbles: true })); });
  await p.$eval('#cash_transaction_occurredAt', (n) => { n.value = ''; n.dispatchEvent(new Event('input', { bubbles: true })); });
  await p.click('[data-dds-op-save]');
  await t('summary with 2 errors, texts from spec', async () => {
    const r = await p.evaluate(() => [
      document.querySelector('[data-dds-op-summary]').hidden,
      document.querySelector('[data-dds-op-summary-title]').textContent,
      [...document.querySelectorAll('[data-dds-op-summary-list] li')].map((li) => li.textContent),
    ]);
    return (r[0] === false && r[1] === 'Не удалось сохранить: 2. Исправьте отмеченные поля.' && r[2].join('|') === 'Дата: Укажите дату операции|Сумма: Укажите сумму') || JSON.stringify(r);
  });
  await t('invalid fields marked, text under field', async () => (await p.evaluate(() => document.querySelector('#cash_transaction_amount').classList.contains('is-invalid')
    && document.querySelector('#cash_transaction_amount + .invalid-feedback').textContent === 'Укажите сумму')) || 'not marked');
  await t('focus on first invalid', async () => (await p.evaluate(() => document.activeElement.id)) === 'cash_transaction_occurredAt' || await p.evaluate(() => document.activeElement.id));
  await t('page not submitted', async () => p.url().endsWith(EDIT) || p.url());
  await shot('04-edit-validation-errors-1440.png');
  for (const [value, text] of [['abc', 'Введите число, например 1 250,00'], ['0', 'Сумма должна быть больше нуля']]) {
    await p.$eval('[data-dds-op-amount-input]', (n, v) => { n.value = v; }, value);
    await p.click('[data-dds-op-save]');
    await t(`amount "${value}" → ${text}`, async () => (await $text('#cash_transaction_amount + .invalid-feedback')) === text || await $text('#cash_transaction_amount + .invalid-feedback'));
  }
  await open();

  // Уход с правками: модалка, «Остаться», затем «Выйти».
  await p.type('[data-dds-op-description]', ' правка');
  await p.click('[data-dds-op-cancel]');
  await t('leave modal shown', () => modalShown('[data-dds-op-leave]'));
  await shot('06-edit-leave-modal-1440.png');
  await p.click('[data-dds-op-leave] [data-bs-dismiss="modal"]');
  await p.waitForFunction(() => !document.querySelector('[data-dds-op-leave]').classList.contains('show') && !document.querySelector('.modal-backdrop'));
  await t('stay keeps page', async () => p.url().endsWith(EDIT) || p.url());
  await p.click('[data-dds-op-cancel]');
  await modalShown('[data-dds-op-leave]');
  await Promise.all([p.waitForNavigation(), p.click('[data-dds-op-leave-confirm]')]);
  await t('leave confirmed → list', async () => p.url().includes('/finance/cash-transactions') && !p.url().endsWith('/edit') || p.url());
  await open();
  await Promise.all([p.waitForNavigation(), p.click('[data-dds-op-cancel]')]);
  await t('cancel without changes leaves at once', async () => !p.url().endsWith('/edit') || p.url());

  // Смена направления сбрасывает статью-отток и показывает заметку.
  await open();
  await p.click('.dds-seg__opt--in .dds-seg__text');
  await t('direction reset note', async () => /не подходит для притока и сброшена/.test(await p.$eval('.dds-op__field--wide .dds-field__note', (n) => n.textContent)) || 'no note');
  await shot('09-direction-reset-note-1440.png');

  // Сохранение суммы с запятой.
  await open();
  await p.$eval('[data-dds-op-amount-input]', (n) => { n.value = '5300,50'; n.dispatchEvent(new Event('input', { bubbles: true })); });
  await Promise.all([p.waitForNavigation(), p.click('[data-dds-op-save]')]);
  await open();
  await t('saved 5300,50', async () => (await p.$eval('[data-dds-op-amount-input]', (n) => n.value)) === '5300,50' || await p.$eval('[data-dds-op-amount-input]', (n) => n.value));
  await p.$eval('[data-dds-op-amount-input]', (n) => { n.value = '5300'; n.dispatchEvent(new Event('input', { bubbles: true })); });
  await Promise.all([p.waitForNavigation(), p.click('[data-dds-op-save]')]);

  // Удаление через «Ещё» и восстановление.
  await open();
  await p.click('.dds-op__head [data-bs-toggle="dropdown"]');
  await p.click('.dds-op__head .dropdown-item');
  await t('delete modal shown', () => modalShown('#dds-op-confirm'));
  await shot('07-edit-delete-modal-1440.png');
  await Promise.all([p.waitForNavigation(), p.click('#dds-op-confirm [data-confirm-submit]')]);
  await p.goto(`http://localhost${EDIT}`, { waitUntil: 'networkidle0' });
  await t('deleted banner + read-only', async () => (await p.evaluate(() => !!document.querySelector('.dds-op__banner--warn') && document.querySelector('.dds-op__fieldset').disabled)) || 'no banner');
  await shot('deleted-1440.png');
  await Promise.all([p.waitForNavigation(), p.click('.dds-op__banner--warn button[type=submit]')]);
  await open();
  await t('restored', async () => (await p.$$('.dds-op__banner--warn')).length === 0 || 'still deleted');

  for (const width of [1280, 1100]) {
    await p.setViewport({ width, height: 1000 });
    await open();
    await t(`no horizontal scroll at ${width}`, async () => (await p.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)) || 'overflow');
    await shot(`01-edit-default-${width}.png`, true);
  }
  await p.setViewport({ width: 390, height: 844 });
  await open();
  await t('no horizontal scroll at 390', async () => (await p.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)) || 'overflow');
  await shot('08-edit-mobile-390.png', true);
  await p.click('.dds-op__field--wide .dds-field__main');
  await t('popover inside screen at 390', async () => (await p.$eval('.dds-pop', (n) => { const r = n.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })) || 'out of screen');

  await t('no page errors', async () => errs.length === 0 || errs.join('|'));
  await browser.close();
  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
