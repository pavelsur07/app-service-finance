# UI Kit Update v2.4.3 → v2.6

**Date:** 2026-07-22
**Branch:** chore/uikit-update-v2.6
**Mode:** diff (`_incoming/ui-kit-v2.4.1.html` → `_incoming/ui-kit-v2.6.html`)
**Source:** `site/ui-kit/_incoming/ui-kit-v2.6.html`
**Diff archive:** `site/ui-kit/_audit/update-2026-07-22/storybook-v2.4.1-to-v2.6.diff` (573 строки)

## Про несовпадение баз

Прод-split был на **v2.4.3**, последний монолит в `_incoming/` — на **v2.4.1**. Правки v2.4.2 / v2.4.3
(`components/states.css`, behavior layer) вносились напрямую в код и в монолит дизайнера не попадали —
это штатно (owner=code, Decision 16). Правки v2.5 (модель скролла app-shell, `--s-8`) вносились напрямую
в split и **догнаны** дизайнером в v2.6.

Обязательная сверка прод-split ↔ монолит (Pre-flight §7) пройдена:

| Прямая правка в split | В прод-split | В монолите v2.6 | Итог |
|---|---|---|---|
| `html:has` / `body:has` scroll model | ✅ 2 | ✅ 3 | монолит догнал |
| `--s-8: 40px` | ✅ | ✅ | монолит догнал |
| `components/states.css` | ✅ | ❌ (ожидаемо) | owner=code, не трогали |

Затирания прямых правок нет. Хунки v2.5 из диффа — no-op: split уже содержит их дословно.

## Classification

| # | Hunk | Class | Description | Target file |
|---|---|---|---|---|
| 1 | L3-19 | 🟢 storybook-edit | `<title>` v2.4.1 → v2.6 | `storybook.html` |
| 2 | L20-32 | 🟢 token-add | `--modal-width-l: 640px`, `--modal-width-xl: 800px` + комментарии S/M/L/XL | `tokens/layout.css` |
| 3 | L33-52 | 🔧 component-edit | сброс стек-хелпера `.form-field + .form-field` в gap-контейнерах (Decision 30) | `components/form-field.css` |
| 4 | L53-75 | 🟡 variant-add | `.mdl` clamp `min(токен, 100vw − --s-8)`, `.mdl--m/--l/--xl`, `.mdl-body` скролл `min(70vh, 640px)` | `patterns/modal.css` |
| 5 | L77-100 | 🟡 variant-add | `.mdl--side` + `@keyframes mdl-slide-in` | `patterns/modal.css` |
| 6 | L104-134 | 🟡 component-add | маркер `CARD-HEAD`: `.card-head`, `.card-head-aside`, `.card--flush` | `components/card-head.{css,html}` |
| 7 | L138-178 | ⚪ no-op | app-shell scroll model (v2.5) — уже в split дословно | `patterns/app-shell.css` (без изменений) |
| 8 | L179-205 | 🟡 pattern-add | маркер `PAGE-SCAFFOLD`: `.wz-head`, `.wz-title`, `.wz-sub`, `.wz-head-actions`, `.wz-row` | `patterns/page-scaffold.{css,html}` |
| 9 | L208-250 | 🟢 storybook-edit | nav-якоря `#card-head` / `#modal-sizes` / `#side-modal` / `#page-scaffold`, `.nav-version`, `.page-meta` | `storybook.html` (body) |
| 10 | L251-305 | 🟢 storybook-edit | Decisions 25–30 в секции `#decisions` | `storybook.html` (body) |
| 11 | L306-321 | ⚪ no-op | `--s-8` в демо шкалы spacing (v2.5) — токен уже в split | `storybook.html` (body) |
| 12 | L323-358 | 🟢 storybook-edit + demo | демо Card переведено с инлайн-стилей на `.card-head`, добавлен вариант B | `components/card.html`, `storybook.html` |
| 13 | L359-465 | 🟢 demo-add | демо Modal L / XL / side | `patterns/modal.html`, `storybook.html` |
| 14 | L467-511 | 🟢 demo-add | демо Page scaffold | `patterns/page-scaffold.html`, `storybook.html` |
| 15 | L515-523 | 🧹 cleanup | снят cloudflare `email-protection` враппер вокруг демо-адреса | `storybook.html` (body) |
| 16 | L524-555 | 🟢 storybook-edit | changelog-блоки v2.6 и v2.5 | `storybook.html` (body) |
| 17 | L556-564 | 🟢 storybook-edit | правка исторической записи v2.1 (`--s-8` больше не «удалён») | `storybook.html` (body) |
| 18 | L565-573 | 🧹 cleanup | снят `cloudflare-static/email-decode.min.js` | `storybook.html` (body) |

Total: 18 hunks. 🔴 breaking — **нет**.

## Version bump

v2.4.3 → v2.6 (**minor**)

Reason: наибольший вес — 🟡 component-add (CARD-HEAD) и pattern-add (PAGE-SCAFFOLD).
Пропуск v2.5 в split-нумерации намеренный: изменения v2.5 уже жили в проде (см. «Про несовпадение баз»),
задним числом записаны в `CHANGELOG.md` отдельной секцией.

## New components

