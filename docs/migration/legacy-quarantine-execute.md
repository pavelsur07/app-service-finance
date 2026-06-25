# Legacy Quarantine Execute — миграционный PR

> Выполняет план из `docs/migration/legacy-quarantine-recon.md`.
> Переносит существующий React-код в `assets/react/_legacy/`, обновляет `vite.config.js`,
> чинит один сломанный импорт, прогоняет сборку, открывает PR.

---

## ⚠️ Эта задача выполняется в ОДИН запуск, но имеет ВНУТРЕННИЙ STOP

- **Phase 0–5** (подготовка, перенос, правки, сборка, локальный smoke-чеклист) — выполняются автономно.
- **Phase 6 (push + open PR)** — выполняется автономно, но PR создаётся в статусе **draft**.
- **Финальный merge** — только Владелец, вручную, после ручного smoke на dev-среде.

Никакого `git push --force`. Никакого merge в `master`. Никакого закрытия draft → ready без Владельца.

---

## Входы

| Что | Где | Обязательно |
|---|---|---|
| Recon-отчёт | `docs/migration/legacy-quarantine-recon.md` | да |
| `vite.config.js` | корень `site/` | да |
| Список git mv | секция 7 recon-отчёта | да |
| Список сломанных импортов | секция 6 recon-отчёта | да |
| Smoke checklist | секция 8 recon-отчёта | да |

Если recon-отчёт отсутствует или старше 14 дней — 🛑 STOP, попросить пересоздать.

---

## Pre-flight проверки (обязательны, до любого `git mv`)

1. `git status` — рабочее дерево чистое. Если нет — 🛑 STOP.
2. Текущая ветка = `master` (или основная). Если нет — `git checkout master && git pull`.
3. `git pull origin master` — на свежем master.
4. Проверить, что `assets/react/_legacy/` **не существует**:
   ```bash
   test ! -d site/assets/react/_legacy || { echo "_legacy already exists, STOP"; exit 1; }
   ```
5. Проверить, что все 15 директорий и файлов из плана **существуют** по исходным путям. Если хоть один отсутствует — 🛑 STOP, отчёт о расхождении.
6. Проверить, что `SnapshotListDemo.tsx` **уже удалён**:
   ```bash
   test ! -f site/assets/react/marketplace-analytics/SnapshotListDemo.tsx \
     || { echo "SnapshotListDemo still exists, was it removed?"; exit 1; }
   ```
7. Проверить, что `npm` и `node` доступны:
   ```bash
   cd site && npm --version && node --version
   ```

Если любая pre-flight проверка падает — 🛑 STOP с объяснением, ничего не меняем.

---

## Phase 1 — Создание ветки

```bash
cd ~/projects/app-service-finance
git checkout -b chore/legacy-quarantine
```

Если ветка `chore/legacy-quarantine` уже существует локально — 🛑 STOP, запросить решение (удалить или продолжить с неё).

---

## Phase 2 — git mv (15 операций)

Выполнять **строго в этом порядке** — сначала директории, потом top-level файлы:

```bash
cd ~/projects/app-service-finance/site

mkdir -p assets/react/_legacy

# Директории (6 шт)
git mv assets/react/Dashboard                assets/react/_legacy/Dashboard
git mv assets/react/ingestion-verification   assets/react/_legacy/ingestion-verification
git mv assets/react/marketplace-ads          assets/react/_legacy/marketplace-ads
git mv assets/react/marketplace-analytics    assets/react/_legacy/marketplace-analytics
git mv assets/react/reconciliation           assets/react/_legacy/reconciliation
git mv assets/react/shared                   assets/react/_legacy/shared

# Top-level entry файлы (10 шт)
git mv assets/react/dashboard_started.tsx                              assets/react/_legacy/dashboard_started.tsx
git mv assets/react/marketplace_analytics_kpi.tsx                      assets/react/_legacy/marketplace_analytics_kpi.tsx
git mv assets/react/marketplace-analytics-page.tsx                     assets/react/_legacy/marketplace-analytics-page.tsx
git mv assets/react/reconciliation-page.tsx                            assets/react/_legacy/reconciliation-page.tsx
git mv assets/react/unit-extended-page.tsx                             assets/react/_legacy/unit-extended-page.tsx
git mv assets/react/ad-efficiency-page.tsx                             assets/react/_legacy/ad-efficiency-page.tsx
git mv assets/react/ingestion-verification-coverage-page.tsx           assets/react/_legacy/ingestion-verification-coverage-page.tsx
git mv assets/react/ingestion-verification-reconciliation-page.tsx     assets/react/_legacy/ingestion-verification-reconciliation-page.tsx
git mv assets/react/ingestion-verification-issues-page.tsx             assets/react/_legacy/ingestion-verification-issues-page.tsx
git mv assets/react/ingestion-verification-financial-summary-page.tsx  assets/react/_legacy/ingestion-verification-financial-summary-page.tsx
```

