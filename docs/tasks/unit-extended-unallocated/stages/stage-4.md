# Этап 4 — перенос «Unit — Экономика» из `_legacy/` в `modules/` (пункт 4 issue #2940)

Поручение Владельца 09.11.2026: «Перенеси страницу из _legacy в modules».

## Задача
Страница `/marketplace-analytics/unit-extended` живёт в карантине
`assets/react/_legacy/marketplace-analytics/unit-extended/` (16 файлов) и entry
`_legacy/unit-extended-page.tsx`. Правка legacy-файла по `CLAUDE.frontend.md` — задача
на миграцию; две реализации держать нельзя.

## Результат
- Фича — `assets/react/modules/marketplace-analytics/features/unit-extended/`
  (подпапка `widgets/` сохранена), entry — `assets/react/entrypoints/unit_extended_page.tsx`
  (имя Vite-entry `unit_extended_page` прежнее, Twig не меняется).
- Из `_legacy/` ничего не импортирует (гейт `import/no-restricted-paths`):
  - `formatMoney`/`formatQty` → `shared/format/number.ts`, легаси `utils.ts` берёт их оттуда;
  - `ErrorBoundary` → `shared/ui/ErrorBoundary.tsx`, все entry берут оттуда;
  - запросы — `apiFetch` (`assets/api/client.ts`, добавлен `signal` для отмены),
    вместо `httpJson`/`useAbortableQuery`; выгрузка XLS — функцией в `*.api.ts`,
    не `fetch` в компоненте;
  - `MarketplaceOption` — в типах фичи.
- Встроенные `<style>` → CSS Modules.
- Поведение и вёрстка страницы не меняются. Старые файлы удалены в том же PR.

## Что считаем выполненным
PR на handoff: lint, test:unit, build зелёные; smoke страницы локально (карточки,
таблица, фильтр периода и тегов, свод по тегам, XLS); `CLAUDE.frontend.md` — факт.

## Чек-лист приёмки
- [x] файлы фичи и entry перенесены, `_legacy/marketplace-analytics/unit-extended/` и `_legacy/unit-extended-page.tsx` удалены
- [x] `shared/format/number.ts` + vitest; `shared/ui/ErrorBoundary.tsx`
- [x] `apiFetch` с `signal`, хуки и выгрузка через него
- [x] CSS Modules вместо `<style>`
- [x] eslint, vitest, vite build
- [x] smoke: скриншоты до/после совпадают, XLS скачивается
- [x] `CLAUDE.frontend.md` (число файлов `_legacy`, модули)

## Итог
- 16 файлов фичи и entry перенесены, `_legacy/` — 72 файла (было 90). `widgets.api.ts`
  слит в `unitExtended.api.ts`: один построитель фильтра для таблицы, карточек и XLS
  (vitest), раньше их было три копии.
- Найдено по пути: стили острова теперь CSS Modules — шаблону нужен
  `vite_entry_link_tags('unit_extended_page')`, без него таблица теряла липкие колонки
  и прокрутку (поймано скриншотом, добавлено).
- Гейты: eslint, vitest 76, vite build, twigcs + lint:twig зелёные; `tsc --noEmit` — те же
  3 ошибки в `_legacy/Dashboard`, что на master.
- Smoke на локальных данных (12 листингов): скриншоты страницы, свода по тегам и
  поиска до/после совпадают побайтно, XLS скачивается, ошибок в консоли нет.
- Не менялось: вёрстка (Tabler-классы легаси-слоя), smart/dumb-разбиение крупных
  компонентов — отдельной задачей, если понадобится.
