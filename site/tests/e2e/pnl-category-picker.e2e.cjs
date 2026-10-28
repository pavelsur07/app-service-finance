// Браузерный сценарий виджета «Категория ОПиУ» на странице документа (не входит в phpunit).
// Нужны: приложение по адресу SITE_IP (Host: localhost), пользователь owner@app.ru/password
// с ролью владельца, документ DOC_ID с двумя операциями-расходами и дерево статей ОПиУ, где
// есть ветка «Производственный цех › Цех № 1 & <b>литьё</b> › Электроэнергия цеха» (4 группы + лист)
// и доходная статья «Выкупы без СПП».
// Запуск (Chrome в контейнере, сеть стенда):
//   docker run --rm --network <сеть> -e NODE_PATH=/usr/src/app/node_modules -e SITE_IP=<ip> -e DOC_ID=<uuid> \
//     -v $PWD:/site -v <папка>:/shots -w /site --entrypoint node zenika/alpine-chrome:with-puppeteer \
//     tests/e2e/pnl-category-picker.e2e.cjs /shots
// Сценарий сохраняет документ со сменой категории — гонять на стенде, не на проде.
const path = require('path');
const puppeteer = require('puppeteer');

const { SITE_IP, DOC_ID } = process.env;
const SHOTS = process.argv[2] || '';
const EDIT = `/documents/${DOC_ID}/edit`;