| Component | Files | React wrapper status |
|---|---|---|
| CardHead | `components/card-head.{html,css}` + запись в `components/all.css` | ⚠️ TODO — отдельная задача |
| PageScaffold | `patterns/page-scaffold.{html,css}` + запись в `patterns/all.css` | ⚠️ TODO — отдельная задача |

`card-head.css` импортируется **после** `card.css`: `.card--flush` и `.card` имеют равную специфичность,
порядок в `all.css` решает, кто выиграет `padding`. Комментарий в `all.css` это фиксирует —
«починка» порядка на строго алфавитный сломает вариант B.

## New tokens

| Token | File | Value |
|---|---|---|
| `--modal-width-l` | `tokens/layout.css` | `640px` |
| `--modal-width-xl` | `tokens/layout.css` | `800px` |

Удалённых токенов нет.

## New variants

| Class | File | Note |
|---|---|---|
| `.mdl--m` | `patterns/modal.css` | явный алиас дефолтных 520 |
| `.mdl--l` / `.mdl--xl` | `patterns/modal.css` | 640 / 800, только для контента (Decision 27) |
| `.mdl--side` | `patterns/modal.css` | full-height sheet справа |
| `.card--flush` | `components/card-head.css` | таблица edge-to-edge |

## Sections expected to differ visually

- **Card** — демо переведено на `.card-head`, добавлен вариант B (`.card--flush` + таблица). Ожидаемо.
- **Modal** — добавлены демо L / XL / side; у существующих S/M появился clamp по вьюпорту
  и скролл `.mdl-body` при `max-height: min(70vh, 640px)`. На десктопе ≥ 840px ширины визуально идентично.
- **Page scaffold** — новая секция в 05 Patterns.
- **Decisions** — добавлены пункты 25–30.
- **Changelog** — добавлены блоки v2.6 и v2.5.
- **Формы в grid-строках** (`.row-2col` / `.row-3col` / `.form-grid`) — правая колонка больше не съезжает
  вниз на 12px. Это и есть багфикс Decision 30, во всех демо с двухколоночными формами.
- **Behavior layer** (`components/states.css`): расхождения storybook ↔ монолит по autofill / focus /
  native pseudo-classes — ожидаемы, в монолите этого слоя нет и не будет. Не регрессия.

## Verification

- [ ] Скриншоты — ручная проверка Владельцем при review PR (`_audit/update-2026-07-22/{before,after}/` созданы как маркер)
- [x] `check-ui-kit-classes`: 8973 → **8962** нарушений (−11: новые классы кита стали известны чекеру). Ноль нарушений внутри `ui-kit/`; остаток — легаси-Tabler классы в шаблонах, разбирается отдельно.
- [x] `check-uikit-react-mapping`: `ref-broken-react-ref` 40 → **42** (+2 = CardHead, PageScaffold — ожидаемо, обёртки отдельной задачей). `ref-no-react-mapping` без изменений: 5.
- [x] `vite build` — green, 117 модулей, `app-*.css` 71 271 B. Новые классы в бандле проверены поштучно
      (`mdl--xl`, `mdl--side`, `card-head`, `card--flush`, `wz-head`, `wz-row`, `--modal-width-l`, `mdl-slide-in`),
      порядок каскада `.card` → `.card--flush` подтверждён на собранном CSS.
- [x] `storybook.html` — shell: `<head>` + storybook-CSS без единого `:root` / правила компонента;
      `<body>` целиком из монолита v2.6. Shell-CSS в монолите и в проде побайтово совпал — не трогали.
- [x] Версия обновлена в трёх местах: `<title>`, `.nav-version`, `.page-meta`.

> ⚠️ `npm run build` в этой среде падает на `EACCES` при очистке `site/public/build/.vite` —
> каталог принадлежит `root` (наследие docker-сборки), к апдейту отношения не имеет.
> Проверка выполнена тем же `vite build` с изолированным `--outDir`.

## Follow-ups

1. **React-обёртки** для `CardHead` и `PageScaffold` (по образцу `uikit-button-wrapper.md`).
2. **Дубль `.wz-*`** в `assets/styles/pages/admin-shell.css` — теперь канонизирован в ките.
   Регрессии нет: entry `admin_shell` грузится после `app` (`templates/admin/base.html.twig`), админка
   выигрывает каскад своими правилами. Различия при удалении дубля учесть: у кита `.wz-head`
   `align-items: flex-start` (у админки `center` + `justify-content: space-between`), у `.wz-title`
   `letter-spacing: -0.02em` вместо `line-height: 1.2`.
3. **`decisions.md`** отстаёт: секция «Decisions (top-16)», в монолите уже 30. Отставание не этого
   апдейта — на входе было 16 против 24. Синк — отдельная задача.
4. **`ui-kit/README.md`** заявляет v1.1 при ките v2.6 — почистить вместе с п. 3.
5. **`uikit.css`** — дизайнер отметил в changelog v2.6, что файл отстаёт на v2.3. Такого файла в репозитории
   нет (`find . -name 'uikit*.css'` пусто): прод собирается из split `tokens/` + `components/` + `patterns/`.
   Синхронизировать нечего — уточнить у дизайнера, что за артефакт имеется в виду.
