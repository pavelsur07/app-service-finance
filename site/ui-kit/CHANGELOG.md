# UI Kit — Changelog

## [2.6] — 2026-07-22
### Added
- **CARD-HEAD** (`components/card-head.{css,html}`) — заголовок секции внутри `.card` (был инлайн-стилями в демо). Вариант A — 11px uppercase, отбивка `--s-3`; вариант B — `.card--flush` для таблицы edge-to-edge. Decision 25.
- **PAGE-SCAFFOLD** (`patterns/page-scaffold.{css,html}`) — `.wz-head` / `.wz-title` / `.wz-row`: вертикальный ритм контента в `.app-workzone`, канонизирован из `assets/styles/pages/admin-shell.css`. Decision 26.
- **MODAL SIZES S/M/L/XL** — `.mdl--compact` 440 · `.mdl`/`.mdl--m` 520 · `.mdl--l` 640 · `.mdl--xl` 800. Все clamp к `min(<токен>, 100vw − --s-8)`; `.mdl-body` скроллится при `max-height: min(70vh, 640px)`. Токены `--modal-width-l` / `--modal-width-xl`. Decision 28.
- **SIDE MODAL** (`.mdl--side`) — full-height модалка, док справа: `position:fixed`, `height:100vh`, `.mdl-body` — единственная скролл-зона, slide-in `mdl-slide-in`, тень `--shadow-drawer`. Decision 29.

### Fixed
- Перекос `.form-field` в grid-контейнерах: стек-хелпер `.form-field + .form-field { margin-top }` протекал в `.row-2col` / `.row-3col` / `.form-grid` / `.demo` — первый элемент строки без margin, второй с margin 12px. Там интервал теперь даёт только `gap`. Decision 30.

### Migration notes
- Никаких breaking. Существующие `.mdl` и `.mdl--compact` сохраняют ширины 520 / 440 — добавился только clamp по вьюпорту и скролл body.
- `.wz-*` дублируются в `assets/styles/pages/admin-shell.css`; entry `admin_shell` грузится после `app`, поэтому /admin/* сохраняет текущий вид. Удаление дубля — отдельная задача.
- React-обёртки для CardHead / PageScaffold — отдельная задача.

## [2.5] — 2026-07-09
> Внесено напрямую в split, монолит дизайнера догнал в v2.6. Записано задним числом.

### Fixed
- **APP-SHELL scroll model**: root/body фиксированы (`height:100%`, `overflow:hidden`), скроллится `.app-workzone` (`overflow:auto` + `min-height:0`). `html:has(> body > .app)` / `body:has(> .app)` управляют scrollbar-gutter — header flush до края.
- `--s-8` (40px) восстановлен в шкале (используется в проде: login, app-shell). Шкала `--s-1` … `--s-8`.

## [2.4.3] — 2026-07-12
### Fixed
- Autofill preview (Chromium, до первого жеста): стили `.input` теперь применяются сразу при загрузке страницы, а не после первого клика. До жеста пароль не коммитится в DOM, `:-webkit-autofill` не матчится и box-shadow-хак бессилен — сверхдлинный `transition` на `.input` в `states.css` замораживает принудительную смену фона UA-стилями.

### Migration notes
- Никаких breaking. Цветов и классов не добавлено; переходов на `.input` в DS не было — визуальные состояния не задеты.

## [2.4.2] — 2026-07-12
### Added
- **Behavior layer**: `components/states.css` — инженерный слой (владелец — код) для поведения браузера/a11y: native pseudo-classes, autofill, focus. Подключается последним в каскаде из `assets/styles/app.css` мимо `all.css`; `uikit-update` его не регенерит. Правило зафиксировано в `CLAUDE.frontend.md` §2 и `decisions.md` (Decision 16).

### Fixed
- Браузерный autofill больше не красит `.input` жёлтым: inset-заливка `var(--input-bg)` + `-webkit-text-fill-color: var(--input-text)`; на фокусе кольцо `var(--input-focus-ring)` совмещено с заливкой в одном `box-shadow`.

### Migration notes
- Никаких breaking. Новых классов и токенов нет — только псевдоклассы на существующем `.input`.

---

текущая версия 1.2.0
