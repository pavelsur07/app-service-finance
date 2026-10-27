// Браузерный тест виджета. Запуск: python3 -m http.server 8765 (в этой папке) и `node dds-tree-picker.e2e.js` (нужен playwright, Chromium).
// Путь скриншота ниже замени на свой или удали строку page.screenshot.
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 700, height: 800 } });
  const errs = []; p.on('pageerror', (e) => errs.push(e.message)); p.on('console', (m) => m.type()==='error' && errs.push(m.text()));
  await p.goto('http://localhost:8765/demo.html'); await p.waitForSelector('.dds-field');
  const t = async (name, fn) => { try { const r = await fn(); console.log(r === true ? 'OK  ' : 'FAIL', name, r === true ? '' : r); } catch (e) { console.log('FAIL', name, e.message); } };
  await t('initial label', async () => (await p.textContent('.dds-field__value')) === 'Комиссия банка' || await p.textContent('.dds-field__value'));
  await t('initial path', async () => (await p.textContent('.dds-field__path')) === 'Выбытие' || await p.textContent('.dds-field__path'));
  await p.click('.dds-field__main');
  await t('popover open + input focused', async () => (await p.evaluate(() => document.activeElement.className)).includes('dds-pop__input'));
  await t('inflow groups hidden for out', async () => !(await p.locator('.dds-tree__group', { hasText: 'Поступления' }).count()) || 'Поступления visible');
  await t('group click does not select', async () => { await p.click('.dds-tree__group[data-d="1"]'); return (await p.inputValue('select')) === '3' || 'changed'; });
  await p.fill('.dds-pop__input', 'свар');
  await t('search shows leaf with hit', async () => (await p.textContent('.dds-tree__hit')) === 'свар'.replace('с','С') || await p.textContent('.dds-tree__hit'));
  await p.screenshot({ path: 'pick-search.png' });
  await p.keyboard.press('Enter');
  await t('Enter picks leaf', async () => (await p.inputValue('select')) === '5' || await p.inputValue('select'));
  await t('popover closed', async () => (await p.locator('.dds-pop').isHidden()) || 'still open');
  await t('path nested 4 levels', async () => (await p.textContent('.dds-field__path')) === 'Выбытие › Заработная плата › Производство › Цех №1' || await p.textContent('.dds-field__path'));
  await p.check('input[value=inflow]');
  await t('direction change resets category', async () => (await p.inputValue('select')) === '' || await p.inputValue('select'));
  await t('reset note shown', async () => /не подходит для притока/.test(await p.textContent('.dds-field__note')) || await p.textContent('.dds-field__note'));
  await p.click('.dds-field__main');
  await t('inflow tree has Поступления, not Выбытие', async () => (await p.locator('.dds-tree__group', { hasText: 'Поступления' }).count()) === 1 && (await p.locator('.dds-tree__group', { hasText: 'Выбытие' }).count()) === 0 || 'bad');
  await p.keyboard.press('ArrowDown'); await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter');
  await t('keyboard select', async () => (await p.inputValue('select')) === '2' || await p.inputValue('select'));
  await p.click('.dds-field__main'); await p.keyboard.press('Escape');
  await t('Esc closes', async () => (await p.locator('.dds-pop').isHidden()) || 'open');
  await p.click('.dds-field__main'); await p.click('text=Показать все');
  await t('show all reveals outflow', async () => (await p.locator('.dds-tree__group', { hasText: 'Выбытие' }).count()) === 1 || 'no');
  await p.mouse.click(650, 700);
  await t('outside click closes', async () => (await p.locator('.dds-pop').isHidden()) || 'open');
  await t('no console errors', async () => errs.length === 0 || errs.join('|'));
  await b.close();
})();
