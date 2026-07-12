# UI Kit — Changelog

## [2.4.2] — 2026-07-12
### Added
- **Behavior layer**: `components/states.css` — инженерный слой (владелец — код) для поведения браузера/a11y: native pseudo-classes, autofill, focus. Подключается последним в каскаде из `assets/styles/app.css` мимо `all.css`; `uikit-update` его не регенерит. Правило зафиксировано в `CLAUDE.frontend.md` §2 и `decisions.md` (Decision 16).

### Fixed
- Браузерный autofill больше не красит `.input` жёлтым: inset-заливка `var(--input-bg)` + `-webkit-text-fill-color: var(--input-text)`; на фокусе кольцо `var(--input-focus-ring)` совмещено с заливкой в одном `box-shadow`.

### Migration notes
- Никаких breaking. Новых классов и токенов нет — только псевдоклассы на существующем `.input`.

---

текущая версия 1.2.0
