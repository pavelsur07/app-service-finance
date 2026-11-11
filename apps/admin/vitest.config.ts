import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: {
    alias: {
      vitest: fileURLToPath(import.meta.resolve('vitest')),
      'msw/node': fileURLToPath(import.meta.resolve('msw/node')),
      msw: fileURLToPath(import.meta.resolve('msw')),
    },
  },
  test: { environment: 'node', include: ['src/**/*.test.ts'] },
});
