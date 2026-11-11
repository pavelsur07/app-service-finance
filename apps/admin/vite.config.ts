import { fileURLToPath } from 'node:url';
import { defineConfig, type ViteDevServer, type PreviewServer } from 'vite';
import react from '@vitejs/plugin-react';

function reserveBackendPaths(server: ViteDevServer | PreviewServer) {
  server.middlewares.use((request, response, next) => {
    const path = (request.url ?? '/').split('?')[0] ?? '/';
    if (
      !path.startsWith('/api/admin/v1/') &&
      (/^\/(api|internal|admin)(\/|$)/.test(path) ||
        /\.php(?:\/|$)/i.test(path))
    ) {
      response.statusCode = 404;
      response.end('Not found');
      return;
    }
    next();
  });
}

export default defineConfig({
  plugins: [
    react(),
    {
      name: 'spa-backend-boundary',
      configureServer: reserveBackendPaths,
      configurePreviewServer: reserveBackendPaths,
    },
  ],
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
    port: 5175,
    strictPort: true,
    allowedHosts: ['account', 'admin', 'account.auth.test', 'admin.auth.test'],
    proxy: process.env.FRONT_API_TARGET
      ? {
          '/api/admin/v1/': {
            target: process.env.FRONT_API_TARGET,
            changeOrigin: false,
          },
        }
      : {},
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
    port: 4175,
    strictPort: true,
    allowedHosts: ['account', 'admin', 'account.auth.test', 'admin.auth.test'],
    proxy: process.env.FRONT_API_TARGET
      ? {
          '/api/admin/v1/': {
            target: process.env.FRONT_API_TARGET,
            changeOrigin: false,
          },
        }
      : {},
  },
});
