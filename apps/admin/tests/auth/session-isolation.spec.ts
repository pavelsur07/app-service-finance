import { expect, test, type APIRequestContext } from '@playwright/test';

const realm: string = 'admin';
const production = process.env.E2E_MODE === 'production';
const prefix = `/api/${realm}/v1`;
const password = 'Local-auth-password-123!';
const email =
  realm === 'account' ? 'auth-owner@example.test' : 'auth-admin@example.test';
const other = realm === 'account' ? 'admin' : 'account';
const otherPort =
  process.env.E2E_MODE === 'preview'
    ? other === 'account'
      ? 4174
      : 4175
    : other === 'account'
      ? 5174
      : 5175;
const otherOrigin = production
  ? `https://${other}.auth.test`
  : `http://${other}.auth.test:${otherPort}`;
const oldOrigin = `${production ? 'https' : 'http'}://app.auth.test`;
async function tokens(
  request: APIRequestContext,
  origin: string,
  contour: string,
) {
  const response = await request.get(`${origin}/api/${contour}/v1/auth/csrf`);
  expect(response.status()).toBe(200);
  return ((await response.json()) as { tokens: Record<string, string> }).tokens;
}

test.beforeEach(async ({ request }) => {
  await expect
    .poll(
      async () => {
        try {
          return (await request.get(`${prefix}/auth/csrf`)).status();
        } catch {
          return 0;
        }
      },
      { timeout: 60_000 },
    )
    .toBe(200);
});

test('real login, company, cookies, sibling and legacy sessions, logout', async ({
  page,
  context,
  baseURL,
}) => {
  if (!baseURL) throw new Error('Missing baseURL');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const unauthenticated = await page.request.get(`${prefix}/session`);
  expect(unauthenticated.status()).toBe(401);
  expect(unauthenticated.headers()['content-type']).toContain(
    'application/json',
  );
  expect(unauthenticated.headers()['cache-control']).toContain('no-store');
  expect(
    unauthenticated.headers()['access-control-allow-origin'],
  ).toBeUndefined();
  await tokens(page.request, baseURL, realm);
  const anonymousCookie = (await context.cookies(baseURL)).find(
    (cookie) => cookie.httpOnly,
  );
  expect(Boolean(anonymousCookie)).toBe(true);
  await page.goto('/login');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Пароль', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page.getByText(email, { exact: true })).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole('button', { name: 'Выйти', exact: true }),
  ).toBeVisible();
  const cookie = (await context.cookies(baseURL)).find((item) => item.httpOnly);
  if (!cookie || !anonymousCookie) throw new Error('Session cookie missing');
  expect(cookie.domain).toBe(`${realm}.auth.test`);
  expect(cookie.path).toBe('/');
  expect(cookie.sameSite).toBe('Lax');
  expect(cookie.secure).toBe(production);
  expect(cookie.value !== anonymousCookie.value).toBe(true);
  expect(
    (await page.request.get(`${otherOrigin}/api/${other}/v1/session`)).status(),
  ).toBe(401);
  if (realm === 'account') {
    const companies = await page.request.get(
      `${prefix}/companies?page=1&perPage=1`,
    );
    const list = (await companies.json()) as {
      data: { id: string; name: string }[];
      meta: { pages: number };
    };
    expect(list.meta.pages).toBe(2);
    const choice = list.data[0];
    if (!choice) throw new Error('Fixture company missing');
    await page.getByLabel('Компания', { exact: true }).selectOption(choice.id);
    await expect(page.getByText('Текущая компания:')).toContainText(
      choice.name,
    );
    await page.reload();
    await expect(page.getByText('Текущая компания:')).toContainText(
      choice.name,
    );
  }
  // The same browser owns all three hosts. A legacy session must remain usable.
  const legacyPage = await page.request.get(`${oldOrigin}/login`);
  expect(legacyPage.status()).toBe(200);
  const html = await legacyPage.text();
  const csrf = html.match(/name="_csrf_token" value="([^"]+)"/)?.[1];
  if (!csrf) throw new Error('Legacy CSRF input missing');
  const legacyLogin = await page.request.post(`${oldOrigin}/login`, {
    form: {
      _username: 'auth-admin@example.test',
      _password: password,
      _csrf_token: csrf,
    },
    maxRedirects: 0,
  });
  expect(legacyLogin.status()).toBe(302);
  expect(
    (await page.request.get(`${oldOrigin}/`, { maxRedirects: 0 })).headers()
      .location,
  ).toMatch(/\/finance$/);
  const siblingTokens = await tokens(page.request, otherOrigin, other);
  expect(
    (
      await page.request.post(`${otherOrigin}/api/${other}/v1/auth/login`, {
        headers: {
          Origin: otherOrigin,
          'X-CSRF-Token': siblingTokens.login ?? '',
        },
        data: { email: 'auth-admin@example.test', password },
      })
    ).status(),
  ).toBe(204);
  expect(
    (await page.request.get(`${otherOrigin}/api/${other}/v1/session`)).status(),
  ).toBe(200);
  const currentTokens = await tokens(page.request, baseURL, realm);
  const hostile = await page.request.post(`${prefix}/auth/logout`, {
    headers: {
      Origin: otherOrigin,
      'X-CSRF-Token': currentTokens.logout ?? '',
    },
  });
  expect(hostile.status()).toBe(403);
  expect(hostile.headers()['access-control-allow-origin']).toBeUndefined();
  expect((await page.request.get(`${prefix}/session`)).status()).toBe(200);
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Войти', exact: true }),
  ).toBeVisible();
  expect((await page.request.get(`${prefix}/session`)).status()).toBe(401);
  expect(
    (await page.request.get(`${otherOrigin}/api/${other}/v1/session`)).status(),
  ).toBe(200);
  expect(
    (await page.request.get(`${oldOrigin}/`, { maxRedirects: 0 })).headers()
      .location,
  ).toMatch(/\/finance$/);
  // Replaying the destroyed server session cannot restore access.
  await context.addCookies([cookie]);
  expect((await page.request.get(`${prefix}/session`)).status()).toBe(401);
  expect(errors).toEqual([]);
});

test('wrong host, reserved paths and sibling CSRF are rejected over HTTP', async ({
  page,
  baseURL,
  browser,
}) => {
  if (!baseURL) throw new Error('Missing baseURL');
  for (const path of [
    `/api/${other}/v1/session`,
    '/api/legacy',
    '/internal/services',
    '/admin/users',
    '/index.php',
  ]) {
    expect((await page.request.get(path, { maxRedirects: 0 })).status()).toBe(
      404,
    );
  }
  expect(
    (
      await page.request.get(`${oldOrigin}${prefix}/session`, {
        maxRedirects: 0,
      })
    ).status(),
  ).toBe(404);
  for (const encoded of [
    '/api/%61ccount/v1/session',
    '/%61pi/admin/v1/session',
    '/api/account%2fv1/session',
  ]) {
    expect(
      (
        await page.request.get(`${oldOrigin}${encoded}`, { maxRedirects: 0 })
      ).status(),
    ).toBe(404);
  }
  const second = await browser.newContext();
  try {
    const foreign = await tokens(second.request, baseURL, realm);
    await tokens(page.request, baseURL, realm);
    const invalid = await page.request.post(`${prefix}/auth/login`, {
      headers: { Origin: baseURL, 'X-CSRF-Token': foreign.login ?? '' },
      data: { email, password },
    });
    expect(invalid.status()).toBe(403);
    expect((await page.request.get(`${prefix}/session`)).status()).toBe(401);
  } finally {
    await second.close();
  }
});
