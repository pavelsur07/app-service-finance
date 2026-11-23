import { expect, test, type Page } from '@playwright/test';

const realm = 'admin';
const session = {
  user: { id: 'user', email: 'known@company.ru' },
  active_company: null,
  permissions: {},
  access: { admin: true },
};
async function guest(page: Page) {
  await page.route(`**/api/${realm}/v1/**`, (route) =>
    route.fulfill({
      status: 401,
      json: { error: { code: 'unauthenticated', message: 'Login' } },
    }),
  );
}
function contrast(first: string, second: string) {
  const light = (color: string) => {
    const values = (color.match(/\d+(?:\.\d+)?/g) ?? [])
      .slice(0, 3)
      .map((value) => {
        const part = Number(value) / 255;
        return part <= 0.04045 ? part / 12.92 : ((part + 0.055) / 1.055) ** 2.4;
      });
    return (
      0.2126 * (values[0] ?? 0) +
      0.7152 * (values[1] ?? 0) +
      0.0722 * (values[2] ?? 0)
    );
  };
  const a = light(first),
    b = light(second);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

test('v3.4 login geometry, local logo, footer, cookie persistence, contrast and focus', async ({
  page,
  context,
}, info) => {
  const mobile = info.project.name === 'mobile';
  const width = mobile ? 390 : 1440;
  await page.setViewportSize({ width, height: mobile ? 844 : 900 });
  await guest(page);
  await page.goto('/login');
  await page.evaluate(() => document.fonts.ready);
  await expect(
    page.getByRole('heading', { name: 'Вход для сотрудников', exact: true }),
  ).toBeVisible();
  await expect(page.getByRole('complementary')).toHaveCount(0);
  const card = page.locator('.ds-auth-card');
  await expect(card).toHaveCSS('width', mobile ? '390px' : '440px');
  await expect(card).toHaveCSS('border-radius', mobile ? '0px' : '8px');
  await expect(card).toHaveCSS(
    'box-shadow',
    mobile
      ? 'none'
      : 'rgba(15, 23, 42, 0.04) 0px 1px 2px 0px, rgba(15, 23, 42, 0.04) 0px 8px 24px 0px',
  );
  await expect(page.locator('h1')).toHaveCSS('font-size', '28px');
  const logo = page.getByRole('link', { name: 'Ваш Финдир — на главную' });
  await expect(logo.locator('img')).toHaveCSS('height', '32px');
  expect(
    (await page.request.get('/brand/logo-full.svg')).headers()['content-type'],
  ).toContain('image/svg+xml');
  await expect(page.getByLabel('Email', { exact: true })).toHaveCSS(
    'height',
    '40px',
  );
  await expect(page.getByLabel('Email', { exact: true })).toHaveAttribute(
    'autocomplete',
    'username',
  );
  await expect(page.getByLabel('Пароль', { exact: true })).toHaveAttribute(
    'autocomplete',
    'current-password',
  );
  await expect(page.getByRole('button', { name: 'Показать пароль' })).toHaveCSS(
    'width',
    '32px',
  );
  await expect(
    page.getByRole('button', { name: 'Войти', exact: true }),
  ).toHaveCSS('height', '44px');
  const cookie = page.getByRole('region', { name: 'Файлы cookie' });
  await expect(cookie).toBeVisible();
  await expect(cookie).toHaveCSS('padding-top', '12px');
  await expect(cookie).toHaveCSS(
    'box-shadow',
    'rgba(15, 23, 42, 0.06) 0px -4px 16px 0px',
  );
  await expect(cookie.getByRole('button').first()).toHaveCSS('height', '32px');
  await expect(page.locator('footer')).toContainText(
    'Персональные данные граждан РФ',
  );
  expect(
    await page
      .locator(
        'a[href="#"], a[href^="/legal/"], a[href*="register"], input[name="remember"]',
      )
      .count(),
  ).toBe(0);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  for (const selector of [
    '.ds-auth-title',
    '.ds-auth-subtitle',
    '.form-label',
    '.ds-auth-legal',
    '.ds-auth-note',
    '.ds-auth-copyright',
    '.ds-auth-documents span',
    '.ds-auth-cookie p',
    '.ds-auth-cookie button',
    'button[type="submit"]',
  ]) {
    const ratios = await page.locator(selector).evaluateAll((nodes) =>
      nodes.map((node) => {
        let parent: Element | null = node;
        let background = 'rgb(255, 255, 255)';
        while (parent) {
          const color = getComputedStyle(parent).backgroundColor;
          if (color !== 'rgba(0, 0, 0, 0)' && color !== 'transparent') {
            background = color;
            break;
          }
          parent = parent.parentElement;
        }
        return { text: getComputedStyle(node).color, background };
      }),
    );
    for (const colors of ratios)
      expect(
        contrast(colors.text, colors.background),
        selector,
      ).toBeGreaterThanOrEqual(4.5);
  }
  const colors = await page
    .getByLabel('Email', { exact: true })
    .evaluate((node) => ({
      border: getComputedStyle(node).borderTopColor,
      background: getComputedStyle(node).backgroundColor,
      placeholder: getComputedStyle(node, '::placeholder').color,
    }));
  expect(contrast(colors.border, colors.background)).toBeGreaterThanOrEqual(3);
  expect(
    contrast(colors.placeholder, colors.background),
  ).toBeGreaterThanOrEqual(4.5);
  await page.screenshot({
    path: info.outputPath(`${realm}-login-cookie-${width}.png`),
    fullPage: true,
    scale: 'css',
  });
  for (let index = 0; index < 7; index++) {
    await page.keyboard.press('Tab');
    const outline = await page
      .locator(':focus')
      .evaluate(
        (node) =>
          getComputedStyle(
            node.matches('.ds-group-input')
              ? (node.closest('.ds-input-group') ?? node)
              : node,
          ).outlineWidth,
      );
    expect(outline).toBe('2px');
  }
  await page.evaluate(() =>
    document.addEventListener(
      'vf:cookie-consent',
      (event) => {
        document.body.dataset.consentEvent = (
          event as CustomEvent<{ level: string }>
        ).detail.level;
      },
      { once: true },
    ),
  );
  await cookie.getByRole('button', { name: 'Только необходимые' }).click();
  await expect(cookie).toHaveCount(0);
  expect(await page.locator('body').getAttribute('data-consent-event')).toBe(
    'necessary',
  );
  const saved = (await context.cookies()).find(
    (item) => item.name === 'vf_cookie_consent',
  );
  expect(saved?.value).toBe('necessary');
  expect(saved?.sameSite).toBe('Lax');
  expect((saved?.expires ?? 0) - Date.now() / 1000).toBeGreaterThan(
    179 * 86400,
  );
  await page.screenshot({
    path: info.outputPath(`${realm}-login-default-${width}.png`),
    fullPage: true,
    scale: 'css',
  });
  await page.reload();
  await expect(cookie).toHaveCount(0);
});

test('real prior-auth 401 carries expiry and known email; initial guest and logout do not', async ({
  page,
}) => {
  let authenticated = true;
  await page.route(`**/api/${realm}/v1/**`, (route) => {
    const path = new URL(route.request().url()).pathname;
    if (!authenticated)
      return route.fulfill({
        status: 401,
        json: { error: { code: 'unauthenticated', message: 'Login' } },
      });
    if (path.endsWith('/companies'))
      return route.fulfill({
        json: { data: [], meta: { total: 0, page: 1, pages: 1, per_page: 50 } },
      });
    return route.fulfill({ json: session });
  });
  await page.goto('/');
  await expect(
    page.getByText('known@company.ru', { exact: true }),
  ).toBeVisible();
  authenticated = false;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page).toHaveURL(/reason=expired/);
  await expect(page.getByRole('status')).toContainText(
    'Сессия истекла. Войдите снова — вернём вас на ту же страницу.',
  );
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue(
    'known@company.ru',
  );
  await expect(page.getByLabel('Пароль', { exact: true })).toBeFocused();
  await page.goto('/login');
  await expect(page.getByText('Сессия истекла.', { exact: false })).toHaveCount(
    0,
  );
});

test('client validation and 401 recovery on 390/1440', async ({
  page,
}, info) => {
  await page.setViewportSize({
    width: info.project.name === 'mobile' ? 390 : 1440,
    height: 900,
  });
  await guest(page);
  await page.goto('/login');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page.getByText('Укажите email', { exact: true })).toBeVisible();
  await expect(page.getByText('Введите пароль', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Email', { exact: true })).toBeFocused();
  await page.route(`**/api/${realm}/v1/auth/csrf`, (route) =>
    route.fulfill({ json: { tokens: { login: 'login-token' } } }),
  );
  await page.getByLabel('Email', { exact: true }).fill('name@company.ru');
  await page.getByLabel('Пароль', { exact: true }).fill('invalid');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveText(
    'Неверный email или пароль. Проверьте раскладку и Caps Lock или восстановите пароль.',
  );
  await expect(page.getByLabel('Пароль', { exact: true })).toHaveValue('');
  await expect(page.getByLabel('Пароль', { exact: true })).toBeFocused();
  await page.screenshot({
    path: info.outputPath(`${realm}-login-error-${info.project.name}.png`),
    fullPage: true,
    scale: 'css',
  });
});
