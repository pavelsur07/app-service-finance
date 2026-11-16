import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
  await page.goto('/?layout');
});

test('viewport shell: header60, sidebar264/72, focus follows collapse/expand', async ({
  page,
}) => {
  await expect(page.locator('header')).toHaveCSS('height', '60px');
  const aside = page.getByRole('complementary', { name: 'Боковая панель' });
  await expect(aside).toHaveCSS('width', '264px');
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  await expect(aside).toHaveCSS('width', '72px');
  await expect(
    page.getByRole('navigation', { name: 'Основная навигация' }),
  ).toHaveCSS('scrollbar-gutter', 'auto');
  await expect(
    page.getByRole('navigation', { name: 'Основная навигация' }),
  ).toHaveCSS('scrollbar-width', 'none');
  await expect(
    page.getByRole('button', { name: 'Развернуть меню' }),
  ).toBeFocused();
  await expect(
    page.getByRole('link', { name: 'Обзор', exact: true }),
  ).toBeHidden();
  await page.getByRole('button', { name: 'Развернуть меню' }).click();
  await expect(aside).toHaveCSS('width', '264px');
  await expect(
    page.getByRole('button', { name: 'Свернуть меню' }),
  ).toBeFocused();
});

test('skip link is first and moves focus into main', async ({ page }) => {
  await page.keyboard.press('Tab');
  await expect(
    page.getByRole('link', { name: 'Перейти к содержимому' }),
  ).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('main')).toBeFocused();
});

test('rail group is named and expands sidebar and submenu by keyboard', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  const group = page.getByRole('button', { name: 'Настройки', exact: true });
  await expect(group).toHaveAttribute('aria-expanded', 'false');
  await group.focus();
  await page.keyboard.press('Enter');
  await expect(group).toBeFocused();
  await expect(group).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('complementary')).toHaveCSS('width', '264px');
  await expect(
    page.getByRole('link', { name: 'Параметры уведомлений' }),
  ).toBeVisible();
  const controlled = await group.getAttribute('aria-controls');
  expect(controlled).toBeTruthy();
  expect(
    await page.evaluate(
      (id) => (id ? document.getElementById(id)?.hidden : null),
      controlled,
    ),
  ).toBe(false);
  await page.keyboard.press('Space');
  await expect(group).toHaveAttribute('aria-expanded', 'false');
  await expect(
    page.getByRole('link', { name: 'Параметры уведомлений' }),
  ).toBeHidden();
});

test('preference survives reload and responsive rail does not overwrite desktop state', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  await page.reload();
  await expect(page.getByRole('complementary')).toHaveCSS('width', '72px');
  await page.getByRole('button', { name: 'Развернуть меню' }).click();
  await page.setViewportSize({ width: 1024, height: 900 });
  await expect(page.getByRole('complementary')).toHaveCSS('width', '72px');
  expect(
    await page.evaluate(() => localStorage.getItem('vf.fixture.sidebar')),
  ).toBe('expanded');
  await page.setViewportSize({ width: 1440, height: 900 });
  await expect(page.getByRole('complementary')).toHaveCSS('width', '264px');
  await expect(
    page.getByRole('button', { name: 'Свернуть меню' }),
  ).toBeFocused();
});

test('blocked storage keeps collapse controls usable', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', {
      get: () => {
        throw new Error('Storage blocked');
      },
    });
  });
  await page.reload();
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  await expect(page.getByRole('complementary')).toHaveCSS('width', '72px');
  await page.getByRole('button', { name: 'Развернуть меню' }).click();
  await expect(page.getByRole('complementary')).toHaveCSS('width', '264px');
});

test('ordinary scrollbars do not move content or header; navigation scrolls separately', async ({
  page,
}) => {
  const main = page.getByRole('main');
  const title = page.getByRole('heading', { name: 'Обзор', exact: true });
  const before = await title.boundingBox();
  await expect(main).toHaveCSS('scrollbar-gutter', 'stable');
  expect(
    await main.evaluate(
      (element) => element.getBoundingClientRect().width - element.clientWidth,
    ),
  ).toBeGreaterThan(0);
  await expect(
    page.getByRole('navigation', { name: 'Основная навигация' }),
  ).toHaveCSS('scrollbar-gutter', 'stable');
  await page.getByRole('button', { name: 'Длинная страница' }).click();
  const after = await title.boundingBox();
  expect(after?.x).toBe(before?.x);
  expect(after?.width).toBe(before?.width);
  await main.evaluate((element) => {
    element.scrollTop = 500;
  });
  expect(await main.evaluate((element) => element.scrollTop)).toBe(500);
  expect(await page.evaluate(() => document.documentElement.scrollHeight)).toBe(
    900,
  );
  expect((await page.locator('header').boundingBox())?.y).toBe(0);
  const nav = page.getByRole('navigation', { name: 'Основная навигация' });
  await nav.evaluate((element) => {
    element.scrollTop = 200;
  });
  expect(await nav.evaluate((element) => element.scrollTop)).toBe(200);
  expect(await main.evaluate((element) => element.scrollTop)).toBe(500);
});

test('logout stays accessible in rail and does not submit a form', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await expect(page.getByLabel('Выходов')).toHaveText('1');
});

test('1440 full and rail screenshots, mobile has no document overflow', async ({
  page,
}, testInfo) => {
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: testInfo.outputPath('shell-full-1440.png'),
    fullPage: true,
  });
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  await expect(page.getByRole('complementary')).toHaveCSS('width', '72px');
  await page.screenshot({
    path: testInfo.outputPath('shell-rail-1440.png'),
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  await expect(page.getByRole('complementary')).toHaveCSS('width', '72px');
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
});

test('sidebar and header text contrast meets 4.5:1 on actual surfaces', async ({
  page,
}) => {
  const samples = await page
    .locator(
      'header nav [aria-current="page"], aside nav p, aside nav button, aside nav a:not([hidden]), footer',
    )
    .evaluateAll((elements) => {
      function luminance(color: string) {
        const channels =
          color
            .match(/[\d.]+/g)
            ?.slice(0, 3)
            .map(Number) ?? [];
        return channels.reduce((sum, c, i) => {
          const v = c / 255;
          return (
            sum +
            (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4) *
              ([0.2126, 0.7152, 0.0722][i] ?? 0)
          );
        }, 0);
      }
      return elements
        .filter((element) => element.getClientRects().length > 0)
        .map((element) => {
          const text = getComputedStyle(element).color;
          let parent: Element | null = element;
          let background = '';
          while (parent) {
            background = getComputedStyle(parent).backgroundColor;
            if (background !== 'rgba(0, 0, 0, 0)') break;
            parent = parent.parentElement;
          }
          const a = luminance(text),
            b = luminance(background);
          return {
            label: element.textContent?.trim().slice(0, 40),
            ratio: (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05),
          };
        });
    });
  for (const sample of samples)
    expect(sample.ratio, sample.label).toBeGreaterThanOrEqual(4.5);
});