(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/chromium-browser',
    ignoreDefaultArgs: ['--hide-scrollbars'],
    args: ['--no-sandbox', '--disable-dev-shm-usage', `--host-resolver-rules=MAP localhost ${SITE_IP}`],
  });
  const p = await browser.newPage();
  // высокое окно: строки операций не прячутся под прилипшей панелью сохранения
  await p.setViewport({ width: 1440, height: 1500 });
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.message));

  let failed = 0;
  let passed = 0;
  const t = async (name, fn) => {
    let r;
    try { r = await fn(); } catch (e) { r = e.message; }
    if (r === true) { passed += 1; console.log('OK  ', name); } else { failed += 1; console.log('FAIL', name, r); }
  };
  const shot = async (name) => {
    if (!SHOTS) return;
    await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));
    await p.screenshot({ path: path.join(SHOTS, name) });
  };
  const row = (i) => `[data-operation-row]:nth-child(${i + 1})`;
  const pick = (i) => `${row(i)} .vf-docform-op__cell--category [data-dds-picker]`;
  const openState = () => p.$$eval('.vf-docform-op__cell--category [data-dds-picker] .dds-pop', (pops) => pops.map((n) => !n.hidden));
  const visibleRows = () => p.$$eval(`${pick(0)} .dds-tree__row`, (rows) => rows.map((r) => ({
    name: r.querySelector('.dds-tree__name').textContent,
    group: r.classList.contains('dds-tree__group'),
    d: Number(r.dataset.d),
    tag: r.querySelector('.dds-tree__tag')?.textContent || '',
    count: r.querySelector('.dds-tree__count')?.textContent || '',
    cur: r.dataset.cur === 'true',
  })));

  await p.goto('http://localhost/login');
  await p.type('input[name=_username]', 'owner@app.ru');
  await p.type('input[name=_password]', 'password');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(`http://localhost${EDIT}`, { waitUntil: 'networkidle0' });
  // панель отладки Symfony (dev-стенд) перекрывает подвал
  await p.evaluate(() => document.querySelectorAll('.sf-toolbar, [id^="sfToolbar"], [id^="sfwdt"]').forEach((n) => n.remove()));

  await t('виджет в каждой строке, select спрятан, дерево одно на страницу', async () => {
    const r = await p.evaluate(() => ({
      pickers: document.querySelectorAll('[data-operation-row] .vf-docform-op__cell--category [data-dds-picker].is-ready').length,
      rows: document.querySelectorAll('[data-operation-row]').length,
      // select остаётся в форме, но визуально скрыт (1×1, clip)
      selectVisible: [...document.querySelectorAll('[data-operation-row] [data-dds-picker] select.dds-picker__native')].some((s) => s.getBoundingClientRect().width > 1),
      trees: document.querySelectorAll('script#document-category-tree').length,
    }));
    return r.pickers === r.rows && r.rows >= 2 && !r.selectVisible && r.trees === 1 || JSON.stringify(r);
  });

  await t('поле: название, путь под полем, × очистки', async () => {
    const r = await p.$eval(pick(0), (n) => ({
      value: n.querySelector('.dds-field__value').textContent,
      pathBelow: n.querySelector('.dds-field + .dds-field__path--below')?.textContent || '',
      clear: !n.querySelector('.dds-field__clear').hidden,
    }));
    return r.value === 'Аренда' && r.pathBelow === 'Операционные расходы' && r.clear || JSON.stringify(r);
  });

  await t('открытие: popover, фокус в поиске, плейсхолдер «Найти категорию»', async () => {
    await p.click(`${pick(0)} .dds-field__main`);
    const r = await p.evaluate(() => ({
      focus: document.activeElement.classList.contains('dds-pop__input'),
      ph: document.activeElement.getAttribute('placeholder'),
      width: Math.round(document.activeElement.closest('.dds-pop').getBoundingClientRect().width),
    }));
    await shot('pnl-02-category-picker-open-1440.png');
    return r.focus && r.ph === 'Найти категорию' && r.width === 440 || JSON.stringify(r);
  });

  await t('теги листьев «Доход»/«Расход», группа считает листья', async () => {
    const rows = await visibleRows();
    const sales = rows.find((r) => r.name === 'Выкупы без СПП');
    const rent = rows.find((r) => r.name === 'Аренда');
    const op = rows.find((r) => r.group && r.name === 'Операционные расходы');
    return sales?.tag === 'Доход' && rent?.tag === 'Расход' && op?.count === '7' || JSON.stringify({ sales, rent, op });
  });

  await t('клик по группе раскрывает/сворачивает, значение не меняется', async () => {
    const before = await p.$eval(`${row(0)} [data-operation-category]`, (s) => s.value);
    const toggle = async () => p.evaluate(() => {
      const g = [...document.querySelectorAll('.dds-pop:not([hidden]) .dds-tree__group')].find((n) => n.textContent.includes('Выручка'));
      g.click();
      return g.getAttribute('aria-expanded');
    });
    const first = await toggle();
    const collapsed = await p.evaluate(() => [...document.querySelectorAll('.dds-pop:not([hidden]) .dds-tree__group')].find((n) => n.textContent.includes('Выручка')).getAttribute('aria-expanded'));
    await p.evaluate(() => [...document.querySelectorAll('.dds-pop:not([hidden]) .dds-tree__group')].find((n) => n.textContent.includes('Выручка')).click());
    const after = await p.$eval(`${row(0)} [data-operation-category]`, (s) => s.value);
    return first === 'true' && collapsed === 'false' && before === after || JSON.stringify({ first, collapsed, before, after });
  });

  await t('открыт один popover: открытие второй строки закрывает первую', async () => {
    // кнопку второй строки закрывает popover первой — открываем с клавиатуры/программно, без mousedown
    await p.$eval(`${pick(1)} .dds-field__main`, (b) => b.click());
    const s = await openState();
    return s[0] === false && s[1] === true || JSON.stringify(s);
  });

  await t('клик вне закрывает', async () => {
    await p.mouse.click(700, 120);
    const s = await openState();
    return s.every((v) => !v) || JSON.stringify(s);
  });

  await t('поиск «цех»: совпала группа → видны её листья, глубина 4, подсветка, первая строка активна', async () => {
    await p.click(`${pick(0)} .dds-field__main`);
    await p.keyboard.type('цех');
    const rows = await visibleRows();
    const leaf = rows.find((r) => r.name === 'Электроэнергия цеха');
    const repair = rows.find((r) => r.name === 'Ремонт оборудования цеха');
    const hits = await p.$$eval(`${pick(0)} .dds-tree__hit`, (n) => n.length);
    const firstLeafCur = rows.filter((r) => !r.group)[0]?.cur;
    await shot('pnl-03-category-search-nested-1440.png');
    return leaf?.d === 4 && !!repair && hits > 0 && firstLeafCur === true || JSON.stringify({ leaf, repair, hits, firstLeafCur });
  });

  await t('направляющие: группа 4-го уровня сдвинута на 3 шага по 22 px от корня', async () => {
    const pad = await p.$$eval(`${pick(0)} .dds-tree__group`, (rows) => {
      const deep = rows.find((r) => r.dataset.d === '3');
      const root = rows.find((r) => r.dataset.d === '0');
      return parseFloat(getComputedStyle(deep).paddingLeft) - parseFloat(getComputedStyle(root).paddingLeft);
    });
    return pad === 66 || String(pad);
  });

  await t('↓ и Enter выбирают лист; select меняется, popover закрыт, фокус на кнопке', async () => {
    await p.keyboard.press('ArrowDown');
    const curName = await p.$eval(`${pick(0)} .dds-tree__row[data-cur="true"] .dds-tree__name`, (n) => n.textContent);
    await p.keyboard.press('Enter');
    const r = await p.evaluate((sel) => {
      const root = document.querySelector(sel);
      const s = root.querySelector('select');
      return {
        text: s.selectedOptions[0].textContent.trim(),
        open: !root.querySelector('.dds-pop').hidden,
        focus: document.activeElement === root.querySelector('.dds-field__main'),
        value: root.querySelector('.dds-field__value').textContent,
      };
    }, pick(0));
    return r.value === curName && r.text.endsWith(curName) && !r.open && r.focus || JSON.stringify({ curName, ...r });
  });

  await t('XSS: название группы в пути — текстом, разметки нет', async () => {
    await p.click(`${pick(0)} .dds-field__main`);
    await p.keyboard.type('электро');
    await p.keyboard.press('Enter');
    const r = await p.$eval(pick(0), (n) => ({ path: n.querySelector('.dds-field__path--below').textContent, b: n.querySelectorAll('b').length }));
    return r.path === 'Операционные расходы › Управленческие расходы › Производственный цех › Цех № 1 & <b>литьё</b>' && r.b === 0 || JSON.stringify(r);
  });

  await t('Esc закрывает и возвращает фокус на кнопку', async () => {
    await p.click(`${pick(1)} .dds-field__main`);
    await p.keyboard.press('Escape');
    const r = await p.evaluate((sel) => ({
      open: !document.querySelector(`${sel} .dds-pop`).hidden,
      focus: document.activeElement === document.querySelector(`${sel} .dds-field__main`),
    }), pick(1));
    return !r.open && r.focus || JSON.stringify(r);
  });

  await t('пустой поиск — «Категория не найдена. Проверьте название.»', async () => {
    await p.click(`${pick(1)} .dds-field__main`);
    await p.keyboard.type('zzzz');
    const text = await p.$eval(`${pick(1)} .dds-pop__empty`, (n) => n.textContent);
    await p.keyboard.press('Escape');
    return text === 'Категория не найдена. Проверьте название.' || text;
  });

  await t('тип строки — по flow выбранной категории (доходная → «Доход»)', async () => {
    await p.click(`${pick(1)} .dds-field__main`);
    await p.keyboard.type('Выкупы');
    await p.keyboard.press('Enter');
    const label = await p.$eval(`${row(1)} [data-operation-flow-label]`, (n) => n.textContent);
    return label === 'Доход' || label;
  });

  await t('«Добавить операцию» — в новой строке рабочий виджет', async () => {
    await p.click('.add_item_link');
    await p.waitForFunction(() => {
      const rows = document.querySelectorAll('[data-operation-row]');
      return rows[rows.length - 1].querySelector('.vf-docform-op__cell--category [data-dds-picker].is-ready .dds-field__main');
    }, { timeout: 3000 });
    const last = '[data-operation-row]:last-child';
    await p.click(`${last} .vf-docform-op__cell--category .dds-field__main`);
    await p.keyboard.type('Аренда');
    await p.keyboard.press('Enter');
    const r = await p.$eval(last, (n) => ({
      value: n.querySelector('.vf-docform-op__cell--category .dds-field__value').textContent,
      empty: n.querySelector('.vf-docform-op__cell--category .dds-field__value').classList.contains('is-empty'),
      selected: n.querySelector('[data-operation-category]').selectedOptions[0].textContent.trim(),
    }));
    // строку убираем: сохраняем ниже без неё
    await p.click(`${last} .remove-item`);
    return r.value === 'Аренда' && !r.empty && r.selected.endsWith('Аренда') || JSON.stringify(r);
  });

  await t('сохранение: выбранная категория записана', async () => {
    const id = await p.$eval(`${row(0)} [data-operation-category]`, (s) => s.value);
    await Promise.all([p.waitForNavigation(), p.click('[data-document-save]')]);
    await p.goto(`http://localhost${EDIT}`, { waitUntil: 'networkidle0' });
    const saved = await p.$eval(`${row(0)} [data-operation-category]`, (s) => s.value);
    const name = await p.$eval(`${pick(0)} .dds-field__value`, (n) => n.textContent);
    return saved === id && name === 'Электроэнергия цеха' || JSON.stringify({ id, saved, name });
  });

  await t('ошибок JS нет', async () => errs.length === 0 || errs.join(' | '));

  console.log(`\n${passed} passed, ${failed} failed`);
  await browser.close();
  process.exit(failed ? 1 : 0);
})();
