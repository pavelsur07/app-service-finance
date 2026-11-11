import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Отдельно от vite.config.js: плагин Symfony тестам не нужен. Только чистые функции, без DOM.
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./assets', import.meta.url)),
            'react': fileURLToPath(new URL('./node_modules/react', import.meta.url)),
            'react-dom': fileURLToPath(new URL('./node_modules/react-dom', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['assets/**/*.test.ts'],
    },
});
