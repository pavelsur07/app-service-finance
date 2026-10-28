// Браузерный сценарий страницы «Документ ОПиУ» (правка) на поднятом стенде (не входит в phpunit).
// Нужны: приложение по адресу SITE_IP (Host: localhost), пользователь owner@app.ru/password
// с ролью владельца; документ DOC_ID с основанием ДДС и двумя операциями:
//   1) Проект и ЦФО — как в шапке, сумма 70 000, описание «Аренда офиса, октябрь»;
//   2) Проект задан вручную (не как в шапке), ЦФО — как в шапке, сумма 30 000.
// У компании — ещё проект PROJECT_ID (лист) и ЦФО CFO_ID, разрешённые в паре с проектами документа.
// Запуск (Chrome в контейнере, сеть стенда):
//   docker run --rm --network <сеть> -e NODE_PATH=/usr/src/app/node_modules -e SITE_IP=<ip> \
//     -e DOC_ID=<uuid> -e PROJECT_ID=<uuid> -e CFO_ID=<uuid> \
//     -v $PWD:/site -v <папка>:/shots -w /site --entrypoint node zenika/alpine-chrome:with-puppeteer \
//     tests/e2e/pnl-document-page.e2e.cjs /shots
// Сценарий сохраняет документ — гонять на стенде, не на проде.
const path = require('path');
const puppeteer = require('puppeteer');