После всех `git mv`:
- `git status` должен показать ровно 95 переименованных файлов (`renamed:` префикс).
- В `assets/react/` корне не должно остаться ни одного `.tsx`/`.jsx` файла.
- Должны остаться **только** `_legacy/` (новая) и любые уже существовавшие подпапки, не входящие в перенос.

Если git показал `deleted:` / `new file:` вместо `renamed:` — git не распознал переименование. Это **не баг**, история всё равно сохранится, но проверь, что diff соответствует ожиданию.

---

## Phase 3 — Правка одного сломанного импорта

Файл: `site/assets/react/_legacy/ingestion-verification/types.ts`

Изменение:
```diff
- import type { operations } from '../../api/schema';
+ import type { operations } from '@/api/schema';
```

Команда:
```bash
cd ~/projects/app-service-finance/site
sed -i.bak "s|from '../../api/schema'|from '@/api/schema'|" \
  assets/react/_legacy/ingestion-verification/types.ts
rm assets/react/_legacy/ingestion-verification/types.ts.bak
```

Проверка:
```bash
grep -n "from '@/api/schema'" assets/react/_legacy/ingestion-verification/types.ts
# Должна быть ровно 1 строка с этим импортом
```

Если sed не сработал (regex не совпал) — 🛑 STOP, разобраться вручную.

---

## Phase 4 — Обновление `vite.config.js`

Заменить **10 путей** в `rollupOptions.input`. Имена entry **не менять** — только пути.

Маппинг (entry name → новый путь):

```
dashboard                                       → ./assets/react/_legacy/dashboard_started.tsx
marketplace_analytics_kpi                       → ./assets/react/_legacy/marketplace_analytics_kpi.tsx
marketplace_analytics_page                      → ./assets/react/_legacy/marketplace-analytics-page.tsx
reconciliation_page                             → ./assets/react/_legacy/reconciliation-page.tsx
unit_extended_page                              → ./assets/react/_legacy/unit-extended-page.tsx
ad_efficiency_page                              → ./assets/react/_legacy/ad-efficiency-page.tsx
ingestion_verification_coverage_page            → ./assets/react/_legacy/ingestion-verification-coverage-page.tsx
ingestion_verification_reconciliation_page      → ./assets/react/_legacy/ingestion-verification-reconciliation-page.tsx
ingestion_verification_issues_page              → ./assets/react/_legacy/ingestion-verification-issues-page.tsx
ingestion_verification_financial_summary_page   → ./assets/react/_legacy/ingestion-verification-financial-summary-page.tsx
```

Три entry **не трогать**: `app`, `design_tokens`, `vf_custom_classes`.

Стратегия правки — `str_replace` по каждой строке (не `sed` массово, чтобы не зацепить что-то лишнее).

Проверка после:
```bash
cd ~/projects/app-service-finance/site
grep -E "assets/react/[^_]" vite.config.js
# Не должно показать ни одной строки (все пути ведут на _legacy/ или вообще не на react/)
```

Если grep что-то нашёл — 🛑 STOP, недоработка в правке.

---

## Phase 5 — Создание `_legacy/README.md`

Файл `site/assets/react/_legacy/README.md`:

