// Браузерный сценарий «Новый документ ОПиУ» на поднятом стенде (не входит в phpunit).
// Нужны: приложение по адресу SITE_IP (Host: localhost), пользователь owner@app.ru/password
// с ролью владельца; у компании — проект PROJECT_ID (лист), ЦФО CFO_ID, разрешённый в паре с ним,
// и хотя бы одна листовая статья ОПиУ.
// Запуск (Chrome в контейнере, сеть стенда):
//   docker run --rm --network <сеть> -e NODE_PATH=/usr/src/app/node_modules -e SITE_IP=<ip> \
//     -e PROJECT_ID=<uuid> -e CFO_ID=<uuid> \
//     -v $PWD:/site -v <папка>:/shots -w /site --entrypoint node zenika/alpine-chrome:with-puppeteer \
//     tests/e2e/pnl-document-new.e2e.cjs /shots
// Сценарий создаёт документ — гонять на стенде, не на проде.
const path = require('path');
const puppeteer = require('puppeteer');

const { SITE_IP, PROJECT_ID, CFO_ID } = process.env;
const SHOTS = process.argv[2] || '';
const NEW = '/documents/new';

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
  const dropToolbar = () => p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
  const shot = async (name, full = true) => {
    if (!SHOTS) return;
    await dropToolbar();
    await p.screenshot({ path: path.join(SHOTS, name), fullPage: full });
  };
  // Панель отладки Symfony (dev-стенд) перекрывает кнопки подвала — убираем сразу после загрузки.
  const open = async () => {
    await p.goto(`http://localhost${NEW}`, { waitUntil: 'networkidle0' });
    await dropToolbar();
  };
  const submit = async () => {
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('[data-document-save]')]);
    await dropToolbar();
  };

  await p.goto('http://localhost/login');
  await p.type('input[name=_username]', 'owner@app.ru');
  await p.type('input[name=_password]', 'password');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await open();

  await t('заголовок, крошки, «Создать документ», статус «ещё не создан»', async () => {
    const r = await p.evaluate(() => ({
      h1: document.querySelector('.vf-page-head h1').textContent.trim(),
      crumb: document.querySelector('.breadcrumb-item.active').textContent.trim(),
      save: document.querySelector('[data-document-save]').textContent.trim(),
      saveDisabled: document.querySelector('[data-document-save]').disabled,
      state: document.querySelector('[data-dirty-text]').textContent.trim(),
      badge: document.querySelector('[data-dirty-badge]').hidden,
      more: !!document.querySelector('.vf-page-head .dropdown-menu'),
    }));
    return r.h1 === 'Новый документ ОПиУ' && r.crumb === 'Новый документ' && r.save === 'Создать документ' && !r.saveDisabled
      && r.state.startsWith('Документ ещё не создан') && r.badge && !r.more || JSON.stringify(r);
  });

  await t('одна пустая операция при открытии, удалить её нельзя, форма не «изменена»', async () => {
    const r = await p.evaluate(() => ({
      rows: document.querySelectorAll('[data-operation-row]').length,
      amount: document.querySelector('[data-operation-row] [data-operation-amount]').value,
      removeDisabled: document.querySelector('[data-operation-row] .remove-item').disabled,
      empty: document.querySelector('[data-operations-empty]').hidden,
      count: document.querySelector('[data-operations-count]').textContent.trim(),
      picker: !!document.querySelector('[data-operation-row] .vf-docform-op__cell--category .dds-field__main'),
    }));
    return r.rows === 1 && r.amount === '' && r.removeDisabled && r.empty && r.count === '1' && r.picker || JSON.stringify(r);
  });

  await t('справа «Как это работает», при 1440 — рядом с «Основными данными»', async () => {
    const r = await p.evaluate(() => {
      const help = document.querySelector('[data-document-help]');
      const main = document.querySelector('.vf-docform-main');
      return { title: help?.querySelector('.card-title').textContent.trim(), right: help && help.getBoundingClientRect().left > main.getBoundingClientRect().right - 1 };
    });
    return r.title === 'Как это работает' && r.right || JSON.stringify(r);
  });
  await shot('pnl-09-new-default-1440.png');

  await t('«Отмена» без правок — уход без диалога', async () => {
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('.vf-docform-footer a.btn')]);
    return !p.url().includes(NEW) || p.url();
  });

  await open();
  await t('Проект и ЦФО шапки подставляются в операцию; «Отмена» после правки — диалог', async () => {
    const before = await p.$eval('[data-operation-row] [data-operation-project-direction]', (s) => s.value);
    await p.$eval('[data-document-project-direction]', (s, v) => { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, PROJECT_ID);
    await p.$eval('[data-document-responsibility-center]', (s, v) => { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, CFO_ID);
    const project = await p.$eval('[data-operation-row] [data-operation-project-direction]', (s) => s.value);
    const cfo = await p.$eval('[data-operation-row] [data-operation-responsibility-center]', (s) => s.value);
    const marked = await p.$$eval('[data-operation-row] [data-inherit-mark]', (n) => n.some((m) => !m.hidden));
    await p.waitForSelector('[data-dirty-badge]:not([hidden])', { timeout: 3000 });
    await p.click('.vf-docform-footer a.btn');
    // Закрыть модалку Bootstrap можно только после анимации показа — ждём полной непрозрачности.
    await p.waitForFunction(() => {
      const m = document.querySelector('#document-leave-modal.show');
      return !!m && getComputedStyle(m).opacity === '1';
    }, { polling: 100, timeout: 5000 });
    await p.click('#document-leave-modal [data-bs-dismiss="modal"]');
    await p.waitForSelector('#document-leave-modal.show', { hidden: true, timeout: 3000 });
    await p.waitForFunction(() => !document.querySelector('.modal-backdrop'), { timeout: 3000 });
    return before === '' && project === PROJECT_ID && cfo === CFO_ID && !marked && p.url().includes(NEW) || JSON.stringify({ before, project, cfo, marked });
  });

  await t('дерево категорий открывается в новой строке', async () => {
    await p.click('[data-operation-row] .vf-docform-op__cell--category .dds-field__main');
    await p.waitForSelector('[data-operation-row] .vf-docform-op__cell--category .dds-pop:not([hidden])', { timeout: 3000 });
    return true;
  });
  await shot('pnl-10-new-category-picker-open-1440.png', false);
  await p.keyboard.press('Escape');

  await t('без суммы — сводка ошибок, строка на месте, фокус на поле', async () => {
    await submit();
    const r = await p.evaluate(() => ({
      summary: document.querySelector('[data-error-summary] .vf-docform-summary__title')?.textContent.trim(),
      rows: document.querySelectorAll('[data-operation-row]').length,
      focus: document.activeElement?.classList.contains('is-invalid'),
      save: document.querySelector('[data-document-save]').textContent.trim(),
    }));
    return (r.summary || '').startsWith('Не удалось сохранить') && r.rows === 1 && r.focus && r.save === 'Создать документ' || JSON.stringify(r);
  });
  await shot('pnl-11-new-validation-errors-1440.png');

  await t('«Создать документ» — переход в список и тост «Документ ОПиУ создан.»', async () => {
    const leaf = await p.$eval('[data-operation-row] [data-operation-category]', (s) => [...s.options].find((o) => o.value && o.dataset.flow && o.dataset.flow !== 'NONE')?.value || '');
    await p.$eval('[data-operation-row] [data-operation-category]', (s, v) => { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, leaf);
    await p.$eval('[data-operation-row] [data-operation-amount]', (i) => { i.value = '1 500,00'; i.dispatchEvent(new Event('input', { bubbles: true })); });
    await submit();
    const toast = await p.evaluate(() => document.body.textContent.includes('Документ ОПиУ создан.'));
    return leaf !== '' && !p.url().includes(NEW) && toast || JSON.stringify({ leaf, url: p.url(), toast });
  });

  await p.setViewport({ width: 390, height: 900, isMobile: true });
  await open();
  await t('390 — карточка операции, без горизонтального скролла', async () => {
    const r = await p.evaluate(() => ({
      scroll: document.documentElement.scrollWidth - window.innerWidth,
      head: getComputedStyle(document.querySelector('.vf-docform-ops__head')).display,
    }));
    return r.scroll <= 0 && r.head === 'none' || JSON.stringify(r);
  });
  await shot('pnl-12-new-mobile-390.png');

  await t('без ошибок JS', async () => errs.length === 0 || errs.join(' | '));

  console.log(`\n${passed} passed, ${failed} failed`);
  await browser.close();
  process.exit(failed ? 1 : 0);
})();
