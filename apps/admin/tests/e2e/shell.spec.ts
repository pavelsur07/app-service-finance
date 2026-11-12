import { expect, test } from '@playwright/test';

test('public routes, keyboard, styles, history and network isolation', async ({
  page,
  baseURL,
}) => {
  if (!baseURL) throw new Error('Missing test baseURL');
  const origin = new URL(baseURL).origin;
  const errors: string[] = [];
  const forbidden: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (
      url.origin !== origin ||
      ['fetch', 'xhr'].includes(request.resourceType())
    ) {
      forbidden.push(request.url());
      await route.abort();
    } else await route.continue();
  });
  await expect
    .poll(
      async () => {
        try {
          return (await page.request.get('/')).status();
        } catch {
          return 0;
        }
      },
      { timeout: 30_000 },
    )
    .toBe(200);
  await page.goto('/about');
  await expect(
    page.getByRole('heading', { name: 'О приложении' }),
  ).toBeVisible();
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('body')).toHaveCSS('margin', '0px');
  await expect(page.locator('.card').first()).toHaveCSS(
    'border-top-style',
    'solid',
  );
  await page.keyboard.press('Tab');
  await expect(
    page.getByRole('link', { name: 'Главная', exact: true }),
  ).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(
    page.getByRole('link', { name: 'О приложении', exact: true }),
  ).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/about$/);
  await expect(
    page.getByRole('heading', { name: 'О приложении' }),
  ).toBeVisible();
  await page.goto('/missing/nested');
  await page.goBack();
  await expect(page).toHaveURL(`${origin}/about`);
  await page.goto('/about');
  await page.reload();
  await expect(
    page.getByRole('heading', { name: 'О приложении' }),
  ).toBeVisible();
  for (const path of ['/about', '/missing/nested']) {
    await page.goto(path);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
  }
  await expect(page.getByRole('heading', { name: 'Не найдено' })).toBeVisible();

  expect(errors).toEqual([]);
  expect(forbidden).toEqual([]);
});
