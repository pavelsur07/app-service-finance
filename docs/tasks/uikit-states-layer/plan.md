# Two-layer CSS ownership — engineering behavior layer (states.css)

## Context

У `ui-kit/components/*.css` фактически два автора: дизайнер (через монолит `_incoming/ui-kit-v*.html`, раскладываемый `uikit-update.md`) и инженер (a11y/поведенческие правки — native pseudo-classes, autofill, focus). Модель источников правды в `CLAUDE.frontend.md` §2 признаёт только дизайнера: «код никогда не источник правды». Из-за этого инженерные правки поведения либо нелегальны, либо будут затёрты следующим `uikit-update`. Исходный баг-доказательство: браузерный autofill красит `.input` жёлтым, перекрывая токены DS.

Решение: легализовать **behavior layer** — файл `ui-kit/components/states.css`, которым владеет код; правило в §2 + ADR; подключение из `app.css` (вне зоны uikit-update); минимальная подстраховка в процедуре апдейта.

**Риск: 🔴 HIGH** (меняем DS-контракт). Финал — STOP, Owner review, **patch-bump v2.4.1 → v2.4.2** (решение Владельца: states.css = исправление autofill-бага, не конфликтуем с minor-линейкой дизайнера).

**Патч Владельца учтён:** (1) states.css подключается из app.css, all.css не трогаем; (2) lint-проверка выполнена до Stage 2 — блокера нет (см. «Факты разведки»).

## Non-goals

- НЕ применять fix-матрицу F1–F4 — только каркас + одно правило (autofill для `.input`).
- НЕ трогать React-обёртки (`assets/react/`), Twig, `_legacy/`, `tokens/*`, монолиты в `_incoming/`, `components/all.css`.

## Факты разведки

- `site/assets/styles/app.css` — блок из 4 `@import` (base, tokens, components/all, patterns/all) на строках 2–5, дальше идут обычные app-правила (238 строк). **Нюанс:** по спецификации CSS `@import` обязан предшествовать любым другим правилам — «последней строкой файла» импорт невалиден. Ставим последним в блоке импортов (строка 6, после `patterns/all.css`) — states.css каскадно последний среди всего ui-kit CSS.
- `--input-*` токены живут в `site/ui-kit/tokens/layout.css:63-70`: `--input-bg`, `--input-text`, `--input-border-focus`, `--input-focus-ring` — всё нужное есть, новых токенов не требуется.
- `.input:focus-visible` в `input.css:11` ставит `box-shadow: 0 0 0 3px var(--input-focus-ring)` — autofill-правило на focus должно совмещать ring + inset-заливку в одном `box-shadow`, иначе одно затрёт другое.
- **Lint-проверка (Правка 2, выполнена):** `check-uikit-react-mapping.mjs` обходит только `.html` (line 92: `walk(full, ['.html'])`) — css без html-пары невидим, правила «css без html = orphan» нет. `check-ui-kit-classes.mjs` читает `ui-kit/components/*.css` целиком как источник определений — states.css подхватится автоматически, новых классов не вводит. Блокера нет, конфиги линтеров не меняем.
- `ui-kit/decisions.md` — решения живут в «Decisions (top-15)» (нумерованный список) + «Don'ts».
- `ui-kit/CHANGELOG.md` устарел (одна строка «текущая версия 1.2.0») — добавим запись по формату §7.5, не переписывая историю задним числом.
- Текущая версия storybook: v2.4.1 (title/nav-version/page-meta); storybook подключает css напрямую (`<link>` на tokens/index.css, components/all.css, patterns/all.css), не через app.css.

## Этапы

### Stage 1 — Правило (🔴 HIGH — необратимая смена DS-контракта): CLAUDE.frontend.md §2 + ADR

1. `CLAUDE.frontend.md` §2, таблица источников правды — добавить строку:
   `| **Behavior layer** | Поведение браузера/a11y: native pseudo-classes (:disabled/:read-only/:autofill), outline-reset, focus | ui-kit/components/states.css — владелец Code |`
