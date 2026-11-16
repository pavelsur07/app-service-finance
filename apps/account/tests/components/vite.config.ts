import { fileURLToPath } from 'node:url';
import { mergeConfig } from 'vite';
import appConfig from '../../vite.config';

export default mergeConfig(appConfig, {
  root: fileURLToPath(new URL('.', import.meta.url)),
  build: { outDir: '../../test-results/components-dist', emptyOutDir: true },
  preview: { host: '0.0.0.0', port: 4180, strictPort: true },
});
