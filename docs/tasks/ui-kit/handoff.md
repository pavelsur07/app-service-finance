# UI Kit Split — Handoff

**Ветка:** `chore/uikit-split`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2053 (draft)
**Дата:** 2026-06-27

---

## Summary всех этапов

| Фаза | Что сделано | Риск |
|---|---|---|
| **Phase 1 — Токены** | Создан `site/ui-kit/tokens/` с 7 файлами + `index.css` | 🟢 LOW |
| **Phase 2 — Компоненты** | Создан `site/ui-kit/components/` с 15 парами `*.css + *.html` + `all.css` | 🟢 LOW |
| **Phase 3 — Паттерны** | Создан `site/ui-kit/patterns/` с 8 парами `*.css + *.html` + `all.css` | 🟢 LOW |
| **Phase 4 — Shell** | `storybook.html` переписан: 3 `<link>` в `<head>`, `<style>` — только storybook-layout | 🟡 MEDIUM |
| **Phase 5 — app.css** | Добавлены 3 `@import` в начало `site/assets/styles/app.css` | 🟡 MEDIUM |
| **Phase 6 — Verify** | Vite: 102 модуля трансформированы; 307 UI Kit классов найдено; react-mapping — 23 ожидаемых `ref-no-react-mapping` | — |

---

## Затронутые файлы

**Новые файлы (57):**

```
site/ui-kit/tokens/
  colors.css, typography.css, spacing.css, radius.css,
  shadows.css, layout.css, semantic.css, index.css

site/ui-kit/components/
  button.{css,html}, input.{css,html}, badge.{css,html},
  status.{css,html}, avatar.{css,html}, toggle.{css,html},
  table.{css,html}, kpi.{css,html}, menu.{css,html},
  tabs.{css,html}, toast.{css,html}, alert.{css,html},
  confirm.{css,html}, money.{css,html}, direction.{css,html},
  all.css

site/ui-kit/patterns/
  sidebar.{css,html}, picker.{css,html}, tags.{css,html},
  report.{css,html}, drawer.{css,html}, modal.{css,html},
  empty-state.{css,html}, acc-card.{css,html},
  all.css
```

**Изменённые файлы (2):**

```
site/ui-kit/storybook.html   — shell: link-tags в <head>, <style> — только layout
site/assets/styles/app.css   — добавлены @import токенов/компонентов/паттернов
```

---

## Список миграций

Миграций БД нет — задача касается только статических CSS/HTML файлов.

---

## Список изменённых публичных контрактов

**Нет.** Ни один CSS-класс не переименован, ни один токен не изменён, ни одна разметка компонентов в storybook'е не тронута.

---

## Verification

| Проверка | Результат |
|---|---|
| Vite CSS resolution | ✅ `102 modules transformed` (EACCES на `public/build` — pre-existing, не наш) |
| `check-ui-kit-classes.mjs` | ✅ 307 классов найдено (больше чем до разбора) |
| `check-uikit-react-mapping.mjs` | ⚠️ 23 нарушения `ref-no-react-mapping` — **ожидаемо**, React-обёртки вне scope |
| Токены не переименованы | ✅ |
| Классы не переименованы | ✅ |
| Body storybook.html не изменён | ✅ |
| Backup `storybook.html.bak` удалён | ✅ |

**Примечание про build EACCES:** `public/build/.vite` принадлежит `root` (создан в другом контексте). Это pre-existing проблема окружения, не связана с нашими изменениями — 102 модуля трансформировались успешно до ошибки записи.

---

## Риски

1. **storybook.html в браузере**: стили теперь грузятся из отдельных файлов через `<link>`. При открытии `storybook.html` как локального файла (`file://`) браузер может заблокировать `@import` цепочки в CSS из-за CORS. Нужно открывать через HTTP-сервер (как и раньше).

2. **app.css в продакшне**: `@import url(...)` цепочки в app.css добавляют 3 новых entry-файла для Vite. Если app.css не подключён в Twig base layout, эффекта нет — это следующий PR.

3. **Ordering в tokens/index.css**: `semantic.css` импортируется последним (зависит от всех raw-токенов) — порядок соблюдён.

---

## Follow-ups (вынесены за scope, следующие PR)

1. **React-обёртки**: создать `assets/react/ui-kit/<Name>/<Name>.tsx` для 23 компонентов (список из `check-uikit-react-mapping.mjs`). Приоритет: Button, Input, Status, Badge, Money.

2. **Twig base layout**: подключить `app.css` в `templates/_layout/` чтобы UI Kit стили применились в проде.

3. **Визуальный регресс в CI**: добавить Playwright-снимки storybook-секций в CI pipeline для автоматической проверки при будущих правках.

4. **Остаток legacy**: 9275 нарушений `check-ui-kit-classes.mjs` в `templates/` — Tabler/Bootstrap классы, требуют отдельной миграционной задачи.