2. Там же, после строки «Код никогда не является источником правды…» (line 39) — дописать исключение: behavior layer (`states.css`) легитимно принадлежит коду; правило оттуда — НЕ баг lint.
3. `ui-kit/decisions.md` — пункт **16** в «Decisions (top-15)» (формат соседей — компактно):
   - контекст: у components/*.css два автора, §2 второго не признавал;
   - решение: behavior layer = третий источник правды, живёт в states.css, владелец — код, подключается из app.css мимо all.css;
   - отвергли: exclusion-костыль «uikit-update, не трогай этот import в all.css» — держится на памяти человека;
   - платим: styling примитива в двух файлах (+1 место смотреть).
   + Don't: «❌ Не регенери и не перезаписывай `states.css` при uikit-update — code-owned слой».

После self-review Stage 1 — 🛑 STOP на ревью Владельца (единственный необратимый этап).

### Stage 2 — Файл states.css + подключение (🟡 MEDIUM)

1. Создать `site/ui-kit/components/states.css`:
   - Шапка-комментарий: «engineering behavior layer · owner=code · не трогается uikit-update».
   - Только поведение, ноль хардкод-цветов — только существующие `--input-*` токены:
     ```css
     .input:-webkit-autofill,
     .input:-webkit-autofill:hover {
       -webkit-box-shadow: inset 0 0 0 1000px var(--input-bg);
       -webkit-text-fill-color: var(--input-text);
       caret-color: var(--input-text);
     }
     .input:-webkit-autofill:focus,
     .input:-webkit-autofill:focus-visible {
       border-color: var(--input-border-focus);
       -webkit-box-shadow: 0 0 0 3px var(--input-focus-ring),
                           inset 0 0 0 1000px var(--input-bg);
     }
     ```
     (точная форма может чуть уточниться; инвариант — совмещённый box-shadow ring+inset на focus).
2. `site/ui-kit/components/all.css` — **НЕ трогать**, остаётся чисто дизайнерским.
3. `site/assets/styles/app.css` — последним в блоке импортов (строка 6, сразу после `patterns/all.css`):
   ```css
   @import url('../../ui-kit/components/states.css'); /* behavior layer, cascade-last */
   ```
4. `site/ui-kit/storybook.html` — в `<head>`, после `components/all.css` (и `patterns/all.css`):
   ```html
   <link rel="stylesheet" href="components/states.css">
   ```
   — иначе autofill-fix не виден в превью storybook.

### Stage 3 — Сборка: uikit-update.md, минимальная подстраховка (🟡 MEDIUM)

1. **«Что НИКОГДА не делать»**: + строка «`states.css` — code-owned behavior layer, uikit-update его не регенерит и не перезаписывает» (файл лежит в components/ — подстраховка от случайной перезаписи при full-replace).
2. **Self-review**: + чекбокс «states.css существует и импортируется из app.css последним (не из all.css); апдейтом не изменён».
3. **Phase 4 (шаблон `<head>` storybook)**: в фиксированный список `<link>` добавить `components/states.css` (после patterns/all.css) — иначе при следующем апдейте пересборка head выкинет подключение и autofill-fix пропадёт из превью.
4. **Phase 6.1**: пометить — расхождения behavior-слоя с монолитом ОЖИДАЕМЫ (в монолите слоя нет), не флагать как регрессию.
5. Compose-шаг для all.css и full-replace-защита в all.css — **не нужны** (all.css чист, states подключён из app.css вне ui-kit-зоны).

### Stage 4 — Версия + verify + закрытие (🔴 HIGH, финал)

1. Patch-bump **v2.4.1 → v2.4.2**: `storybook.html` в 3 местах (title, nav-version, page-meta) + changelog-блок в storybook (🔧 autofill fix, 📐 behavior layer) + запись `[2.4.2]` в `ui-kit/CHANGELOG.md` по формату §7.5.
2. Verify (см. ниже).
3. `docs/tasks/ui-kit/` — task-док/handoff (states-layer), Stage Reports в stages/ по фактическому паттерну соседних задач.
4. Ветка `chore/uikit-states-layer` от master, Conventional Commits, **draft PR** + отчёт. 🛑 STOP — merge только Владелец.

## Verify

- `cd site && node tools/check-ui-kit-classes.mjs` → 0 нарушений (states.css не вводит новых классов — только псевдоклассы на `.input`; каталог сканируется целиком, конфиг не менялся — отчитаться).
- `node tools/check-uikit-react-mapping.mjs` → вывод без изменений (обходит только .html — states.css невидим, проверено в разведке).
- `cd site && npm run build` → green; в `public/build/app-*.css` правила states.css идут после всех ui-kit-правил (components и patterns).
- `grep -nE '#[0-9a-fA-F]{3,6}|rgb' site/ui-kit/components/states.css` → пусто.
- `git diff --stat site/ui-kit/components/all.css` → пусто (all.css не тронут).
- Ручной smoke Владельцем: autofill в форме логина — фон `.input` токенный, на фокусе видно кольцо; storybook-превью input-секции показывает то же.

## Затрагиваемые файлы

| Файл | Действие |
|---|---|
| `CLAUDE.frontend.md` | §2: строка в таблицу + исключение к «код не источник правды» |
| `site/ui-kit/decisions.md` | Decision #16 + Don't |
| `site/ui-kit/components/states.css` | new — behavior layer |
| `site/assets/styles/app.css` | +1 import последним в блоке импортов |
| `site/ui-kit/storybook.html` | +`<link>` states.css; версия v2.4.2 в 3 местах + changelog-блок |
| `site/ui-kit/uikit-update.md` | never-do строка, self-review чекбокс, Phase 6.1 пометка |
| `site/ui-kit/CHANGELOG.md` | запись [2.4.2] |
| `docs/tasks/ui-kit/...` | план/stage reports/handoff |

`site/ui-kit/components/all.css` — намеренно не изменяется.

## Follow-up (за scope, отдельная задача)

Переписать F1–F4 так, чтобы поведение шло в states.css, а визуальные состояния (hover/focus/active цвета) — в монолит дизайнеру.
