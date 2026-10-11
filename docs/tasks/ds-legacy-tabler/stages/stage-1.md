### Stage 1: токены DS поверх Tabler — DONE

**Risk:** MEDIUM · **Stage base commit:** `5702b7c1` · **Work items:** 1.1 `--vf-*`, 1.2 слой `--tblr-*`, 1.3 `legacy-app.css`

#### What was done
- `design-tokens.css`: имена `--vf-*` сохранены, значения — из DS; алиасы и
  токены вне DS помечены `deprecated`, пробелы DS — `TODO(ds)`. Новые
  семантические `--vf-danger-text`, `--vf-ok-*`, `--vf-warn-*`, `--vf-brand*`.
- Слой `--tblr-*` на `:root,[data-bs-theme=light]` (сайдбар несёт
  `data-bs-theme=light`, иначе Tabler вернул бы там свою палитру).
- `legacy-app.css`: хардкод `rgba(10,21,72,…)`, `#8a94a6`, фолбэки
  `var(--tblr-x, #hex)` → токены; локальный `:root {--vf-brand…}` перенесён в
  токены; активный пункт меню — Action blue (DS: «активное состояние»).

#### Files changed
- `site/assets/styles/design-tokens.css`, `site/assets/styles/legacy-app.css` — modified

#### Definition of Done
- [x] `--vf-*` с новыми значениями, deprecated не удалены
- [x] `--tblr-*` переопределены поверх Tabler
- [x] хардкод цветов в `legacy-app.css` — 0 (`grep -E '#[0-9a-f]{3,8}|rgba?\([0-9]'`)
- [x] контраст AA — аудит §8, все пары ≥ 4,5
- [x] скриншоты до/после: операции ДДС, новая операция, дашборд, вход

#### Checks
- `npm run lint` ✅, `npm run build` ✅
- `npm run check:ui-kit` — 9081 до и после (pre-existing, без роста)
- smoke в headless Chromium, вычисленные стили: body `rgb(11,16,32)` на
  `rgb(247,248,252)`, `.btn-primary` `rgb(29,78,216)`, `--tblr-primary` на
  сайдбаре `#1d4ed8`, `.text-success` `#1d7a30`, `.text-danger` `#b00020`,
  `--tblr-success-lt` `#e4f5e7`. Страница входа (`/login`) не изменилась: у
  неё свой `pages/login.css`.

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none.
- FOLLOW-UP (Stage 2): `.text-danger` пока брендовый `#b00020` — отрицательные
  суммы и ошибки перейдут на `--vf-danger-text`.

#### External review
- required: no (MEDIUM, diff < 500)

#### Next
- Stage 2 автоматически