```markdown
# _legacy/ — карантин для старого React-кода

Этот код существует только до миграции в `assets/react/modules/`.

## Правила

1. **НЕ добавлять сюда новые файлы.** Карантин не растёт.
2. **Любая правка legacy-файла = задача на миграцию** в `assets/react/modules/<module>/`.
3. После миграции файлы из `_legacy/` **удаляются в том же PR**, что переносит модуль.
4. Цель: пустой `_legacy/`.

## Текущее состояние

- Карантин создан: 2026-06-25
- Файлов на старте: 95
- Структура внутри: соответствует исходной в `assets/react/`, ничего не реорганизовано.

## Что менять можно (исключения)

- Hot-fix критичного бага в проде, если миграция модуля ещё не запланирована.
  При этом — обязательно создать задачу на миграцию модуля.

## История

См. `docs/migration/legacy-quarantine-recon.md` — отчёт разведки.
См. PR `chore/legacy-quarantine` — миграционный коммит.
```

---

## Phase 6 — Сборка и smoke (локально)

```bash
cd ~/projects/app-service-finance/site

# Полная сборка
npm run build
```

**Ожидаемо:**
- Exit code 0.
- Vite собирает все 13 entries (3 неперенесённых + 10 перенесённых).
- В выводе нет `ERROR`, нет `Could not resolve`, нет TypeScript-ошибок.
- Создаётся `public/build/manifest.json` со всеми 13 entries.

Если сборка падает — 🛑 STOP, **не пушить ветку**. Сохранить вывод ошибки, проанализировать, исправить (скорее всего ещё один сломанный импорт, не пойманный разведкой), повторить сборку. После 3 неудачных попыток — STOP с отчётом.

Если сборка прошла:

```bash
# Запустить dev-сервер для ручного smoke (опционально, можно пропустить и доверить Владельцу)
npm run dev &
DEV_PID=$!
sleep 5
# ... проверки страниц делает Владелец вручную
# Команда останавливается, не убивая dev (Владелец сам решит)
```

Для **автоматической** части smoke — статические проверки:

```bash
# Линтеры UI Kit (должны быть green, _legacy исключён из их scope)
node tools/check-ui-kit-classes.mjs
node tools/check-uikit-react-mapping.mjs
```

Оба должны быть green. Если красные — сюрприз, разбираться отдельно.

---

## Phase 7 — Коммит и push

```bash
cd ~/projects/app-service-finance

git add -A
git status   # финальный взгляд: 95 renamed + 1 modified (types.ts) + 1 modified (vite.config.js) + 1 new (_legacy/README.md)

git commit -m "chore(react): quarantine legacy code to _legacy/

Move 95 files from assets/react/ to assets/react/_legacy/
without functional changes. Vite entry names preserved, only
source paths updated in vite.config.js.

Fixes one broken relative import (../../api/schema → @/api/schema)
in ingestion-verification/types.ts.

Per docs/migration/legacy-quarantine-recon.md.

Rollback: git revert <this-commit-sha>."

git push -u origin chore/legacy-quarantine
```

---

## Phase 8 — Открыть draft PR

PR создаётся **в статусе draft**. Финальный merge — только Владелец вручную после ручного smoke в dev/staging.

Тело PR:

