### Stage 3: общие партиалы и подписи — PLANNED

**Risk:** LOW–MEDIUM (разметка 65+17+10 мест меняется на include/embed; визуал не меняется)
**Stage base commit:** master после закрывающего PR Stage 2
**Work items:** 3.0–3.4 (`plan.md`, раздел Stage 3)

#### Задача
Убрать дубли общей разметки легаси-shell'а: flash, пустое состояние, шапка страницы — по одному
партиалу на каждый; словари подписей `{% set …Labels %}` заменить на `label()` Enum или поле DTO.
Поведение и визуал 1:1, новых CSS-классов нет (`CLAUDE.frontend.md`).

#### Результат
- Flash рендерят ровно три места: `partials/_flash` (легаси), `admin/_toasts` (без изменений),
  `app/_ui/_flash` (кит); один маппинг `label → variant`; inline-копии `app.flashes` удалены.
- `partials/_empty.html.twig` (icon, title, hint, action) вместо 17 копий `class="empty"`.
- `{% embed 'partials/_page_header.html.twig' %}` (блоки `title`, `pretitle`, `actions`) вместо 65 копий
  `page-header` — та же Tabler-разметка, переход на DS `vf-page-head` — Stage 6.
- Словари подписей → `label()` Enum / поле DTO; первыми `settlement/layout` и `document/_list_layout`.

#### Замер «до» (07.11.2026, master после PR I)
- inline `app.flashes` вне трёх разрешённых мест — 10 файлов: `catalog/product/import`,
  `company/company_member/index`, `telegram/admin/bot/index`, `telegram/integration/index`,
  `marketplace_ads/index`, `balance/layout`, `inventory/layout`, `marketplace/layout`,
  `marketplace_analytics/cost_mappings/index`, `api/settings/permissions`
  (план называл 11 с `settlement/layout` — там `app.flashes` уже нет).
- `class="empty"` — 17 файлов; `class="page-header` — 65 файлов;
  `{% set …Labels %}` — 16 вхождений в 11 файлах (план: 13; добавились `admin/ai/company`,
  `report/cashflow`, `report/account_balances`, `admin/users/index`, `cash_transaction_auto_rule/*`).

#### Разбиение на PR
- PR A (3.0 + 3.1): тест трёх shell'ов на одинаковые классы flash по label; `partials/_flash` как
  единственный рендер легаси, 10 inline-копий удалены, JS flash — в `assets/legacy-app.js`.
- PR B (3.2): `partials/_empty`, 17 замен.
- PR C (3.3): `partials/_page_header` embed, 65 замен — порциями по модулям, если дифф > 1500 строк.
- PR D (3.4): подписи из Enum/DTO — сначала `settlement/layout`, `document/_list_layout`, затем остальные;
  Enum без `label()` получает его в том же PR с unit-тестом.

#### Чек-лист приёмки
- [ ] 3.0 — функциональный тест: три shell'а рендерят flash каждого label одинаковыми классами
- [ ] 3.1 — `grep -rl app.flashes templates` → только `partials/_flash`, `admin/_toasts`, `app/_ui/_flash`
- [ ] 3.2 — `grep -rl 'class="empty"' templates` → только `partials/_empty`
- [ ] 3.3 — `grep -rl 'class="page-header' templates` → только `partials/_page_header`
- [ ] 3.4 — `grep -rn '{% set [a-zA-Z]*Labels' templates` → 0
- [ ] Каждый PR: `make site-cs-twig`, `make site-test` (crawler-тесты видят flash и заголовки),
      внешнее ревью, стенд-скриншот не требуется (разметка побайтно та же, кроме include)

#### Reviewer focus
- Одинаковые классы flash для одинакового label во всех 12 местах; никаких новых CSS-классов.
- `embed` шапки: блок `actions` пустой → кнопок нет, лишнего `<div class="col-auto">` не остаётся.
