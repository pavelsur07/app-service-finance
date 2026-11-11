import { expect, test } from '@playwright/test';

test.skip(process.env.E2E_MODE !== 'production', 'Real nginx image contract');

test('production fallback, cache policy, assets and private paths', async ({
  page,
}) => {
  await expect
    .poll(
      async () => {
        try {
          return (await page.request.get('/health')).status();
        } catch {
          return 0;
        }
      },
      { timeout: 30_000 },
    )
    .toBe(200);
  const home = await page.request.get('/');
  const html = await home.text();
  expect(home.status()).toBe(200);
  expect(home.headers()['cache-control']).toContain('no-cache');
  const about = await page.request.get('/about');
  expect(await about.text()).toBe(html);
  expect(about.headers()['cache-control']).toContain('no-cache');
  const missingAsset = await page.request.get('/assets/missing.js');
  expect(missingAsset.status()).toBe(404);
  expect((await page.request.get('/.env')).status()).toBe(404);
  expect((await page.request.get('/.git/config')).status()).toBe(404);
  expect((await page.request.get('/assets/missing.js.map')).status()).toBe(404);
  await page.goto('/');
  await expect(
    page.getByRole('heading', { name: 'Клиентский кабинет', exact: true }),
  ).toBeVisible();
  for (const [selector, attribute, contentType] of [
    ['script[src]', 'src', /javascript/],
    ['link[rel="stylesheet"]', 'href', /text\/css/],
  ] as const) {
    const path = await page.locator(selector).first().getAttribute(attribute);
    expect(path).toMatch(/^\/assets\//);
    if (!path) throw new Error('Missing production asset');
    const asset = await page.request.get(path);
    expect(asset.status()).toBe(200);
    expect(asset.headers()['content-type']).toMatch(contentType);
    expect(asset.headers()['cache-control']).toContain('immutable');
  }
});
