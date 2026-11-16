import { expect, test } from '@playwright/test';

function luminance(color: string): number {
  const channels = color
    .match(/[\d.]+/g)
    ?.slice(0, 3)
    .map(Number);
  if (!channels || channels.length !== 3)
    throw new Error(`Invalid color: ${color}`);
  const linear = channels.map((channel) => {
    const value = channel / 255;
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  return (
    (linear[0] ?? 0) * 0.2126 +
    (linear[1] ?? 0) * 0.7152 +
    (linear[2] ?? 0) * 0.0722
  );
}
function contrast(a: string, b: string): number {
  const first = luminance(a);
  const second = luminance(b);
  return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
}

test('DS light foundation, local font, preflight and focus', async ({
  page,
  baseURL,
}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '1440 design reference');
  await page.setViewportSize({ width: 1440, height: 900 });
  if (!baseURL) throw new Error('Missing baseURL');
  const origin = new URL(baseURL).origin;
  const external: string[] = [];
  page.on('request', (request) => {
    if (new URL(request.url()).origin !== origin) external.push(request.url());
  });
  await page.goto('/about');
  await page.evaluate(() => document.fonts.ready);
  await expect(page.locator('body')).toHaveCSS('font-family', /Manrope/);
  await expect(page.locator('body')).toHaveCSS('font-size', '13px');
  await expect(page.locator('body')).toHaveCSS(
    'font-variant-numeric',
    'tabular-nums',
  );
  await expect(page.locator('html')).toHaveCSS('font-size', '16px');
  await expect(page.locator('body')).toHaveCSS('margin', '0px');
  expect(
    await page.evaluate(() => document.fonts.check('400 13px Manrope')),
  ).toBe(true);
  await page.keyboard.press('Tab');
  await expect(
    page.getByRole('link', { name: 'Перейти к содержимому', exact: true }),
  ).toBeFocused();
  await expect(page.locator(':focus')).toHaveCSS('outline-width', '2px');
  await expect(page.locator(':focus')).toHaveCSS('outline-style', 'solid');
  const background = await page
    .locator('body')
    .evaluate((element) => getComputedStyle(element).backgroundColor);
  const text = await page
    .locator('body')
    .evaluate((element) => getComputedStyle(element).color);
  expect(contrast(background, text)).toBeGreaterThanOrEqual(4.5);
  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(page.locator('body')).toHaveCSS('background-color', background);
  const license = await page.request.get('/fonts/Manrope-OFL.txt');
  expect(license.status()).toBe(200);
  expect(await license.text()).toContain('SIL OPEN FONT LICENSE');
  expect(external).toEqual([]);
  await page.screenshot({
    path: testInfo.outputPath('about-1440.png'),
    fullPage: true,
  });
});

test('DS login controls have token dimensions, contrast and focus', async ({
  page,
}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '1440 design reference');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.route('**/api/**', (route) =>
    route.fulfill({
      status: 401,
      json: { error: { code: 'unauthenticated', message: 'Login' } },
    }),
  );
  await page.goto('/login');
  const input = page.getByLabel('Email', { exact: true });
  await expect(input).toBeVisible();
  await expect(input).toHaveCSS('height', '38px');
  const colors = await input.evaluate((element) => {
    const style = getComputedStyle(element);
    return { border: style.borderTopColor, background: style.backgroundColor };
  });
  expect(contrast(colors.border, colors.background)).toBeGreaterThanOrEqual(3);
  await input.focus();
  await expect(input).toHaveCSS('outline-width', '2px');
  const button = page.getByRole('button', { name: 'Войти', exact: true });
  await expect(button).toHaveCSS('height', '36px');
  await expect(button).toHaveCSS('background-color', 'rgb(30, 42, 68)');
  const buttonColors = await button.evaluate((element) => {
    const style = getComputedStyle(element);
    return { text: style.color, background: style.backgroundColor };
  });
  expect(
    contrast(buttonColors.text, buttonColors.background),
  ).toBeGreaterThanOrEqual(4.5);
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: testInfo.outputPath('login-1440.png'),
    fullPage: true,
  });
});
