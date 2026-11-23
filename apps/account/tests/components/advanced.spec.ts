import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
  await page.goto('/?advanced');
});

test('data fixture renders money, all data variants and table totals', async ({
  page,
}) => {
  await expect(
    page.getByRole('heading', {
      name: 'Данные и оверлеи ДС v3.4',
      exact: true,
    }),
  ).toBeVisible();
  await expect(page.getByTestId('money')).toHaveText(
    '−1\u00a0234\u00a0567,89\u00a0₽',
  );
  await expect(page.getByTestId('expense-value')).toHaveCSS(
    'color',
    await page.locator('body').evaluate((node) => getComputedStyle(node).color),
  );
  await expect(page.getByRole('table', { name: 'Операции' })).toBeVisible();
  await expect(page.locator('tfoot')).toContainText('Итого');
  await expect(
    page.getByRole('img', { name: 'Мегамаркет', exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole('img', { name: 'Павел Иванов', exact: true }),
  ).toContainText('ПИ');
});
test('sort control updates aria-sort on th and caller changes order', async ({
  page,
}) => {
  const header = page.getByRole('columnheader', { name: /Сумма/ });
  await expect(header).toHaveAttribute('aria-sort', 'none');
  await header.getByRole('button').click();
  await expect(header).toHaveAttribute('aria-sort', 'ascending');
  await expect(page.locator('tbody tr').first()).toContainText('Вторая');
  await header.getByRole('button').click();
  await expect(header).toHaveAttribute('aria-sort', 'descending');
  await expect(page.locator('tbody tr').first()).toContainText('Первая');
});
test('pagination supports first/last, ellipsis and safe boundaries', async ({
  page,
}) => {
  const nav = page.getByRole('navigation', { name: 'Пагинация' });
  await expect(nav).toContainText('1–10');
  await expect(
    nav.getByRole('button', { name: 'Предыдущая страница' }),
  ).toBeDisabled();
  await nav.getByRole('button', { name: 'Страница 100', exact: true }).click();
  await expect(nav).toContainText('991–1000');
  await expect(
    nav.getByRole('button', { name: 'Следующая страница' }),
  ).toBeDisabled();
  await nav.getByRole('button', { name: 'Страница 1', exact: true }).click();
  await expect(
    nav.getByRole('button', { name: 'Страница 1', exact: true }),
  ).toHaveAttribute('aria-current', 'page');
});
test('Tabs roves arrows/Home/End, skips disabled and associates panels', async ({
  page,
}) => {
  const tabs = page.getByRole('tablist', { name: 'Отчёты' });
  await tabs.getByRole('tab', { name: /Обзор/ }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(tabs.getByRole('tab', { name: 'История' })).toBeFocused();
  await expect(tabs.getByRole('tab', { name: 'История' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  await expect(page.getByRole('tabpanel', { name: 'История' })).toContainText(
    'Содержимое истории',
  );
  await page.keyboard.press('Home');
  await expect(tabs.getByRole('tab', { name: /Обзор/ })).toBeFocused();
  await page.keyboard.press('End');
  await expect(tabs.getByRole('tab', { name: 'История' })).toBeFocused();
});
test('TagsInput adds suggestions/custom values, rejects duplicates and supports two-step Backspace', async ({
  page,
}) => {
  const tags = page.getByRole('combobox', { name: 'Метки', exact: true });
  await tags.fill('оп');
  await page.keyboard.press('Enter');
  await expect(
    page.getByRole('button', { name: 'Удалить Оплата', exact: true }),
  ).toBeVisible();
  await tags.fill('новая');
  await page.keyboard.press('Enter');
  await expect(
    page.getByRole('button', { name: 'Удалить новая', exact: true }),
  ).toBeVisible();
  await tags.fill('НОВАЯ');
  await page.keyboard.press('Enter');
  await expect(
    page.getByRole('button', { name: /Удалить новая/i }),
  ).toHaveCount(1);
  await tags.fill('');
  await page.keyboard.press('Backspace');
  await expect(
    page.getByRole('button', { name: 'Удалить новая', exact: true }),
  ).toBeFocused();
  await page.keyboard.press('Backspace');
  await expect(
    page.getByRole('button', { name: 'Удалить новая', exact: true }),
  ).toHaveCount(0);
  await expect(tags).toBeFocused();
});
test('disabled tags cannot mutate, chip removal is independent, avatar fallback recovers', async ({
  page,
}) => {
  await expect(
    page.getByRole('combobox', { name: 'Заблокированные метки' }),
  ).toBeDisabled();
  await expect(
    page.getByRole('button', { name: 'Удалить Фиксированная' }),
  ).toBeDisabled();
  await page.getByRole('button', { name: 'Фильтр', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Фильтр', exact: true }),
  ).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Удалить Фильтр' }).click();
  await expect(
    page.getByRole('button', { name: 'Фильтр', exact: true }),
  ).toHaveCount(0);
  await expect(page.getByRole('img', { name: 'Ошибка фото' })).toContainText(
    'ОФ',
  );
});
test('Modal has native top layer, initial focus, trap/inert, Escape and restore', async ({
  page,
}) => {
  const trigger = page.getByRole('button', { name: 'Открыть Modal' });
  await trigger.click();
  const dialog = page.getByRole('dialog', { name: 'Диалог' });
  await expect(dialog).toHaveCSS('width', '520px');
  expect(await dialog.evaluate((node) => node.matches(':modal'))).toBe(true);
  await expect(dialog.getByRole('textbox', { name: 'Название' })).toBeFocused();
  await page
    .getByTestId('background')
    .evaluate((node) => (node as HTMLButtonElement).focus());
  await expect(dialog.getByRole('textbox', { name: 'Название' })).toBeFocused();
  await dialog.getByRole('button', { name: 'Последняя кнопка' }).focus();
  await page.keyboard.press('Tab');
  await expect(
    dialog.getByRole('button', { name: 'Закрыть окно' }),
  ).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(
    dialog.getByRole('button', { name: 'Последняя кнопка' }),
  ).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
});
test('Modal S/L sizes and backdrop pointer rules', async ({ page }) => {
  for (const [button, width] of [
    ['Открыть S', '440px'],
    ['Открыть L', '640px'],
  ] as const) {
    await page.getByRole('button', { name: button, exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Размер' });
    await expect(dialog).toHaveCSS('width', width);
    await dialog.locator('header').click({ position: { x: 10, y: 10 } });
    await expect(dialog).toBeVisible();
    await page.mouse.click(5, 5);
    await expect(dialog).toHaveCount(0);
  }
});
test('nested Drawer/Modal close one layer and keep scroll locked until final close', async ({
  page,
}) => {
  const trigger = page.getByRole('button', { name: 'Открыть Drawer' });
  await trigger.click();
  const drawer = page.getByRole('dialog', { name: 'Панель' });
  await expect(drawer).toHaveCSS('width', '680px');
  await expect(drawer).toHaveCSS('height', '900px');
  await drawer.getByRole('button', { name: 'Вложенный Modal' }).click();
  const child = page.getByRole('dialog', { name: 'Вложенный диалог' });
  await expect(child).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(child).toHaveCount(0);
  await expect(drawer).toBeVisible();
  await expect(
    drawer.getByRole('button', { name: 'Вложенный Modal' }),
  ).toBeFocused();
  await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
  await page.keyboard.press('Escape');
  await expect(drawer).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
});
test('background wheel/PageDown are blocked and long Drawer scrolls independently', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Открыть Drawer' }).click();
  const drawer = page.getByRole('dialog', { name: 'Панель' });
  const before = await page.evaluate(() => scrollY);
  await page.mouse.move(10, 200);
  await page.mouse.wheel(0, 700);
  await page.keyboard.press('PageDown');
  expect(await page.evaluate(() => scrollY)).toBe(before);
  const body = drawer.locator('.ds-dialog-body');
  await body.hover();
  await page.mouse.wheel(0, 700);
  await expect
    .poll(() => body.evaluate((node) => node.scrollTop))
    .toBeGreaterThan(0);
  expect(await page.evaluate(() => scrollY)).toBe(before);
  await page.keyboard.press('Escape');
});
test('ConfirmDialog starts on cancel, busy blocks double confirm and dismissal', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Открыть подтверждение' }).click();
  const dialog = page.getByRole('dialog', { name: 'Удалить запись?' });
  await expect(dialog.getByRole('button', { name: 'Отмена' })).toBeFocused();
  await dialog.getByRole('button', { name: 'Удалить', exact: true }).click();
  await expect(dialog).toHaveAttribute('aria-busy', 'true');
  await expect(
    dialog.getByRole('button', { name: 'Удалить', exact: true }),
  ).toBeDisabled();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeVisible();
  await expect(page.getByLabel('Подтверждений')).toHaveText('1');
  await page
    .getByTestId('finish-confirm')
    .evaluate((node) => (node as HTMLButtonElement).click());
  await expect(dialog).toHaveCount(0);
});
test('DropdownMenu keyboard skips disabled, restores focus, Tab/outside close', async ({
  page,
}) => {
  const trigger = page.getByRole('button', { name: 'Действия', exact: true });
  await trigger.focus();
  await page.keyboard.press('ArrowDown');
  const menu = page.getByRole('menu', { name: 'Действия' });
  await expect(menu.getByRole('menuitem', { name: 'Открыть' })).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Удалить' })).toBeFocused();
  await page.keyboard.press('Home');
  await expect(menu.getByRole('menuitem', { name: 'Открыть' })).toBeFocused();
  await page.keyboard.press('End');
  await expect(menu.getByRole('menuitem', { name: 'Удалить' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(menu).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await trigger.click();
  await page.getByRole('button', { name: 'После меню', exact: true }).click();
  await expect(menu).toHaveCount(0);
  await trigger.click();
  await page.keyboard.press('Tab');
  await expect(menu).toHaveCount(0);
  await expect(
    page.getByRole('button', { name: 'После меню', exact: true }),
  ).toBeFocused();
});
test('menu inside dialog Escape closes menu only; menu remains above clipped modal body', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Открыть Modal' }).click();
  const dialog = page.getByRole('dialog', { name: 'Диалог' });
  await dialog.getByRole('button', { name: 'Внутреннее меню' }).click();
  const menu = page.getByRole('menu', { name: 'Внутреннее меню' });
  await expect(menu).toBeVisible();
  expect(await menu.evaluate((node) => node.matches(':popover-open'))).toBe(
    true,
  );
  await page.keyboard.press('Escape');
  await expect(menu).toHaveCount(0);
  await expect(dialog).toBeVisible();
  await expect(
    dialog.getByRole('button', { name: 'Внутреннее меню' }),
  ).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
});
test('EntityPicker searches, skips disabled, commits draft only on Apply and restores focus', async ({
  page,
}) => {
  const trigger = page.getByRole('button', { name: 'Выбрать компанию' });
  await trigger.click();
  const dialog = page.getByRole('dialog', { name: 'Компании' });
  const search = dialog.getByRole('combobox', { name: 'Поиск' });
  await expect(search).toBeFocused();
  await search.fill('вторая');
  await page.keyboard.press('Enter');
  await expect(
    dialog.getByRole('option', { name: /Вторая компания/ }),
  ).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByLabel('Компания выбрана')).toHaveText('one');
  await dialog.getByRole('button', { name: 'Отмена' }).click();
  await expect(page.getByLabel('Компания выбрана')).toHaveText('one');
  await trigger.click();
  await search.fill('вторая');
  await page.keyboard.press('Enter');
  await dialog.getByRole('button', { name: 'Выбрать', exact: true }).click();
  await expect(page.getByLabel('Компания выбрана')).toHaveText('two');
  await expect(trigger).toBeFocused();
  await trigger.click();
  await search.fill('недоступная');
  await expect(dialog.getByRole('option')).toBeDisabled();
  await dialog.getByRole('button', { name: 'Отмена' }).click();
});
test('TreePicker keyboard expands groups, selects leaves, preserves search ancestors and draft cancel', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Выбрать статью' }).click();
  const dialog = page.getByRole('dialog', { name: 'Статьи' });
  const search = dialog.getByRole('textbox', { name: 'Поиск' });
  await search.press('ArrowDown');
  const group = dialog.getByRole('treeitem', { name: 'Расходы', exact: true });
  await expect(group).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(group).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByLabel('Статья выбрана')).toHaveText('none');
  await page.keyboard.press('ArrowRight');
  await expect(
    dialog.getByRole('treeitem', { name: 'Аренда', exact: true }),
  ).toBeFocused();
  await page.keyboard.press('Space');
  await expect(
    dialog.getByRole('treeitem', { name: 'Аренда', exact: true }),
  ).toHaveAttribute('aria-selected', 'true');
  await dialog.getByRole('button', { name: 'Отмена' }).click();
  await expect(page.getByLabel('Статья выбрана')).toHaveText('none');
  await page.getByRole('button', { name: 'Выбрать статью' }).click();
  await search.fill('зарплата');
  await expect(group).toBeVisible();
  await expect(
    dialog.getByRole('treeitem', { name: 'Зарплата', exact: true }),
  ).toBeVisible();
  await search.press('ArrowDown');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await dialog.getByRole('button', { name: 'Выбрать', exact: true }).click();
  await expect(page.getByLabel('Статья выбрана')).toHaveText('payroll');
});
test('TreePicker disabled leaf cannot be committed and query clear restores expansion', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Выбрать статью' }).click();
  const dialog = page.getByRole('dialog', { name: 'Статьи' });
  const search = dialog.getByRole('textbox', { name: 'Поиск' });
  await search.fill('закрытая');
  await expect(
    dialog.getByRole('treeitem', { name: 'Закрытая', exact: true }),
  ).toHaveAttribute('aria-disabled', 'true');
  await expect(
    dialog.getByRole('button', { name: 'Выбрать', exact: true }),
  ).toBeDisabled();
  await search.fill('');
  await expect(
    dialog.getByRole('treeitem', { name: 'Расходы', exact: true }),
  ).toHaveAttribute('aria-expanded', 'false');
});
test('1440 screenshots data/modal/drawer/pickers and small viewport remains usable', async ({
  page,
}, info) => {
  await page.screenshot({
    path: info.outputPath('data-1440.png'),
    fullPage: true,
  });
  for (const [button, title] of [
    ['Открыть Modal', 'Диалог'],
    ['Открыть Drawer', 'Панель'],
    ['Выбрать компанию', 'Компании'],
    ['Выбрать статью', 'Статьи'],
  ] as const) {
    await page.getByRole('button', { name: button, exact: true }).click();
    await expect(page.getByRole('dialog', { name: title })).toBeVisible();
    await page.screenshot({
      path: info.outputPath(`${title}-1440.png`),
      fullPage: true,
    });
    await page.keyboard.press('Escape');
  }
  await page.setViewportSize({ width: 375, height: 812 });
  await page
    .getByRole('button', { name: 'Открыть Modal', exact: true })
    .click();
  const dialog = page.getByRole('dialog', { name: 'Диалог' });
  expect((await dialog.boundingBox())?.width).toBeLessThanOrEqual(335);
  await page.keyboard.press('Escape');
});

test('native modal locks the actual AppShell main/nav scroll owners without width shift', async ({
  page,
}) => {
  await page.goto('/?layout');
  await page.getByRole('button', { name: 'Длинная страница' }).click();
  const main = page.locator('main.ds-workspace'),
    nav = page.locator('.ds-sidebar-nav');
  await nav.evaluate((node) => (node.scrollTop = 100));
  const before = {
    main: await main.evaluate((node) => node.scrollTop),
    nav: await nav.evaluate((node) => node.scrollTop),
    width: await main.evaluate((node) => node.clientWidth),
  };
  await page.getByRole('button', { name: 'Открыть окно в каркасе' }).click();
  await expect(
    page.getByRole('dialog', { name: 'Окно каркаса' }),
  ).toBeVisible();
  await page.mouse.move(1200, 700);
  await page.mouse.wheel(0, 700);
  await page.keyboard.press('PageDown');
  await page.mouse.move(120, 600);
  await page.mouse.wheel(0, 500);
  await expect
    .poll(() => main.evaluate((node) => node.scrollTop))
    .toBe(before.main);
  await expect
    .poll(() => nav.evaluate((node) => node.scrollTop))
    .toBe(before.nav);
  expect(await main.evaluate((node) => node.clientWidth)).toBe(before.width);
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(
    page.getByRole('button', { name: 'Открыть окно в каркасе' }),
  ).toBeFocused();
});
test('menu Enter/Space activates a single enabled action; fully disabled menu can escape', async ({
  page,
}) => {
  const trigger = page.getByRole('button', { name: 'Действия', exact: true });
  await trigger.focus();
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Выбор меню', { exact: true })).toHaveText(
    'delete',
  );
  await expect(page.getByRole('menu')).toHaveCount(0);
  await trigger.click();
  await page.keyboard.press('Space');
  await expect(page.getByLabel('Выбор меню', { exact: true })).toHaveText(
    'open',
  );
  await page.getByRole('button', { name: 'Недоступное меню' }).click();
  await expect(
    page.getByRole('menu', { name: 'Недоступное меню' }),
  ).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(
    page.getByRole('button', { name: 'Недоступное меню' }),
  ).toBeFocused();
});
test('nested unmount restores outer trigger and original scroll styles; modal closes exactly once', async ({
  page,
}) => {
  const trigger = page.getByRole('button', { name: 'Открыть Drawer' });
  await page.evaluate(() => {
    document.body.style.overflow = 'clip';
    document.documentElement.style.scrollbarGutter = 'auto';
  });
  await trigger.click();
  await page.getByRole('button', { name: 'Вложенный Modal' }).click();
  await expect(
    page.getByRole('dialog', { name: 'Вложенный диалог' }),
  ).toBeVisible();
  await page
    .getByTestId('unmount-drawer')
    .evaluate((node) => (node as HTMLButtonElement).click());
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await expect(page.locator('body')).toHaveCSS('overflow', 'clip');
  expect(
    await page.evaluate(() => document.documentElement.style.scrollbarGutter),
  ).toBe('auto');
  await page.getByRole('button', { name: 'Открыть Modal' }).click();
  await page.keyboard.press('Escape');
  await expect(page.getByLabel('Закрытий Modal')).toHaveText('1');
});
test('IME Enter never commits a partial tag', async ({ page }) => {
  const input = page.getByRole('combobox', { name: 'Метки', exact: true });
  await input.fill('композиция');
  await input.dispatchEvent('keydown', { key: 'Enter', isComposing: true });
  await expect(
    page.getByRole('button', { name: 'Удалить композиция' }),
  ).toHaveCount(0);
  await expect(input).toHaveValue('композиция');
});

test('data text/control contrast and all Tabler icons meet owner constraints', async ({
  page,
}) => {
  const audit = await page.evaluate(() => {
    function lum(value: string) {
      const rgb =
        value
          .match(/[\d.]+/g)
          ?.slice(0, 3)
          .map(Number) ?? [];
      return rgb.reduce((sum, channel, index) => {
        const n = channel / 255;
        return (
          sum +
          (n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4) *
            ([0.2126, 0.7152, 0.0722][index] ?? 0)
        );
      }, 0);
    }
    function ratio(a: string, b: string) {
      const x = lum(a),
        y = lum(b);
      return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
    }
    function background(node: Element): string {
      let current: Element | null = node;
      while (current) {
        const bg = getComputedStyle(current).backgroundColor;
        if (bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') return bg;
        current = current.parentElement;
      }
      throw Error('No opaque surface');
    }
    const text = [...document.querySelectorAll('main *')]
      .filter(
        (node) =>
          node.namespaceURI === 'http://www.w3.org/1999/xhtml' &&
          node.getClientRects().length > 0 &&
          !node.closest(
            '.sr-only,:disabled,[aria-disabled="true"],[data-disabled="true"]',
          ) &&
          [...node.childNodes].some(
            (child) =>
              child.nodeType === Node.TEXT_NODE && child.textContent?.trim(),
          ),
      )
      .map((node) => ({
        text: [...node.childNodes]
          .filter((child) => child.nodeType === Node.TEXT_NODE)
          .map((child) => child.textContent)
          .join('')
          .trim(),
        ratio: ratio(getComputedStyle(node).color, background(node)),
      }));
    const borders = [
      ...document.querySelectorAll(
        '.ds-page-button:not(:disabled),.ds-tags:not([data-disabled="true"])',
      ),
    ].map((node) =>
      Math.max(
        ratio(getComputedStyle(node).borderTopColor, background(node)),
        ratio(
          getComputedStyle(node).borderTopColor,
          background(node.parentElement ?? node),
        ),
      ),
    );
    const icons = [...document.querySelectorAll('svg')].map((node) => ({
      width: node.getAttribute('width'),
      stroke: node.getAttribute('stroke-width'),
    }));
    return { text, borders, icons };
  });
  expect(audit.text.length).toBeGreaterThan(20);
  expect(audit.text.filter((item) => item.ratio < 4.5)).toEqual([]);
  for (const ratio of audit.borders) expect(ratio).toBeGreaterThanOrEqual(3);
  for (const icon of audit.icons) {
    expect(['16', '18', '20']).toContain(icon.width);
    expect(icon.stroke).toBe('2');
  }
});

test('Escape dismisses tag suggestions before the containing modal', async ({
  page,
}) => {
  await page
    .getByRole('button', { name: 'Открыть Modal', exact: true })
    .click();
  const dialog = page.getByRole('dialog', { name: 'Диалог' });
  await dialog
    .getByRole('combobox', { name: 'Метки в окне', exact: true })
    .focus();
  await expect(dialog.getByRole('listbox')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog.getByRole('listbox')).toHaveCount(0);
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
});

test('dialog-scoped notifications stay above the panel, at viewport edge and inside focus boundary', async ({
  page,
}) => {
  await page
    .getByRole('button', { name: 'Открыть Modal', exact: true })
    .click();
  const dialog = page.getByRole('dialog', { name: 'Диалог' });
  await dialog
    .getByRole('button', { name: 'Показать уведомление окна' })
    .click();
  const toast = dialog.getByRole('region', { name: 'Уведомления' });
  await expect(toast).toBeVisible();
  const box = await toast.boundingBox();
  expect(box?.y).toBe(24);
  expect((box?.x ?? 0) + (box?.width ?? 0)).toBe(1416);
  const close = toast.getByRole('button', { name: 'Закрыть уведомление' });
  await close.focus();
  await page.keyboard.press('Enter');
  await expect(toast).toHaveCount(0);
  await expect(
    dialog.getByRole('button', { name: 'Показать уведомление окна' }),
  ).toBeFocused();
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
});

test('opening both nested layers in one React commit keeps the child on top', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Открыть вложенные сразу' }).click();
  const child = page.getByTestId('nested-dialog');
  await expect
    .poll(() => child.evaluate((node) => node.contains(document.activeElement)))
    .toBe(true);
  await page.keyboard.press('Escape');
  await expect(child).toHaveCount(0);
  await expect(page.getByRole('dialog', { name: 'Панель' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(
    page.getByRole('button', { name: 'Открыть вложенные сразу' }),
  ).toBeFocused();
});
test('typing after Escape resumes tag suggestions without blur/refocus', async ({
  page,
}) => {
  const input = page.getByRole('combobox', { name: 'Метки', exact: true });
  await input.focus();
  await expect(
    page.getByRole('listbox', { name: 'Метки: варианты', exact: true }),
  ).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(
    page.getByRole('listbox', { name: 'Метки: варианты', exact: true }),
  ).toHaveCount(0);
  await input.fill('оп');
  await expect(
    page.getByRole('listbox', { name: 'Метки: варианты', exact: true }),
  ).toBeVisible();
});

test('required TagsInput validates committed tags and FormData excludes unfinished text', async ({
  page,
}) => {
  const form = page.getByTestId('tags-form'),
    input = page.getByRole('combobox', {
      name: 'Обязательные метки',
      exact: true,
    });
  expect(
    await form.evaluate((node) => (node as HTMLFormElement).checkValidity()),
  ).toBe(false);
  await input.fill('Незавершённая');
  expect(
    await form.evaluate((node) => (node as HTMLFormElement).checkValidity()),
  ).toBe(false);
  expect(
    await form.evaluate((node) =>
      new FormData(node as HTMLFormElement).getAll('tags'),
    ),
  ).toEqual([]);
  await page.keyboard.press('Enter');
  await expect(
    page.getByRole('button', { name: 'Удалить Незавершённая', exact: true }),
  ).toBeVisible();
  expect(
    await form.evaluate((node) => (node as HTMLFormElement).checkValidity()),
  ).toBe(true);
  expect(
    await form.evaluate((node) =>
      new FormData(node as HTMLFormElement).getAll('tags'),
    ),
  ).toEqual(['Незавершённая']);
  await input.fill('Черновик');
  expect(
    await form.evaluate((node) =>
      new FormData(node as HTMLFormElement).getAll('tags'),
    ),
  ).toEqual(['Незавершённая']);
  await page
    .getByRole('button', { name: 'Удалить Незавершённая', exact: true })
    .click();
  expect(
    await form.evaluate((node) => (node as HTMLFormElement).checkValidity()),
  ).toBe(false);
});
