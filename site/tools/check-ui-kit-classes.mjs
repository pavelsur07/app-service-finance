#!/usr/bin/env node
/**
 * check-ui-kit-classes.mjs
 *
 * Lint: каждый CSS-класс, используемый в assets/ и templates/, должен быть
 * определён в ui-kit/ (components/, patterns/ или storybook.html как fallback).
 *
 * Запуск: node tools/check-ui-kit-classes.mjs
 * Опциональный режим verbose: DEBUG=1 node tools/check-ui-kit-classes.mjs
 *
 * Exit codes:
 *   0 — нарушений нет
 *   1 — найдены нарушения
 *   2 — ошибка конфигурации (нет ui-kit/, нет assets/ и т.п.)
 *
 * Без npm-зависимостей. Node 18+.
 */

import { readFile, readdir, stat } from 'node:fs/promises';
import { join, relative, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const DEBUG = process.env.DEBUG === '1';

// ============================================================
// Конфигурация
// ============================================================

const CONFIG = {
  // Источники определений классов UI Kit
  uiKitCssDirs: [
    'ui-kit/components',
    'ui-kit/patterns',
  ],
  uiKitHtmlFallbacks: [
    'ui-kit/storybook.html', // если ещё не разнесли на отдельные .css
  ],

  // Где искать использование классов
  codeSources: [
    { dir: 'assets/react', exts: ['.tsx', '.ts', '.jsx', '.js'] },
    { dir: 'templates', exts: ['.twig', '.html.twig'] },
  ],

  // Что игнорировать при обходе
  excludeDirs: new Set(['node_modules', '_legacy', 'build', 'dist', '.git', 'public']),
  excludeFiles: [/\.test\./, /\.stories\./, /\.spec\./],

  // Классы, которые разрешены без определения в UI Kit
  // (системные / стандартные HTML / временные исключения)
  allowedExtra: new Set([
    // HTML-стандартные
    'visually-hidden',
    'sr-only',
    // CSS Modules — будут с хешем, фильтруем отдельно
  ]),

  // Префиксы, которые точно НЕ UI Kit и должны игнорироваться
  // (например, если временно подмешан Tabler во время миграции)
  ignorePrefixes: [
    // 'tabler-', // раскомментировать на время миграции
  ],
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
    if (CONFIG.excludeDirs.has(entry.name)) continue;
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
// Извлечение классов из CSS
// ============================================================

function extractClassesFromCss(css) {
  const classes = new Set();
  // Strip CSS comments
  const noComments = css.replace(/\/\*[\s\S]*?\*\//g, '');
  // Match .classname (must start with letter or underscore)
  const regex = /\.([a-zA-Z_][a-zA-Z0-9_-]*)/g;
  let m;
  while ((m = regex.exec(noComments)) !== null) {
    classes.add(m[1]);
  }
  return classes;
}

function extractClassesFromHtmlStyles(html) {
  const classes = new Set();
  const styleBlocks = html.match(/<style[^>]*>([\s\S]*?)<\/style>/gi) || [];
  for (const block of styleBlocks) {
    const inner = block.replace(/^<style[^>]*>/i, '').replace(/<\/style>$/i, '');
    for (const c of extractClassesFromCss(inner)) classes.add(c);
  }
  return classes;
}

async function collectUiKitClasses() {
  const defined = new Set();
  const sources = []; // [{ class, file }]

  for (const dir of CONFIG.uiKitCssDirs) {
    const full = resolve(ROOT, dir);
    const cssFiles = await walk(full, ['.css']);
    for (const file of cssFiles) {
      const content = await readFile(file, 'utf8');
      const classes = extractClassesFromCss(content);
      for (const c of classes) {
        defined.add(c);
        sources.push({ class: c, file: relative(ROOT, file) });
      }
    }
  }

  for (const htmlPath of CONFIG.uiKitHtmlFallbacks) {
    const full = resolve(ROOT, htmlPath);
    try {
      const content = await readFile(full, 'utf8');
      const classes = extractClassesFromHtmlStyles(content);
      for (const c of classes) {
        defined.add(c);
        sources.push({ class: c, file: relative(ROOT, full) });
      }
    } catch (e) {
      if (e.code !== 'ENOENT') throw e;
    }
  }

  return { defined, sources };
}

// ============================================================
// Извлечение использования классов из кода
// ============================================================

/**
 * Возвращает [{ class, dynamic, line, col }] из одного исходника.
 * - dynamic: true, если класс — префикс перед ${...} в template literal,
 *   полная проверка совпадения не делается, только prefix-match.
 */
function extractClassUsageFromTsx(code) {
  const usages = [];
  const lines = code.split('\n');

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNum = i + 1;

    // 1) className="..." / class="..." (твигу и jsx)
    const plainAttr = /(?:className|class)\s*=\s*["']([^"']+)["']/g;
    let m;
    while ((m = plainAttr.exec(line)) !== null) {
      for (const cls of m[1].split(/\s+/).filter(Boolean)) {
        usages.push({ class: cls, dynamic: false, line: lineNum });
      }
    }

    // 2) className={'...'} / className={"..."}
    const wrappedString = /(?:className|class)\s*=\s*\{\s*["']([^"']+)["']\s*\}/g;
    while ((m = wrappedString.exec(line)) !== null) {
      for (const cls of m[1].split(/\s+/).filter(Boolean)) {
        usages.push({ class: cls, dynamic: false, line: lineNum });
      }
    }

    // 3) className={`...`} — template literal (только внутри className={...})
    const wrappedTpl = /(?:className|class)\s*=\s*\{\s*`([^`]+)`\s*\}/g;
    while ((m = wrappedTpl.exec(line)) !== null) {
      pushFromTemplateLiteral(m[1], lineNum, usages);
    }

    // 4) clsx(...) / cn(...) — берём все строки и template literals внутри
    const clsxCall = /\b(?:clsx|cn|classnames)\s*\(([\s\S]*?)\)/g;
    while ((m = clsxCall.exec(line)) !== null) {
      const inner = m[1];
      // строки
      const strLit = /["']([^"']+)["']/g;
      let s;
      while ((s = strLit.exec(inner)) !== null) {
        for (const cls of s[1].split(/\s+/).filter(Boolean)) {
          usages.push({ class: cls, dynamic: false, line: lineNum });
        }
      }
      // template literals
      const tplLit = /`([^`]+)`/g;
      while ((s = tplLit.exec(inner)) !== null) {
        pushFromTemplateLiteral(s[1], lineNum, usages);
      }
    }
  }

  return usages;
}

function pushFromTemplateLiteral(content, lineNum, usages) {
  // Разбиваем по ${...}
  const parts = content.split(/\$\{[^}]*\}/);
  for (let j = 0; j < parts.length; j++) {
    const part = parts[j];
    const hasInterpAfter = j < parts.length - 1;
    const tokens = part.split(/\s+/).filter(Boolean);
    for (let k = 0; k < tokens.length; k++) {
      const token = tokens[k];
      const isLast = k === tokens.length - 1;
      const dynamic = isLast && hasInterpAfter;
      if (dynamic) {
        // Префикс перед ${...} — например `btn-${variant}` → префикс `btn-`
        if (token.endsWith('-') || /[a-zA-Z0-9_]$/.test(token)) {
          usages.push({ class: token, dynamic: true, line: lineNum });
        }
      } else {
        // Полное имя класса
        if (/^[a-zA-Z_][a-zA-Z0-9_-]*$/.test(token)) {
          usages.push({ class: token, dynamic: false, line: lineNum });
        }
      }
    }
  }
}

function extractClassUsageFromTwig(code) {
  const usages = [];
  const lines = code.split('\n');
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNum = i + 1;
    const plain = /\bclass\s*=\s*["']([^"']+)["']/g;
    let m;
    while ((m = plain.exec(line)) !== null) {
      const inner = m[1];
      // Twig: class="btn btn-{{ variant }}" — обрабатываем как template literal через {{ }}
      const parts = inner.split(/\{\{[^}]*\}\}/);
      for (let j = 0; j < parts.length; j++) {
        const part = parts[j];
        const hasInterpAfter = j < parts.length - 1;
        const tokens = part.split(/\s+/).filter(Boolean);
        for (let k = 0; k < tokens.length; k++) {
          const token = tokens[k];
          const isLast = k === tokens.length - 1;
          const dynamic = isLast && hasInterpAfter;
          if (dynamic) {
            if (token.endsWith('-') || /[a-zA-Z0-9_]$/.test(token)) {
              usages.push({ class: token, dynamic: true, line: lineNum });
            }
          } else {
            if (/^[a-zA-Z_][a-zA-Z0-9_-]*$/.test(token)) {
              usages.push({ class: token, dynamic: false, line: lineNum });
            }
          }
        }
      }
    }
  }
  return usages;
}

