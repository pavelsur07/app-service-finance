# Промпт: внедрение ДС «Ваш Финдир» v3.4 (оба слоя)

Работай по `AGENTS.md`, `CLAUDE.md`, `CLAUDE.frontend.md`. Это Large-задача (frontend
redesign): Phase 0 → Stages → Handoff, один вопрос Владельцу — «merge and deploy»
на каждый PR. Каждый Stage ниже — отдельный PR.

## Задача
Привести продукт к дизайн-системе v3.4. Решения Владельца, миграция токенов,
список изменений компонентов и открытые вопросы — `docs/plan/ds-v3.md` (читать
целиком перед Phase 0). Эталон:
- листы `docs/design-system/sheets/DS-*.html` + `sheets/ds-kit.css` (значения),
  скриншоты `sheets/png/*.jpg`;
- каркас приложения — `docs/design-system/screens/App-Layout.html` и лист DS-Layout;
- Tailwind — `docs/design-system/tailwind/` (только Stage 6).

Лист читается неоднозначно или не покрывает случай — ближайшее решение по
существующему коду, `TODO(ds)` в месте решения, вопрос — в отчёт Stage.

## Рамки
- Не менять: PHP-логику, роуты, имена полей форм, `name`/`id`/`data-*`, на которые
  завязаны JS и тесты; схему БД.
- **Деньги не трогать**: `vf_money` / `vf_amount` / `vf_number` и их вывод остаются
  как есть (открытый вопрос §6.1 плана).
- **Тёмную тему не включать**: из `ds-kit.css` не переносить блоки
  `[data-theme="dark"]` и `@media (prefers-color-scheme: dark)` (§1.3 плана).
- Вкладки-разделы (`.page-tabs`) — как есть, `TODO(ds)` (§6.2). Кегль легаси —
  14px (§6.3). `--vf-chart-*` — без изменений (§6.4).
- Не добавлять зависимостей, кроме Tailwind на Stage 6.
- Удаление/переименование класса кита — HIGH-LOCAL: сначала `rg` всех
  потребителей в `site/templates`, `site/assets`, `apps/`, `site/packages/`.

## Stages
- **Phase 0 (только чтение):** отчёт `docs/tasks/ds-v3/plan.md`: сверка §3–§4 плана
  с текущим кодом (значения и потребители могли измениться после 2026-11-15),
  список экранов для скриншотов до/после, риски. Продолжай без остановки.
- **Stage 1 — токены нового слоя:** `site/assets/styles/kit/tokens/*.css` по §3.1:
  значения v3.4, новые токены, алиасы `DEPRECATED`; смысловые изменения
  `--border-strong` / `--button-secondary-border` — с правкой потребителей.
- **Stage 2 — токены легаси:** `site/assets/styles/design-tokens.css` по §3.2,
  пересчитать `--tblr-*-rgb`; деструктивные действия → `--vf-danger-text`.
- **Stage 3 — шрифт:** Manrope в `_layout/legacy.html.twig`, `_layout/app.html.twig`,
  `apps/**`; убрать загрузку Inter и `--vf-feature-settings`.
- **Stage 4 — компоненты:** §4 плана: `.btn-icon`, «Поле с дополнением»
  (`.input-wrap` + `.input-icon` / `.input-action`), `.link`, удаления и слияния,
  `coverage.css` → `assets/styles/pages/`. React-обёртки (`assets/react/ui-kit/`) —
  только если меняется их класс.
- **Stage 5 — App Shell:** шапка (крошки, период, поиск, уведомления), сайдбар
  (группы, вложенные пункты с `aria-expanded`, активный — `aria-current="page"`,
  свёртывание в рейку 72px с запоминанием, пользователь + выход формой POST),
  футер; `scrollbar-gutter: stable` у сайдбара и рабочей зоны. Классы кита
  (`.app-header`, `.sb`, `.app-workzone`), визуал — `App-Layout.html`.
- **Stage 6 — Tailwind в новом слое:** установка, конфиг по
  `docs/design-system/tailwind/` (preflight off, `content` — только новый слой),
  подключение в Vite после CSS кита; проверка, что легаси-страницы не изменились
  (скриншоты до/после).

## Проверка перед handoff каждого Stage
- Гейты из `CLAUDE.frontend.md` и `make site-cs-twig`, если тронуты шаблоны. Не
  писать, что прогнано то, чего нет.
- Скриншоты до/после в 1440 по списку из Phase 0 (минимум: «Операции ДДС»,
  операция ДДС, «Юнит-экономика», дашборд, бюджет, вход).
- Контраст: текст ≥4.5:1, границы контролов ≥3:1; фокус виден на всех
  интерактивных элементах.
- Отчёт: отклонения от листов, `TODO(ds)`, ответы на открытые вопросы, если
  появились.

## Критерии готовности
`docs/design-system/CHECKLIST-ds-v3.md` — все ⛔ закрыты, остальные отмечены или
объяснены в отчёте.