```markdown
# Legacy Quarantine — Migration PR

Moves existing React code to `assets/react/_legacy/` per
`docs/migration/legacy-quarantine-recon.md`.

## Scope

- 95 files moved (6 directories + 10 top-level entries).
- 1 broken import fixed (`ingestion-verification/types.ts`).
- 10 paths updated in `vite.config.js` (entry names preserved).
- New `_legacy/README.md` with quarantine rules.

## What did NOT change

- No Twig templates touched. Vite entry names preserved, so `vite_entry_script_tags(...)` calls in templates continue to work.
- No `assets/api/`, no `assets/controllers/`, no Symfony code touched.
- No new dependencies. No `npm install`.
- No functional change. Pixel-perfect identical UX.

## Verification done locally

- [x] `npm run build` — green
- [x] `node tools/check-ui-kit-classes.mjs` — 0 violations (legacy excluded)
- [x] `node tools/check-uikit-react-mapping.mjs` — green

## Verification needed by Owner before merge (manual smoke)

Open each URL in dev/staging, confirm visual identity vs production:

- [ ] `/dashboard`
- [ ] `/marketplace-analytics`
- [ ] `/marketplace-analytics/unit-extended`
- [ ] `/marketplace/reconciliation`
- [ ] `/marketplace-ads/efficiency`
- [ ] `/ingestion/verification/coverage`
- [ ] `/ingestion/verification/reconciliation`
- [ ] `/ingestion/verification/issues`
- [ ] `/ingestion/verification/financial-summary`

For each: page renders, console clean (no JS errors), Network shows 200s.

## Rollback

Atomic PR. Single `git revert <merge-commit-sha>` returns to pre-quarantine state.

## Next steps (after merge)

1. Enable ESLint `import/no-restricted-paths` rule blocking imports from `_legacy/` into future `modules/`.
2. Enable both UI Kit linters as required CI checks.
3. Start migrating modules: Dashboard first (smallest), then per `CLAUDE.frontend.md` workflow.
```

PR создаётся командой (если установлен `gh`):

```bash
gh pr create \
  --draft \
  --title "chore(react): quarantine legacy code to _legacy/" \
  --body-file <(cat <<'EOF'
... тело PR из шаблона выше ...
EOF
) \
  --base master \
  --head chore/legacy-quarantine
```

Если `gh` недоступен — вывести URL для ручного создания:
```
https://github.com/<org>/<repo>/compare/master...chore/legacy-quarantine?expand=1
```
и пометить «открыть draft PR вручную с приведённым телом».

---

## Self-review (перед STOP)

- [ ] Pre-flight все 7 проверок пройдены до начала
- [ ] `git status` показывает ровно: 95 renamed + 2 modified + 1 new
- [ ] В `assets/react/` корне нет `.tsx`/`.jsx` файлов вне `_legacy/`
- [ ] `vite.config.js` не содержит путей вида `./assets/react/<что-то>.tsx` (только `./assets/react/_legacy/...`)
- [ ] `types.ts` использует `@/api/schema`, не `../../api/schema`
- [ ] `npm run build` — green
- [ ] `check-ui-kit-classes.mjs` — green
- [ ] `check-uikit-react-mapping.mjs` — green
- [ ] `_legacy/README.md` создан
- [ ] Коммит сделан с понятным сообщением
- [ ] Ветка запушена
- [ ] PR в **draft** статусе
- [ ] **НЕ нажат** merge, **НЕ переведён** ready-for-review без Владельца

Если хоть один пункт красный — 🛑 STOP, не пушить.

---

## Что НИКОГДА не делать

```
git push --force                                 — никогда
git push в master                                — никогда
merge PR автоматически                           — только Владелец
переводить PR из draft в ready                   — только Владелец
менять Twig-шаблоны                              — не в этом PR
менять Symfony контроллеры                       — не в этом PR
менять assets/api/, assets/controllers/          — не в этом PR
npm install / npm uninstall                      — нет
добавлять новые файлы кроме _legacy/README.md    — нет
править файлы внутри _legacy/ кроме types.ts     — нет
расширять scope                                  — STOP
```

---

## Закрытие

1. Локальные изменения зафиксированы коммитом `chore(react): quarantine legacy code to _legacy/`.
2. Ветка `chore/legacy-quarantine` запушена в `origin`.
3. Draft PR открыт.
4. 🛑 **STOP. Ждать Владельца:** ручной smoke по чек-листу из тела PR, затем перевод PR из draft в ready, затем merge.
5. После merge — отдельные задачи: подключение ESLint-границ и CI gates с двумя линтерами.

---

## Rollback

Если что-то сломалось **до push**:
```bash
git reset --hard origin/master
git branch -D chore/legacy-quarantine
```

Если сломалось **после merge** (но это решение Владельца, не автономное):
```bash
git revert <merge-commit-sha>
git push origin master
```

Атомарный PR, чистый revert вернёт всё на место без побочных эффектов.