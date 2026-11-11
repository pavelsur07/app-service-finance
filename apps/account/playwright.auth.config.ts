import { defineConfig, devices } from '@playwright/test';
const mode = process.env.E2E_MODE ?? 'dev';
const origin =
  mode === 'production'
    ? 'https://account.auth.test'
    : `http://account.auth.test:${mode === 'preview' ? 4174 : 5174}`;
export default defineConfig({
  testDir: './tests/auth',
  workers: 1,
  timeout: 60_000,
  retries: 0,
  use: { baseURL: origin, trace: 'off', ...devices['Desktop Chrome'] },
});
