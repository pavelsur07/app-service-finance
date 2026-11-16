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
  await expect(
    page.getByRole('radio', { name: 'Первая компания' }),
  ).toBeChecked();
  await page
    .getByRole('navigation', { name: 'Пагинация' })
    .getByRole('button', { name: 'Страница 2', exact: true })
    .click();
  await expect(
    page.getByRole('navigation', { name: 'Пагинация' }),
  ).toContainText('2–2');
  await page.getByRole('radio', { name: 'Вторая компания' }).check();
  await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
  await expect(
    page.getByRole('radio', { name: 'Первая компания' }),
  ).not.toBeChecked();
  await page
    .getByRole('navigation', { name: 'Пагинация' })
    .getByRole('button', { name: 'Страница 2', exact: true })
    .click();
  await expect(
    page.getByRole('navigation', { name: 'Пагинация' }),
  ).toContainText('2–2');
  revoked = true;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.getByRole('radio')).toHaveCount(0);
  await expect(
    page.getByText('Нет доступных компаний.', { exact: false }),
  ).toBeVisible();
});

test('login with multiple companies requires Continue and preserves safe returnTo', async ({
  page,
}) => {
  let authenticated = false;
  let selected = { id: 'one', name: 'Первая компания' };
  let selections = 0;
  const session = () => ({
    user: { id: 'u', email: 'person@company.ru' },
    active_company: selected,
    permissions: {},
  });
  await page.route('**/api/account/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/csrf'))
      return route.fulfill({
        json: {
          tokens: { login: 'login-token', company_select: 'select-token' },
        },
      });
    if (path.endsWith('/login')) {
      authenticated = true;
      return route.fulfill({ status: 204 });
    }
    if (!authenticated) return route.fulfill({ status: 401, json: {} });
    if (path.endsWith('/companies'))
      return route.fulfill({
        json: {
          data: [
            { id: 'one', name: 'Первая компания' },
            { id: 'two', name: 'Вторая компания' },
          ],
          meta: { total: 2, pages: 1, page: 1, per_page: 50 },
        },
      });
    if (path.endsWith('/active-company')) {
      selections++;
      selected = { id: 'two', name: 'Вторая компания' };
    }
    return route.fulfill({ json: session() });
  });
  await page.goto('/login?returnTo=%2Fabout');
  await page.getByLabel('Email', { exact: true }).fill('person@company.ru');
  await page.getByLabel('Пароль', { exact: true }).fill('valid-password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(
    page.getByRole('heading', { name: 'Выберите компанию' }),
  ).toBeVisible();
  await expect(page.locator('.ds-auth-card')).toHaveCSS(
    'width',
    test.info().project.name === 'mobile' ? '390px' : '520px',
  );
  await expect(page.locator('.ds-auth-companies')).toHaveCSS(
    'border-top-width',
    '1px',
  );
  await expect(page.locator('.ds-auth-company').first()).toHaveCSS(
    'border-radius',
    '0px',
  );
  await expect(page.locator('.ds-auth-company [role=img]').first()).toHaveCSS(
    'background-color',
    'rgb(238, 240, 245)',
  );
  await page.screenshot({
    path: test
      .info()
      .outputPath(`account-companies-${test.info().project.name}.png`),
    fullPage: true,
    scale: 'css',
  });
  await page.getByRole('radio', { name: 'Вторая компания' }).check();
  expect(selections).toBe(0);
  await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
  await expect(page).toHaveURL(/\/about$/);
  expect(selections).toBe(1);
});
