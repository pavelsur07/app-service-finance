#!/usr/bin/env node
/**
 * check-uikit-react-mapping.mjs
 *
 * Lint: для каждого ui-kit/components/<name>.html и ui-kit/patterns/<name>.html
 * должна существовать React-обёртка в assets/react/ui-kit/<Component>/<Component>.tsx
 * с JSDoc-аннотацией @uiKit, и в HTML должен быть обратный комментарий @react.
 *
 * Проверки:
 *  1. Каждая React-обёртка имеет JSDoc с `@uiKit <путь>` и `@version <X.Y>`.
 *  2. Путь из @uiKit указывает на существующий файл в ui-kit/.
 *  3. Каждый ui-kit/(components|patterns)/<name>.html имеет HTML-комментарий
 *     `<!-- @react <ComponentName> -->` с именем существующей React-обёртки.
 *  4. Связь двусторонняя: A@uiKit=path/to/B и B@react=A.
 *
 * Запуск: node tools/check-uikit-react-mapping.mjs
 * Verbose: DEBUG=1 node tools/check-uikit-react-mapping.mjs
 *
 * Exit codes:
 *   0 — нарушений нет
 *   1 — найдены нарушения
 *   2 — ошибка конфигурации
 *
 * Без npm-зависимостей. Node 18+.
 */

import { readFile, readdir, stat } from 'node:fs/promises';
import { join, relative, resolve, dirname, basename, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const DEBUG = process.env.DEBUG === '1';

// ============================================================
// Конфигурация
// ============================================================

const CONFIG = {
  uiKitRefDirs: [
    'ui-kit/components',
    'ui-kit/patterns',
  ],
  reactWrappersDir: 'assets/react/ui-kit',
  excludeFiles: [/\.test\./, /\.stories\./, /\.spec\./, /^index\./],

  // Компоненты UI Kit, для которых React-обёртка не требуется
  // (например, чистая разметка для Twig без React-аналога)
  reactOptional: new Set([
    // 'icon-sprite',
  ]),
};

// ============================================================
// Утилиты
// ============================================================

async function walk(dir, exts) {
  const results = [];
  let entries;
  try {
    entries = await readdir(dir, { withFileTypes: true });
  } catch (e) {
    if (e.code === 'ENOENT') return results;
    throw e;
  }
  for (const entry of entries) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...await walk(full, exts));
    } else if (exts.some(ext => entry.name.endsWith(ext))) {
      if (CONFIG.excludeFiles.some(rx => rx.test(entry.name))) continue;
      results.push(full);
    }
  }
  return results;
}

function debug(...args) {
  if (DEBUG) console.error('[debug]', ...args);
}

// ============================================================
// Сбор UI Kit референсов (HTML)
// ============================================================

async function collectUiKitRefs() {
  // [{ path, name, reactComponent }] — name = filename без расширения
  const refs = [];
  for (const dir of CONFIG.uiKitRefDirs) {
    const full = resolve(ROOT, dir);
    const files = await walk(full, ['.html']);
    for (const file of files) {
      const content = await readFile(file, 'utf8');
      const name = basename(file, '.html');
      const reactComponent = extractReactAnnotation(content);
      refs.push({
        path: relative(ROOT, file),
        name,
        reactComponent,
      });
    }
  }
  return refs;
}

function extractReactAnnotation(html) {
  // <!-- @react Button --> или <!-- @react: Button -->
  const m = html.match(/<!--\s*@react:?\s+([A-Za-z][A-Za-z0-9]*)\s*-->/);
  return m ? m[1] : null;
}

// ============================================================
// Сбор React-обёрток
// ============================================================

async function collectReactWrappers() {
  // [{ path, componentName, uiKitRef, version }]
  const wrappers = [];
  const dir = resolve(ROOT, CONFIG.reactWrappersDir);
  const files = await walk(dir, ['.tsx', '.ts']);

  for (const file of files) {
    const content = await readFile(file, 'utf8');
    const componentName = extractComponentName(content, file);
    if (!componentName) continue; // не компонент

    const { uiKitRef, version } = extractJsdocUiKit(content);
    wrappers.push({
      path: relative(ROOT, file),
      componentName,
      uiKitRef,
      version,
    });
  }
  return wrappers;
}

