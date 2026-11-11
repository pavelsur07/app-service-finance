import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  resolve: {
    dedupe: ['react', 'react-dom'],
    alias: {
      react: fileURLToPath(new URL('./node_modules/react', import.meta.url)),
      'react-dom': fileURLToPath(
        new URL('./node_modules/react-dom', import.meta.url),
      ),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5174,
    strictPort: true,
    allowedHosts: ['account', 'admin'],
    fs: {
      allow: [
        fileURLToPath(new URL('.', import.meta.url)),
        fileURLToPath(new URL('../../site/packages', import.meta.url)),
        fileURLToPath(new URL('../../site/assets/styles', import.meta.url)),
      ],
    },
  },
  preview: {
    host: '0.0.0.0',
    port: 4174,
    strictPort: true,
    allowedHosts: ['account', 'admin'],
  },
});
