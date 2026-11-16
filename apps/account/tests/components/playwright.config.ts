import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.',
  projects: [
    { name: 'components', testMatch: 'components.spec.ts' },
    {
      name: 'layout',
      testMatch: 'layout.spec.ts',
      use: { launchOptions: { args: ['--disable-features=OverlayScrollbar'] } },
    },
  ],
  workers: 1,
  retries: 0,
  outputDir: '../../test-results/components-browser',
  use: {
    baseURL: 'http://127.0.0.1:4180',
    viewport: { width: 1440, height: 900 },
    trace: 'retain-on-failure',
  },
  webServer: {
    command:
      'npm exec vite -- preview --config tests/components/vite.config.ts',
    cwd: new URL('../..', import.meta.url).pathname,
    url: 'http://127.0.0.1:4180',
    reuseExistingServer: false,
  },
});