function extractComponentName(code, filePath) {
  // 1) `export const ComponentName: React.FC` или `export const ComponentName: FC`
  let m = code.match(/export\s+const\s+([A-Z][A-Za-z0-9]*)\s*:\s*(?:React\.)?FC\b/);
  if (m) return m[1];
  // 2) `export function ComponentName(`
  m = code.match(/export\s+function\s+([A-Z][A-Za-z0-9]*)\s*[(<]/);
  if (m) return m[1];
  // 3) `const ComponentName: React.FC` + позже `export { ComponentName }` или `export default ComponentName`
  m = code.match(/(?:^|\n)\s*const\s+([A-Z][A-Za-z0-9]*)\s*:\s*(?:React\.)?FC\b/);
  if (m) {
    const name = m[1];
    if (code.match(new RegExp(`export\\s*\\{\\s*[^}]*\\b${name}\\b[^}]*\\}`))
      || code.match(new RegExp(`export\\s+default\\s+${name}\\b`))) {
      return name;
    }
  }
  // Fallback: имя файла, если оно похоже на компонент
  const base = basename(filePath, extname(filePath));
  if (/^[A-Z]/.test(base)) {
    return base;
  }
  return null;
}

function extractJsdocUiKit(code) {
  // Ищем JSDoc-блок прямо перед `export const`/`export function`/`const`
  // Простой подход: глобальный поиск @uiKit и @version в первых ~50 строках
  const head = code.split('\n').slice(0, 80).join('\n');

  const uikit = head.match(/@uiKit\s+([^\s*\n]+)/);
  const version = head.match(/@version\s+([^\s*\n]+)/);
  return {
    uiKitRef: uikit ? uikit[1] : null,
    version: version ? version[1] : null,
  };
}

// ============================================================
// Сверка
// ============================================================

async function fileExists(p) {
  try {
    await stat(resolve(ROOT, p));
    return true;
  } catch {
    return false;
  }
}

async function check() {
  const refs = await collectUiKitRefs();
  const wrappers = await collectReactWrappers();

  debug('refs:', refs.map(r => `${r.path} → ${r.reactComponent || '∅'}`));
  debug('wrappers:', wrappers.map(w => `${w.componentName} → ${w.uiKitRef || '∅'}`));

  const violations = [];

  // Индексы
  const refByPath = new Map(refs.map(r => [r.path, r]));
  const wrapperByComponent = new Map(wrappers.map(w => [w.componentName, w]));

  // ----- Проверки на стороне React-обёрток -----
  for (const w of wrappers) {
    if (!w.uiKitRef) {
      violations.push({
        kind: 'wrapper-missing-uikit-annotation',
        file: w.path,
        message: `Нет JSDoc-аннотации @uiKit над компонентом ${w.componentName}.`,
        hint: `Добавьте /** @uiKit ui-kit/components/<name>.html @version <X.Y> */ над export.`,
      });
      continue;
    }
    if (!w.version) {
      violations.push({
        kind: 'wrapper-missing-version',
        file: w.path,
        message: `Нет JSDoc-аннотации @version над компонентом ${w.componentName}.`,
        hint: `Добавьте @version <X.Y> (текущая версия UI Kit из ui-kit/CHANGELOG.md).`,
      });
    }
    if (!await fileExists(w.uiKitRef)) {
      violations.push({
        kind: 'wrapper-broken-uikit-ref',
        file: w.path,
        message: `@uiKit ссылается на несуществующий файл: ${w.uiKitRef}`,
        hint: `Проверьте путь. Должен быть ui-kit/components/<name>.html или ui-kit/patterns/<name>.html.`,
      });
      continue;
    }
    // Проверка двусторонности: ref-файл должен указывать обратно на этот компонент
    const ref = refByPath.get(w.uiKitRef);
    if (ref && ref.reactComponent && ref.reactComponent !== w.componentName) {
      violations.push({
        kind: 'wrapper-uikit-mismatch',
        file: w.path,
        message: `Обёртка ${w.componentName} ссылается на ${w.uiKitRef}, но там @react = ${ref.reactComponent}.`,
        hint: `Согласуйте имена. Либо переименуйте обёртку, либо обновите HTML-комментарий.`,
      });
    }
    if (ref && !ref.reactComponent) {
      violations.push({
        kind: 'ref-missing-react-annotation',
        file: ref.path,
        message: `HTML-референс не содержит обратной ссылки <!-- @react ${w.componentName} -->.`,
        hint: `Добавьте в начало файла: <!-- @react ${w.componentName} -->.`,
      });
    }
  }

  // ----- Проверки на стороне UI Kit референсов -----
  for (const ref of refs) {
    if (CONFIG.reactOptional.has(ref.name)) continue;

    if (!ref.reactComponent) {
      // Если не помечен @react — возможно, у него вообще нет React-обёртки. Это нарушение,
      // но мягкое: предлагаем явно пометить, либо добавить обёртку, либо в reactOptional.
      const candidate = guessComponentName(ref.name);
      const exists = wrapperByComponent.has(candidate);
      violations.push({
        kind: 'ref-no-react-mapping',
        file: ref.path,
        message: `HTML-референс не указывает соответствующую React-обёртку (<!-- @react ... -->).`,
        hint: exists
          ? `Похоже, обёртка ${candidate} существует. Добавьте: <!-- @react ${candidate} -->.`
          : `Создайте обёртку assets/react/ui-kit/${candidate}/${candidate}.tsx или добавьте '${ref.name}' в CONFIG.reactOptional.`,
      });
      continue;
    }

    const wrapper = wrapperByComponent.get(ref.reactComponent);
    if (!wrapper) {
      violations.push({
        kind: 'ref-broken-react-ref',
        file: ref.path,
        message: `@react ${ref.reactComponent} — компонент не найден в ${CONFIG.reactWrappersDir}/.`,
        hint: `Создайте ${CONFIG.reactWrappersDir}/${ref.reactComponent}/${ref.reactComponent}.tsx, или поправьте @react в HTML.`,
      });
      continue;
    }
    // Проверка двусторонности: обёртка должна ссылаться обратно
    if (wrapper.uiKitRef !== ref.path) {
      violations.push({
        kind: 'ref-react-mismatch',
        file: ref.path,
        message: `@react ${ref.reactComponent}, но в ${wrapper.path} @uiKit = ${wrapper.uiKitRef || '∅'} (не совпадает с ${ref.path}).`,
        hint: `Согласуйте обе ссылки.`,
      });
    }
  }

  return { violations, refs, wrappers };
}

function guessComponentName(filename) {
  // kpi-row → KpiRow, button → Button, page-header → PageHeader
  return filename
    .split('-')
    .map(w => w.charAt(0).toUpperCase() + w.slice(1))
    .join('');
}

// ============================================================
// Main
// ============================================================

async function main() {
  console.log('━━━ check-uikit-react-mapping ━━━');
  console.log(`root: ${ROOT}`);

  // Проверим наличие ключевых директорий
  const dirs = [...CONFIG.uiKitRefDirs, CONFIG.reactWrappersDir];
  let anyExists = false;
  for (const d of dirs) {
    try {
      await stat(resolve(ROOT, d));
      anyExists = true;
    } catch {}
  }
  if (!anyExists) {
    console.error('❌ Не найдено ни одной из ключевых директорий:');
    for (const d of dirs) console.error(`   ${d}`);
    process.exit(2);
  }

  const { violations, refs, wrappers } = await check();

  console.log(`UI Kit refs: ${refs.length}`);
  console.log(`React wrappers: ${wrappers.length}`);

  if (violations.length === 0) {
    console.log('✅ Все связи согласованы.');
    process.exit(0);
  }

  console.error(`\n❌ Нарушений: ${violations.length}\n`);

  // Группируем по файлу
  const grouped = new Map();
  for (const v of violations) {
    if (!grouped.has(v.file)) grouped.set(v.file, []);
    grouped.get(v.file).push(v);
  }
  for (const [file, list] of grouped) {
    console.error(`  ${file}`);
    for (const v of list) {
      console.error(`    [${v.kind}] ${v.message}`);
      if (v.hint) console.error(`        → ${v.hint}`);
    }
    console.error('');
  }

  process.exit(1);
}

main().catch(err => {
  console.error('💥 Неожиданная ошибка:', err);
  process.exit(2);
});
