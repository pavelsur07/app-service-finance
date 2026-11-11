import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import imports from 'eslint-plugin-import';
import { fileURLToPath, URL } from 'node:url';

const repo = fileURLToPath(new URL('../..', import.meta.url));
export default tseslint.config(
  {
    ignores: [
      '**/node_modules/**',
      '**/dist/**',
      '**/test-results/**',
      '**/playwright-report/**',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    plugins: { import: imports },
    settings: {
      'import/resolver': {
        typescript: {
          project: fileURLToPath(new URL('./tsconfig.json', import.meta.url)),
        },
      },
    },
    rules: {
      '@typescript-eslint/no-non-null-assertion': 'error',
      'no-restricted-globals': ['error', 'fetch'],
      'no-restricted-syntax': [
        'error',
        {
          selector:
            "CallExpression[callee.type='MemberExpression'][callee.property.name='fetch']",
          message: 'Use @vashfindir/api.',
        },
      ],
      'import/no-restricted-paths': [
        'error',
        {
          basePath: repo,
          zones: [
            { target: './apps/account', from: './apps/admin' },
            { target: './apps/admin', from: './apps/account' },
            { target: './apps', from: './site', except: ['./packages'] },
            { target: './site/packages', from: './apps/account/src' },
            { target: './site/packages', from: './apps/admin/src' },
            { target: './site/packages', from: './site/assets' },
          ],
        },
      ],
    },
  },
  {
    files: ['site/packages/api/src/**/*.ts'],
    rules: { 'no-restricted-globals': 'off' },
  },
);
