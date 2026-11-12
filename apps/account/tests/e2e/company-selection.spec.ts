import { expect, test } from '@playwright/test';

test('company pagination, switching and revoked access on focus', async ({
  page,
}) => {
  let selected = { id: 'one', name: 'Первая компания' };
  let revoked = false;
  await page.route('**/api/account/v1/**', (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/csrf'))
      return route.fulfill({ json: { tokens: { company_select: 'token' } } });
    if (url.pathname.endsWith('/companies')) {
      const page = Number(url.searchParams.get('page'));
      if (revoked)
        return route.fulfill({
          status: page > 1 ? 422 : 200,
          json:
            page > 1
              ? {
                  error: {
                    code: 'invalid_page',
                    message: 'Страница недоступна',
                  },
                }
              : {
                  data: [],
                  meta: { page: 1, pages: 1, per_page: 50, total: 0 },
                },
        });
      return route.fulfill({
        json: {
          data: [
            {
              id: page === 1 ? 'one' : 'two',
              name: page === 1 ? 'Первая компания' : 'Вторая компания',
            },
          ],
          meta: { page, pages: 2, per_page: 1, total: 2 },
        },
      });
    }
    if (url.pathname.endsWith('/active-company'))
      selected = { id: 'two', name: 'Вторая компания' };
    return route.fulfill({
      json: {
        user: { id: 'user', email: 'test@example.test' },
        active_company: revoked ? null : selected,
        permissions: {},
      },
    });
  });
  await page.goto('/');
  await expect(page.getByText('Текущая компания:')).toContainText(
    'Первая компания',
  );
  await page.getByRole('button', { name: 'Следующая', exact: true }).click();
  await expect(page.getByText('Страница 2 из 2')).toBeVisible();
  await page.getByLabel('Компания', { exact: true }).selectOption('two');
  await expect(page.getByText('Текущая компания:')).toContainText(
    'Вторая компания',
  );
  await page.getByRole('button', { name: 'Следующая', exact: true }).click();
  await expect(page.getByText('Страница 2 из 2')).toBeVisible();
  revoked = true;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.getByText('Текущая компания:')).toContainText('Не выбрана');
  await expect(page.getByText('Текущая компания:')).not.toContainText(
    'Вторая компания',
  );
  await expect(
    page.getByText('Нет доступных компаний.', { exact: false }),
  ).toBeVisible();
});
