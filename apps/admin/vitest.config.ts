import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: {
    alias: {
      react: fileURLToPath(new URL('./node_modules/react', import.meta.url)),
      'react-dom': fileURLToPath(
        new URL('./node_modules/react-dom', import.meta.url),
      ),
      vitest: fileURLToPath(import.meta.resolve('vitest')),
      'msw/node': fileURLToPath(import.meta.resolve('msw/node')),
      msw: fileURLToPath(import.meta.resolve('msw')),
    },
  },
  server: {
    fs: {
      allow: [
        fileURLToPath(new URL('.', import.meta.url)),
        fileURLToPath(new URL('../packages/ds', import.meta.url)),
      ],
    },
  },
  test: {
    environment: 'node',
    include: [
      'src/**/*.test.{ts,tsx}',
      '../packages/ds/src/**/*.test.{ts,tsx}',
    ],
  },
});
