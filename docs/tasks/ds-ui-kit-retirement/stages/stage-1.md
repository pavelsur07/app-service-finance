### Stage 1: перенос живого CSS кита в `site/assets/styles/kit/` — DONE

План — `docs/plan/ds-ui-kit-retirement.md`; одобрение Владельца на удаление
`site/ui-kit/` и двух гейтов получено 2026-10-11.

#### Что сделано
- `git mv` 56 CSS-файлов (`base.css`, `tokens/`, `components/*.css`, `patterns/*.css`)
  из `site/ui-kit/` в `site/assets/styles/kit/` с сохранением структуры; импорты внутри
  кита только соседние (`./…`) — не менялись.
- `assets/styles/app.css`, `assets/styles/pages/marketplace-coverage.css` — новые пути `@import`.

#### Проверки
- `npm run lint` ✅, `npm run build` ✅.
- **Хеши всех CSS-бандлов до и после совпадают байт в байт** (`app`, `marketplace_coverage`,
  `admin_shell`, `login`, `design_tokens`, `vf_custom_classes`, `legacy_app` — и имена файлов).
- Скриншоты до/после байт в байт: регистрация, админка, «Покрытие» маркетплейсов,
  главная и операции ДДС в новом интерфейсе.
- Гейты `check:ui-kit` / `check:uikit-react-mapping` после переноса не находят CSS
  кита — они удаляются на этапе 2 (в CI не входят, красные до задачи).
