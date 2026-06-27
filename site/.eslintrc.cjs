/* eslint-env node */
const path = require('path');

module.exports = {
  root: true,
  parser: '@typescript-eslint/parser',
  parserOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    ecmaFeatures: { jsx: true },
  },
  plugins: ['import'],
  settings: {
    'import/resolver': {
      typescript: {
        project: path.resolve(__dirname, 'tsconfig.json'),
      },
    },
  },
  rules: {
    // Граница карантина — главное правило этого PR
    'import/no-restricted-paths': ['error', {
      zones: [
        // Никто кроме entrypoints не импортирует из _legacy/
        {
          target: path.resolve(__dirname, 'assets/react/modules'),
          from: path.resolve(__dirname, 'assets/react/_legacy'),
          message: 'Не импортировать из _legacy/. Перенесите нужное в modules/ или shared/ как часть миграции.',
        },
        {
          target: path.resolve(__dirname, 'assets/react/ui-kit'),
          from: path.resolve(__dirname, 'assets/react/_legacy'),
          message: 'UI Kit не зависит от _legacy/. Никогда.',
        },
        {
          target: path.resolve(__dirname, 'assets/react/shared'),
          from: path.resolve(__dirname, 'assets/react/_legacy'),
          message: 'shared/ не зависит от _legacy/. Если нужна утилита из legacy — перепишите её в shared/ заново.',
        },
        // _legacy/ не питается новым кодом
        {
          target: path.resolve(__dirname, 'assets/react/_legacy'),
          from: path.resolve(__dirname, 'assets/react/modules'),
          message: '_legacy/ заморожен. Не добавляйте импорты из modules/ в legacy-код.',
        },
        {
          target: path.resolve(__dirname, 'assets/react/_legacy'),
          from: path.resolve(__dirname, 'assets/react/ui-kit'),
          message: '_legacy/ заморожен. Не добавляйте импорты из ui-kit/ в legacy-код.',
        },
      ],
    }],
  },
  ignorePatterns: [
    'node_modules/',
    'public/build/',
    'vendor/',
    '*.config.js',
    '*.config.mjs',
    '*.config.cjs',
  ],
};
