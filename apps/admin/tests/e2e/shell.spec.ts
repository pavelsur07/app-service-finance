import { expect, test } from '@playwright/test';

test('placeholder routes, keyboard, styles, history and network isolation', async ({
  page,
  baseURL,
}) => {
  const errors: string[] = [];
  const forbidden: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (
      url.origin !== baseURL ||
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
  await page.goto('/');
  await expect(
    page.getByText('Раздел находится в разработке', { exact: true }),
  ).toBeVisible();
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('body')).toHaveCSS('margin', '0px');
  await expect(page.locator('.empty')).toHaveCSS('border-top-style', 'dashed');
  await expect(page.locator('.card')).toHaveCSS('border-top-style', 'solid');
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
  await page.goBack();
  await expect(page).toHaveURL(`${baseURL}/`);
  await page.goto('/about');
  await page.reload();
  await expect(
    page.getByRole('heading', { name: 'О приложении' }),
  ).toBeVisible();
  for (const path of ['/', '/about', '/missing/nested']) {
    await page.goto(path);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
  }
  await expect(page.getByRole('heading', { name: 'Не найдено' })).toBeVisible();
  await page.getByRole('link', { name: 'На главную', exact: true }).click();
  await expect(page).toHaveURL(`${baseURL}/`);
  expect(errors).toEqual([]);
  expect(forbidden).toEqual([]);
});