// ============================================================
// Сверка
// ============================================================

function isClassAllowed(cls, definedClasses) {
  // Точное совпадение
  if (definedClasses.has(cls)) return true;
  // Whitelist
  if (CONFIG.allowedExtra.has(cls)) return true;
  // Игнорируемые префиксы
  for (const prefix of CONFIG.ignorePrefixes) {
    if (cls.startsWith(prefix)) return true;
  }
  return false;
}

function isDynamicPrefixAllowed(prefix, definedClasses) {
  // Динамический класс `btn-${...}` — проверяем, есть ли в UI Kit классы,
  // начинающиеся с этого префикса.
  for (const cls of definedClasses) {
    if (cls.startsWith(prefix)) return true;
  }
  // Также проверяем whitelist по префиксу
  for (const allowed of CONFIG.allowedExtra) {
    if (allowed.startsWith(prefix)) return true;
  }
  for (const ip of CONFIG.ignorePrefixes) {
    if (prefix.startsWith(ip)) return true;
  }
  return false;
}

// ============================================================
// Main
// ============================================================

async function main() {
  console.log('━━━ check-ui-kit-classes ━━━');
  console.log(`root: ${ROOT}`);

  // 1) Собираем определённые классы UI Kit
  const { defined, sources } = await collectUiKitClasses();
  console.log(`UI Kit classes defined: ${defined.size}`);
  if (defined.size === 0) {
    console.error('❌ Не найдено ни одного класса в ui-kit/. Проверьте структуру.');
    console.error('   Ожидается: ui-kit/components/*.css, ui-kit/patterns/*.css, или ui-kit/storybook.html');
    process.exit(2);
  }
  debug('first 20 classes:', [...defined].slice(0, 20));

  // 2) Собираем использование в коде
  const violations = []; // { file, line, class, dynamic, suggestion }
  let filesScanned = 0;
  let usagesScanned = 0;

  for (const source of CONFIG.codeSources) {
    const dir = resolve(ROOT, source.dir);
    const files = await walk(dir, source.exts);
    for (const file of files) {
      filesScanned++;
      const content = await readFile(file, 'utf8');
      const usages = source.exts.includes('.twig') || source.exts.includes('.html.twig')
        ? extractClassUsageFromTwig(content)
        : extractClassUsageFromTsx(content);
      usagesScanned += usages.length;
      for (const u of usages) {
        const ok = u.dynamic
          ? isDynamicPrefixAllowed(u.class, defined)
          : isClassAllowed(u.class, defined);
        if (!ok) {
          const suggestion = suggestClosest(u.class, defined);
          violations.push({
            file: relative(ROOT, file),
            line: u.line,
            class: u.class,
            dynamic: u.dynamic,
            suggestion,
          });
        }
      }
    }
  }

  console.log(`files scanned: ${filesScanned}, usages: ${usagesScanned}`);

  if (violations.length === 0) {
    console.log('✅ Все классы найдены в UI Kit.');
    process.exit(0);
  }

  // 3) Отчёт
  console.error(`\n❌ Нарушений: ${violations.length}\n`);
  const grouped = new Map();
  for (const v of violations) {
    if (!grouped.has(v.file)) grouped.set(v.file, []);
    grouped.get(v.file).push(v);
  }
  for (const [file, list] of grouped) {
    console.error(`  ${file}`);
    for (const v of list) {
      const tag = v.dynamic ? '(prefix)' : '';
      const hint = v.suggestion ? `   → возможно, ${v.suggestion}?` : '';
      console.error(`    L${v.line}: '${v.class}' ${tag}${hint}`);
    }
    console.error('');
  }
  console.error('Подсказка: либо добавьте класс в ui-kit/, либо используйте существующий, либо положите класс в allowedExtra/ignorePrefixes конфига скрипта.');
  process.exit(1);
}

// Простой Levenshtein для подсказки похожих классов
function suggestClosest(target, set) {
  let best = null;
  let bestDist = Infinity;
  for (const cls of set) {
    if (Math.abs(cls.length - target.length) > 3) continue;
    const d = levenshtein(target, cls);
    if (d < bestDist && d <= 3) {
      bestDist = d;
      best = cls;
    }
  }
  return best;
}

function levenshtein(a, b) {
  const m = a.length, n = b.length;
  if (m === 0) return n;
  if (n === 0) return m;
  const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
  for (let i = 0; i <= m; i++) dp[i][0] = i;
  for (let j = 0; j <= n; j++) dp[0][j] = j;
  for (let i = 1; i <= m; i++) {
    for (let j = 1; j <= n; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      dp[i][j] = Math.min(dp[i - 1][j] + 1, dp[i][j - 1] + 1, dp[i - 1][j - 1] + cost);
    }
  }
  return dp[m][n];
}

main().catch(err => {
  console.error('💥 Неожиданная ошибка:', err);
  process.exit(2);
});
