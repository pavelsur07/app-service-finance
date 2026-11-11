import { defineConfig, devices } from '@playwright/test';

const preview = process.env.E2E_MODE === 'preview';
const host = process.env.E2E_CONTAINER ? 'admin' : '127.0.0.1';
const port = preview ? 4175 : 5175;
export default defineConfig({
  testDir: './tests/e2e',
  workers: 1,
  timeout: 60_000,
  retries: 0,
  use: { baseURL: `http://${host}:${port}`, trace: 'retain-on-failure' },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    {
      name: 'mobile',
      use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' },
    },
  ],
});