const { SITE_IP, DOC_ID, PROJECT_ID, CFO_ID } = process.env;
const SHOTS = process.argv[2] || '';
const EDIT = `/documents/${DOC_ID}/edit`;

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
  const shot = async (name, full = true) => {
    if (!SHOTS) return;
    await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
    await p.screenshot({ path: path.join(SHOTS, name), fullPage: full });
  };
  // Панель отладки Symfony (dev-стенд) перекрывает кнопки подвала — убираем сразу после загрузки.
  const open = async () => {
    await p.goto(`http://localhost${EDIT}`, { waitUntil: 'networkidle0' });
    await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
  };
  const row = (i) => `[data-operation-row]:nth-child(${i + 1})`;
  const setHeader = (sel, value) => p.$eval(sel, (s, v) => { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, value);
  const rowValues = (field) => p.$$eval(`[data-operation-row] ${field}`, (n) => n.map((s) => s.value));
  const marks = (kind) => p.$$eval(`[data-operation-row] [data-inherit-mark="${kind}"]`, (n) => n.map((m) => !m.hidden));

  await p.goto('http://localhost/login');
  await p.type('input[name=_username]', 'owner@app.ru');
  await p.type('input[name=_password]', 'password');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await open();

  const headerProject = '[data-document-project-direction]';
  const headerCfo = '[data-document-responsibility-center]';
  const opProject = '[data-operation-project-direction]';
  const opCfo = '[data-operation-responsibility-center]';
  const initialHeaderProject = await p.$eval(headerProject, (s) => s.value);
  const initialOps = await rowValues(opProject);

  await t('шапка: Проект и ЦФО в «Основных данных», плашка, счётчик описания', async () => {
    const r = await p.evaluate(() => ({
      plaque: document.querySelector('.vf-docform-inherit')?.textContent.trim().startsWith('Проект и ЦФО подставляются во все операции документа'),
      counter: document.querySelector('[data-document-description-counter]').textContent,
      len: document.querySelector('[data-document-description]').value.length,
      cfoPicker: !!document.querySelector('[data-dds-picker] [data-document-responsibility-center]'),
      cols: getComputedStyle(document.querySelector('.vf-docform-fields')).gridTemplateColumns.split(' ').length,
    }));
    return r.plaque && r.counter === `${r.len} из 500` && r.cfoPicker && r.cols === 2 || JSON.stringify(r);
  });

  await t('«Сохранить» неактивна без изменений, статус «Все изменения сохранены»', async () => {
    const r = await p.evaluate(() => ({
      disabled: document.querySelector('[data-document-save]').disabled,
      state: document.querySelector('[data-dirty-text]').textContent,
    }));
    return r.disabled && r.state === 'Все изменения сохранены' || JSON.stringify(r);
  });

  await t('«Ещё»: Дублировать, Открыть операцию ДДС, Выгрузить JSON, Удалить документ', async () => {
    const items = await p.$$eval('.vf-page-head .dropdown-menu .dropdown-item', (n) => n.map((i) => i.textContent.trim()));
    return JSON.stringify(items) === JSON.stringify(['Дублировать документ', 'Открыть операцию ДДС', 'Выгрузить JSON', 'Удалить документ']) || JSON.stringify(items);
  });

  await t('суммы в формате «70 000,00»', async () => {
    const v = await rowValues('[data-operation-amount]');
    return v[0] === '70 000,00' && v[1] === '30 000,00' || JSON.stringify(v);
  });

  await t('операция с проектом не как в шапке — «Изменён» + «Как в шапке»', async () => {
    const m = await marks('project');
    const text = await p.$eval(`${row(1)} [data-inherit-mark="project"]`, (n) => n.textContent.replace(/\s+/g, ' ').trim());
    return m[0] === false && m[1] === true && text === 'Изменён Как в шапке' || JSON.stringify({ m, text });
  });
  await shot('pnl-01-edit-default-1440.png');

  await t('описание — вторая строка, aria-label «Описание, операция N», placeholder', async () => {
    const r = await p.$$eval('[data-operation-comment]', (n) => n.map((i) => [i.getAttribute('aria-label'), i.placeholder, i.maxLength]));
    return r[0][0] === 'Описание, операция 1' && r[1][0] === 'Описание, операция 2' && r[1][1] === 'За что платим, например: аренда офиса, октябрь' || JSON.stringify(r);
  });

  await t('смена проекта шапки: меняется только наследующая операция, уведомление N из M', async () => {
    await setHeader(headerProject, PROJECT_ID);
    const v = await rowValues(opProject);
    const notice = await p.$eval('[data-inherit-notice]', (n) => (n.hidden ? '' : n.textContent));
    const ok = v[0] === PROJECT_ID && v[1] === initialOps[1]
      && /^Проект «.+» подставлен в 1 из 2 операций\. В 1 задан вручную\.$/.test(notice);
    return ok || JSON.stringify({ v, notice });
  });

  await t('после смены шапки «Сохранить» активна, статус — несохранённые изменения', async () => {
    const r = await p.evaluate(() => [document.querySelector('[data-document-save]').disabled, document.querySelector('[data-dirty-text]').textContent, document.querySelector('[data-dirty-badge]').hidden]);
    return r[0] === false && r[1] === 'Есть несохранённые изменения' && r[2] === false || JSON.stringify(r);
  });

  await t('«Как в шапке» возвращает значение шапки и снимает пометку', async () => {
    await p.click(`${row(1)} [data-inherit-reset="project"]`);
    const v = await rowValues(opProject);
    const m = await marks('project');
    return v[1] === PROJECT_ID && m.every((x) => !x) || JSON.stringify({ v, m });
  });

  await t('ЦФО: ручной выбор в операции → «Изменён»; смена шапки его не трогает', async () => {
    await p.$eval(`${row(0)} ${opCfo}`, (s, v) => { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, CFO_ID);
    const before = await marks('cfo');
    await setHeader(headerCfo, CFO_ID);
    const v = await rowValues(opCfo);
    const after = await marks('cfo');
    const notice = await p.$eval('[data-inherit-notice]', (n) => n.textContent);
    // строка 1 уже совпадает с новой шапкой — пометка пропадает; строка 2 наследовала — получила новое значение
    return before[0] === true && v.every((x) => x === CFO_ID) && after.every((x) => !x)
      && /^ЦФО «Администрация.*» подставлен в 1 из 2 операций\. В 1 задан вручную\.$/.test(notice) || JSON.stringify({ before, v, after, notice });
  });

  await t('виджет ЦФО строки показывает подставленное значение', async () => {
    const text = await p.$eval(`${row(1)} .vf-docform-op__cell--responsibilityCenterId .dds-field__value`, (n) => n.textContent);
    return text.startsWith('Администрация') || text;
  });

  await t('новая операция берёт Проект и ЦФО шапки, фокус на категории', async () => {
    await p.click('.add_item_link');
    await p.waitForFunction(() => document.activeElement && document.activeElement.closest('[data-operation-row]:last-child .vf-docform-op__cell--category'), { timeout: 3000 });
    const r = await p.$eval('[data-operation-row]:last-child', (n, ids) => ({
      project: n.querySelector('[data-operation-project-direction]').value === ids[0],
      cfo: n.querySelector('[data-operation-responsibility-center]').value === ids[1],
      marks: [...n.querySelectorAll('[data-inherit-mark]')].every((m) => m.hidden),
      comment: n.querySelector('[data-operation-comment]').value,
    }), [PROJECT_ID, CFO_ID]);
    await p.click('[data-operation-row]:last-child .remove-item');
    return r.project && r.cfo && r.marks && r.comment === '' || JSON.stringify(r);
  });

  await t('дубль: копия категории, проекта, ЦФО, суммы и описания — сразу под исходной', async () => {
    await p.click(`${row(0)} [data-operation-duplicate]`);
    await p.waitForFunction(() => document.querySelectorAll('[data-operation-row]')[1].querySelector('[data-dds-picker].is-ready'), { timeout: 3000 });
    const r = await p.$$eval('[data-operation-row]', (rows) => rows.slice(0, 2).map((n) => [
      n.querySelector('[data-operation-category]').value,
      n.querySelector('[data-operation-project-direction]').value,
      n.querySelector('[data-operation-responsibility-center]').value,
      n.querySelector('[data-operation-amount]').value,
      n.querySelector('[data-operation-comment]').value,
      n.querySelector('.vf-docform-op__cell--category .dds-field__value').textContent,
    ]));
    const count = await p.$eval('[data-operations-count]', (n) => n.textContent);
    return JSON.stringify(r[0]) === JSON.stringify(r[1]) && r[1][4] === 'Аренда офиса, октябрь' && count === '3' || JSON.stringify({ r, count });
  });

  await t('превышение остатка: сверка предупреждает', async () => {
    const r = await p.evaluate(() => [
      document.querySelector('[data-allocation-over]').hidden,
      document.querySelector('[data-allocation-rest]').textContent,
    ]);
    return r[0] === false || JSON.stringify(r);
  });

  await t('удаление дубля возвращает сверку; единственную операцию удалить нельзя', async () => {
    await p.click(`${row(1)} .remove-item`);
    const over = await p.$eval('[data-allocation-over]', (n) => n.hidden);
    await p.click(`${row(1)} .remove-item`);
    const disabled = await p.$eval(`${row(0)} .remove-item`, (b) => b.disabled);
    return over && disabled || JSON.stringify({ over, disabled });
  });

  await t('«Отмена» с правками — диалог «Выйти без сохранения?»', async () => {
    await p.click('.vf-docform-footer a.btn');
    await p.waitForSelector('#document-leave-modal.show', { timeout: 3000 });
    await shot('pnl-06-edit-leave-modal-1440.png', false);
    await p.click('#document-leave-modal [data-bs-dismiss="modal"]');
    await p.waitForSelector('#document-leave-modal.show', { hidden: true, timeout: 3000 });
    await p.waitForFunction(() => !document.querySelector('.modal-backdrop') && !document.body.classList.contains('modal-open'), { timeout: 3000 });
    return true;
  });

  await t('сохранение: Проект/ЦФО шапки и операции, сумма с разрядами', async () => {
    await p.$eval(`${row(0)} [data-operation-amount]`, (n) => { n.value = '71 500,50'; n.dispatchEvent(new Event('input', { bubbles: true })); });
    await Promise.all([p.waitForNavigation(), p.click('[data-document-save]')]);
    await open();
    const r = await p.evaluate(() => ({
      header: [document.querySelector('[data-document-project-direction]').value, document.querySelector('[data-document-responsibility-center]').value],
      ops: [...document.querySelectorAll('[data-operation-row]')].map((n) => [n.querySelector('[data-operation-project-direction]').value, n.querySelector('[data-operation-responsibility-center]').value, n.querySelector('[data-operation-amount]').value]),
    }));
    return r.header[0] === PROJECT_ID && r.header[1] === CFO_ID && r.ops.length === 1
      && r.ops[0][0] === PROJECT_ID && r.ops[0][1] === CFO_ID && r.ops[0][2] === '71 500,50' || JSON.stringify(r);
  });

  await t('ошибка сервера: пустая сумма — сводка, рамка, фокус на поле', async () => {
    await p.$eval(`${row(0)} [data-operation-amount]`, (n) => { n.value = ''; n.dispatchEvent(new Event('input', { bubbles: true })); });
    await Promise.all([p.waitForNavigation(), p.click('[data-document-save]')]);
    await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
    const r = await p.evaluate(() => ({
      summary: document.querySelector('[data-error-summary] .vf-docform-summary__title')?.textContent || '',
      item: document.querySelector('[data-error-summary] li')?.textContent || '',
      invalid: document.querySelector('[data-operation-amount]').classList.contains('is-invalid'),
      focus: document.activeElement === document.querySelector('[data-operation-amount]'),
      save: document.querySelector('[data-document-save]').disabled,
    }));
    await shot('pnl-04-edit-validation-errors-1440.png');
    return /^Не удалось сохранить: 1 ошибка/.test(r.summary) && /Операция 1, сумма: Введите сумму/.test(r.item) && r.invalid && r.focus && !r.save || JSON.stringify(r);
  });

  await open();
  await t('«Удалить документ» — модалка с CSRF', async () => {
    await p.click('.vf-page-head [data-bs-toggle="dropdown"]');
    await p.click('[data-bs-target="#document-delete-modal"]');
    await p.waitForSelector('#document-delete-modal.show', { timeout: 3000 });
    await shot('pnl-07-edit-delete-modal-1440.png', false);
    const r = await p.$eval('#document-delete-modal', (m) => [m.querySelector('.modal-title').textContent, !!m.querySelector('input[name="_token"]').value]);
    return r[0] === 'Удалить документ?' && r[1] || JSON.stringify(r);
  });

  for (const w of [1280, 1100]) {
    await p.setViewport({ width: w, height: 1000 });
    await open();
    await t(`${w}: без горизонтального скролла`, async () => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth) || 'scroll');
    await shot(`pnl-01-edit-default-${w}.png`);
  }
  await p.setViewport({ width: 390, height: 900, isMobile: true });
  await open();
  await t('390: операции карточками, горизонтального скролла нет', async () => {
    const r = await p.evaluate(() => ({
      scroll: document.documentElement.scrollWidth <= window.innerWidth,
      head: getComputedStyle(document.querySelector('.vf-docform-ops__head')).display,
      category: Math.round(document.querySelector('.vf-docform-op__cell--category').getBoundingClientRect().width),
      card: Math.round(document.querySelector('.vf-docform-op').getBoundingClientRect().width),
    }));
    return r.scroll && r.head === 'none' && r.category > r.card * 0.8 || JSON.stringify(r);
  });
  await shot('pnl-08-edit-mobile-390.png');

  await t('ошибок JS нет', async () => errs.length === 0 || errs.join(' | '));

  console.log(`\n${passed} passed, ${failed} failed`);
  await browser.close();
  process.exit(failed ? 1 : 0);
})();
