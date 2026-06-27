# Verify npm run build — после uikit-split

> Микро-задача. Проверить, что `npm run build` проходит чисто на ветке `chore/uikit-split` и собирает все entries.
> Только проверка. Никаких правок кода, никакого merge.

---

## Контекст

В отчёте по `uikit-split` build остановился с EACCES на `public/build/.vite` (pre-existing, права root). Vite успел трансформировать 102 модуля до ошибки записи. Нужно подтвердить, что **с правильными правами** build проходит до конца и собирает все 13 entries.

---

## Pre-flight

1. Находимся на ветке `chore/uikit-split`. Если нет:
   ```bash
   git fetch origin
   git checkout chore/uikit-split
   git pull
   ```
2. Текущая директория = корень репозитория.
3. `git status` чистый.

---

## Phase 1 — Починить права на `public/build/`

```bash
cd site
ls -la public/build/.vite 2>/dev/null
```

Если показывает `root:root` — починить:

```bash
sudo chown -R $(whoami):$(whoami) public/build
```

Если `sudo` недоступен (нет прав sudo для текущего пользователя) — 🛑 STOP, в отчёт: «нужны права sudo на хосте, сделай вручную». Это нормальный случай для production-сервера, локально обычно работает.

---

## Phase 2 — Очистить кэш и собрать

```bash
cd site
rm -rf public/build/.vite public/build/assets public/build/manifest.json
npm run build
```

Зафиксировать:
- exit code
- стрим вывода (последние 30 строк)
- время сборки

---

## Phase 3 — Проверить результат

### 3.1. Exit code

```bash
echo "Exit code: $?"
```

Должен быть `0`. Если не `0` — 🛑 STOP, в отчёт полный вывод ошибки.

### 3.2. manifest.json существует

```bash
cd site
test -f public/build/manifest.json && echo "manifest: OK" || echo "manifest: MISSING"
```

### 3.3. Все 13 entries в manifest

```bash
cd site
cat public/build/manifest.json | python3 -c "
import json, sys
m = json.load(sys.stdin)
expected = [
  'assets/app.js',
  'assets/styles/design-tokens.css',
  'assets/styles/vf-custom-classes.css',
  'assets/react/_legacy/dashboard_started.tsx',
  'assets/react/_legacy/marketplace_analytics_kpi.tsx',
  'assets/react/_legacy/marketplace-analytics-page.tsx',
  'assets/react/_legacy/reconciliation-page.tsx',
  'assets/react/_legacy/unit-extended-page.tsx',
  'assets/react/_legacy/ad-efficiency-page.tsx',
  'assets/react/_legacy/ingestion-verification-coverage-page.tsx',
  'assets/react/_legacy/ingestion-verification-reconciliation-page.tsx',
  'assets/react/_legacy/ingestion-verification-issues-page.tsx',
  'assets/react/_legacy/ingestion-verification-financial-summary-page.tsx',
]
found = list(m.keys())
print(f'Expected: {len(expected)}, Found in manifest: {len(found)}')
print('---')
print('Missing:')
for e in expected:
  if e not in found:
    print(f'  - {e}')
print('Extra (not expected but present):')
for f in found:
  if f not in expected:
    print(f'  + {f}')
"
```

Допустимо если в manifest есть **дополнительные** записи помимо 13 (Vite иногда добавляет служебные chunks). **Недопустимо** если хоть один из 13 expected отсутствует.

### 3.4. CSS-импорты UI Kit разрезолвились

```bash
cd site
grep -c "ui-kit" public/build/manifest.json || echo "no ui-kit references"
```

Если в manifest есть ссылки на скомпилированный CSS из `ui-kit/` — резолв сработал. Если нет — посмотреть, попал ли UI Kit CSS в `assets/app.js` или `assets/styles/*.css` (inline bundling — это тоже OK).

### 3.5. Размер бандла

```bash
cd site
du -sh public/build/
ls -la public/build/assets/ | head -20
```

Просто чтоб зафиксировать «сборка реальная, файлы появились».

---

## Phase 4 — Отчёт

Создать файл `docs/migration/uikit-split-build-verify.md`:

```markdown
# uikit-split — build verification

**Date:** <YYYY-MM-DD>
**Branch:** chore/uikit-split
**Last commit:** <git log -1 --oneline>

## Build

- Exit code: <0 / non-zero>
- Build time: <X seconds>
- Output size: <du -sh result>
- manifest.json: <OK / MISSING>

## Entries

- Expected: 13
- Found in manifest: <N>
- Missing: <list or "none">
- Extra: <list or "none">

## UI Kit CSS resolution

- References to ui-kit/* in manifest.json: <count>
- CSS bundling: <inline in entries / separate chunk>

## Verdict

<one of:>
- ✅ Build clean, all entries present, safe to merge.
- ⚠️ Build clean but with warnings: <list>
- ❌ Build broken: <reason>

## Logs (last 30 lines of npm run build)

\`\`\`
<paste here>
\`\`\`
```

Закоммитить:
```bash
git add docs/migration/uikit-split-build-verify.md
git commit -m "docs(verify): npm run build verification for uikit-split"
git push
```

---

## Self-review

- [ ] `npm run build` exit 0
- [ ] `public/build/manifest.json` существует
- [ ] Все 13 expected entries в manifest
- [ ] Отчёт `docs/migration/uikit-split-build-verify.md` создан
- [ ] Никакие файлы кода не правились (только новый docs/-файл)

---

## Что НЕ делать

- Не править `vite.config.js`, `package.json`, `tsconfig.json`.
- Не править файлы в `ui-kit/`, `assets/`, `templates/`.
- Не делать `npm install` / `npm update`.
- Не мержить PR.
- Не переводить PR из draft в ready.

---

## Closing

🛑 STOP. Отчёт `uikit-split-build-verify.md` запушен. Жду решения Владельца по merge на основе verdict.
