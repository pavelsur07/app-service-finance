import { expect, test, type Route } from '@playwright/test';

test('public shell preserves no-bootstrap, sidebar state, active navigation and SPA history', async ({
  page,
}, testInfo) => {
  const desktop = testInfo.project.name === 'desktop';
  if (desktop) await page.setViewportSize({ width: 1440, height: 900 });
  const api: string[] = [];
  let documents = 0;
  page.on('request', (request) => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame())
      documents++;
    if (new URL(request.url()).pathname.startsWith('/api/'))
      api.push(request.url());
  });
  await page.goto('/about');
  await expect(page.locator('header')).toHaveCSS('height', '60px');
  const aside = page.getByRole('complementary', { name: 'Боковая панель' });
  await expect(aside).toHaveCSS('width', desktop ? '264px' : '72px');
  await page.keyboard.press('Tab');
  await expect(
    page.getByRole('link', { name: 'Перейти к содержимому' }),
  ).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('main')).toBeFocused();
  const group = page.getByRole('button', {
    name: 'Администрирование',
    exact: true,
  });
  if (!desktop) await group.click();
  await expect(
    page.getByRole('link', { name: 'О приложении', exact: true }),
  ).toHaveAttribute('aria-current', 'page');
  if (desktop)
    await page.screenshot({
      path: testInfo.outputPath('admin-shell-full-1440.png'),
      fullPage: true,
    });
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  await expect(aside).toHaveCSS('width', '72px');
  if (desktop)
    await page.screenshot({
      path: testInfo.outputPath('admin-shell-rail-1440.png'),
      fullPage: true,
    });
  await page.reload();
  await expect(aside).toHaveCSS('width', '72px');
  await page.goto('/missing/nested');
  await expect(
    page.getByRole('heading', { name: 'Не найдено', exact: true }),
  ).toBeVisible();
  const before = documents;
  await page
    .getByRole('button', { name: 'Администрирование', exact: true })
    .click();
  await page.getByRole('link', { name: 'О приложении', exact: true }).click();
  await expect(page).toHaveURL(/\/about$/);
  expect(documents).toBe(before);
  expect(api).toEqual([]);
});

test('authenticated shell shows the real identity and uses the current logout', async ({
  page,
}) => {
  let authenticated = true,
    logout = 0;
  await page.route('**/api/admin/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/csrf'))
      return route.fulfill({
        json: {
          tokens: {
            login: 'test-login',
            logout: 'test-logout',
            company_select: 'test-company',
          },
        },
      });
    if (path.endsWith('/logout')) {
      authenticated = false;
      logout++;
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
  const aside = page.getByRole('complementary');
  await expect(
    aside.getByText('person@example.test', { exact: true }),
  ).toHaveCount(1);
  await expect(
    page.getByRole('button', { name: 'Выйти', exact: true }),
  ).toHaveCount(1);
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Войти', exact: true }),
  ).toBeVisible();
  expect(logout).toBe(1);
});

test('realm preference does not read the sibling key', async ({
  page,
}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Desktop preference');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/about');
  await page.evaluate(() => {
    localStorage.setItem('vf.admin.sidebar', 'expanded');
    localStorage.setItem('vf.account.sidebar', 'collapsed');
  });
  await page.reload();
  await expect(page.getByRole('complementary')).toHaveCSS('width', '264px');
  await page.getByRole('button', { name: 'Свернуть меню' }).click();
  expect(
    await page.evaluate(() => localStorage.getItem('vf.admin.sidebar')),
  ).toBe('collapsed');
  expect(
    await page.evaluate(() => localStorage.getItem('vf.account.sidebar')),
  ).toBe('collapsed');
});

test('logout remains available while a background session refresh is pending', async ({
  page,
}) => {
  let authenticated = true,
    stall = false;
  const pending: Route[] = [];
  await page.route('**/api/admin/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/csrf'))
      return route.fulfill({ json: { tokens: { logout: 'logout-token' } } });
    if (path.endsWith('/logout')) {
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
        json: { data: [], meta: { page: 1, pages: 1, per_page: 50, total: 0 } },
      });
    if (stall) {
      pending.push(route);
      return;
    }
    return route.fulfill({
      json: {
        user: { id: 'user-1', email: 'person@example.test' },
        active_company: null,
        permissions: {},
        access: { admin: true },
      },
    });
  });
  try {
    await page.goto('/');
    const logout = page.getByRole('button', { name: 'Выйти', exact: true });
    await expect(logout).toBeEnabled();
    stall = true;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await expect.poll(() => pending.length).toBeGreaterThan(0);
    await expect(logout).toBeEnabled();
    await logout.click();
    await expect(
      page.getByRole('button', { name: 'Войти', exact: true }),
    ).toBeVisible();
  } finally {
    await Promise.allSettled(pending.map((route) => route.abort()));
  }
});
