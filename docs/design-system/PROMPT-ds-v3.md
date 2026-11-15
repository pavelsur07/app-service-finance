# Промпт: ДС «Ваш Финдир» v3.4 в apps (account, admin)

Работай по `AGENTS.md`, `CLAUDE.md`, `CLAUDE.frontend.md` (раздел про `apps/`) и
`apps/README.md`. Это Large-задача: Phase 0 → Stages → Handoff, один вопрос
Владельцу — «merge and deploy» на каждый PR. Каждый Stage ниже — отдельный PR.

## Задача
Внедрить дизайн-систему v3.4 в `apps/account` и `apps/admin` через новый пакет
`apps/packages/ds` (`@vashfindir/ds`). Решения, структура пакета, компоненты,
этапы и открытые вопросы — `docs/plan/ds-v3.md` (прочитать целиком). Эталон:
- листы `docs/design-system/v3/sheets/DS-*.html` + `ds-kit.css`, скриншоты `sheets/png/`;
- токены `docs/design-system/v3/tokens/ds-v3.tokens.json` (W3C Design Tokens);
- каркас `docs/design-system/v3/screens/App-Layout.html`;
- Tailwind v4 — `docs/design-system/v3/tailwind/app.css`.

Лист не покрывает случай — ближайшее решение из существующих компонентов,
`TODO(ds)` в месте решения, вопрос — в отчёт. Компонент из §3.2 плана без листа —
не придумывать дизайн: минимальная версия на существующих компонентах + `TODO(ds)`.

## Рамки
- **`site/` не трогать вообще**: шаблоны, `site/assets/**`, `site/packages/**`,
  Vite `site`, листы `docs/design-system/sheets/*.dc.html`. Проверка — `git diff --stat site/` пуст.
- Тёмную тему не включать (§1.5 плана).
- Деньги: до решения Владельца — неразрывный пробел U+00A0, минус U+2212, расход
  обычным цветом, `TODO(ds)` (§4.1).
- Новые зависимости — только для пакета и apps: `tailwindcss`, `@tailwindcss/vite`,
  `style-dictionary`, набор иконок (§4.2). Lock-файлы обновлять внутри
  Node-контейнера (`apps/README.md`).
- Строгий TypeScript apps (`exactOptionalPropertyTypes`, `noUncheckedIndexedAccess`)
  распространяется на пакет.

## Stages
- **Phase 0 (только чтение):** отчёт `docs/tasks/ds-v3-apps/plan.md`: как apps
  собираются в прод-образ (подтвердить `apps/packages/ds` или предложить другое
  место), что из `@vashfindir/ui` используют apps, API компонентов §3.1 по листам,
  список экранов для скриншотов. Продолжай без остановки.
- **Stage 1 — пакет и токены** (§5.1).
- **Stage 2 — Tailwind v4 и стили** (§5.2).
- **Stage 3 — базовые компоненты** (§5.3).
- **Stage 4 — каркас** (§5.4).
- **Stage 5 — данные и оверлеи** (§5.5).

## Проверка перед handoff каждого Stage
- `make front-typecheck front-lint front-test front-build`; e2e
  `make front-e2e MODE=preview` для затронутых экранов. Не писать, что прогнано
  то, чего нет.
- Компоненту — Vitest-тест: рендер, состояния, клавиатура, a11y-атрибуты.
- Скриншоты экранов apps в 1440 до/после; сверка с листами.
- Контраст: текст ≥4.5:1, границы контролов ≥3:1; фокус виден везде.
- Отчёт: отклонения от листов, `TODO(ds)`, статус вопросов §4 плана.

## Критерии готовности
`docs/design-system/CHECKLIST-ds-v3.md` — все ⛔ закрыты, остальные отмечены или
объяснены в отчёте.
