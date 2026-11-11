import { expect, test } from '@playwright/test';

test('anonymous login, invalid credentials and protected reload', async ({
  page,
}) => {
  let authenticated = false;
  await page.route('**/api/account/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/auth/csrf'))
      return route.fulfill({
        json: {
          tokens: {
            login: 'login-token',
            logout: 'logout-token',
            company_select: 'select-token',
          },
        },
      });
    if (path.endsWith('/auth/login')) {
      const body = route.request().postDataJSON() as { password: string };
      if (body.password !== 'valid-password')
        return route.fulfill({
          status: 401,
          json: { error: { code: 'invalid_credentials', message: 'Invalid' } },
        });
      authenticated = true;
      return route.fulfill({ status: 204 });
    }
    if (path.endsWith('/auth/logout')) {
      authenticated = false;
      return route.fulfill({ status: 204 });
    }
    if (!authenticated)
      return route.fulfill({
        status: 401,
        json: { error: { code: 'unauthenticated', message: 'Login' } },
      });
    if (path.endsWith('/companies'))
      return route.fulfill({
        json: { data: [], meta: { total: 0, page: 1, per_page: 50, pages: 1 } },
      });
    return route.fulfill({
      json: {
        user: { id: 'user-1', email: 'person@example.test' },
        active_company: null,
        permissions: {},
        access: { admin: true },
      },
    });
  });
  await page.goto('/');
  await expect(page).toHaveURL(/\/login/);
  await page.getByLabel('Email', { exact: true }).fill('person@example.test');
  await page.getByLabel('Пароль', { exact: true }).fill('incorrect');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText(
    'Неверный email или пароль',
  );
  await page.getByLabel('Пароль', { exact: true }).fill('valid-password');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(
    page.getByText('Раздел находится в разработке', { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText('person@example.test', { exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole('button', { name: 'Выйти', exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Войти', exact: true }),
  ).toBeVisible();
});

test('restoration errors retry without redirect loops', async ({ page }) => {
  let unavailable = true;
  await page.route('**/api/account/v1/**', (route) =>
    route.fulfill({
      status: unavailable ? 500 : 401,
      json: { error: { code: 'unavailable', message: 'Временно недоступно' } },
    }),
  );
  await page.goto('/');
  await expect(page.getByRole('alert')).toContainText('Временно недоступно');
  await expect(page).toHaveURL(/\/$/);
  unavailable = false;
  await page.getByRole('button', { name: 'Повторить', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Войти', exact: true }),
  ).toBeVisible();
});

test('forbidden session offers an action; no redirect loop', async ({
  page,
}) => {
  await page.route('**/api/account/v1/**', (route) =>
    route.fulfill({
      status: 403,
      json: { error: { code: 'forbidden', message: 'Недостаточно прав' } },
    }),
  );
  await page.goto('/');
  await expect(
    page.getByRole('heading', { name: 'Доступ запрещён' }),
  ).toBeVisible();
  await expect(
    page.getByRole('button', { name: 'Повторить', exact: true }),
  ).toBeVisible();
  await expect(page).toHaveURL(/\/$/);
});

test('login is keyboard accessible and prevents duplicate pending submissions', async ({
  page,
}) => {
  let finish: (() => void) | undefined;
  let submissions = 0;
  await page.route('**/api/account/v1/**', async (route) => {
    if (route.request().url().endsWith('/csrf'))
      return route.fulfill({ json: { tokens: { login: 'token' } } });
    if (route.request().url().endsWith('/login')) {
      submissions++;
      await new Promise<void>((resolve) => {
        finish = resolve;
      });
      return route.fulfill({
        status: 429,
        json: { error: { code: 'limited', message: 'Too many' } },
      });
    }
    return route.fulfill({ status: 401, json: {} });
  });
  await page.goto('/login?returnTo=https://evil.test');
  await page.getByLabel('Email', { exact: true }).fill('person@example.test');
  await page.getByLabel('Email', { exact: true }).focus();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Пароль', { exact: true })).toBeFocused();
  await page.keyboard.type('password');
  await page.keyboard.press('Enter');
  await expect(
    page.getByRole('button', { name: 'Войти', exact: true }),
  ).toBeDisabled();
  await expect.poll(() => submissions).toBe(1);
  finish?.();
  await expect(page.getByRole('alert')).toContainText('Слишком много попыток');
  await expect(page.getByLabel('Пароль', { exact: true })).toHaveValue('');
});
