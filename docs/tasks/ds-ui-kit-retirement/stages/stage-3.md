### Stage 3: правила и документация — DONE

Решения Владельца 2026-10-11: эталон визуала обоих интерфейсов — листы DS
«Ваш Финдир»; CSS нового слоя — реализация; замена `check:ui-kit` пока не ставится.

#### Что поправлено
- `CLAUDE.frontend.md`: «Источники правды» — листы DS для обоих интерфейсов, таблица
  реализаций (легаси: `design-tokens.css`, `vf-custom-classes.css`, фильтры `vf_*`,
  partials; новый слой: `assets/styles/kit/`); раздел «UI Kit» → «CSS нового слоя»
  без CHANGELOG/storybook/`@uiKit`/`@react`/semver; гейты — только `lint`, `build`,
  `api:types:check`; «Связанные документы» — листы DS и аудит.
- `CLAUDE.md` (§ Design System, ссылка на фронт), `AGENTS.md` (таблица документов).
- `docs/workflow/stage-report-frontend.md`: команды и раздел «Дизайн-система» вместо «UI Kit».
- `docs/workflow/templates.md`: фронтовый Stage без удалённых гейтов и CHANGELOG.
- `screen-intake.md`, `site/screens/README.md`: эталон — листы DS, классы — CSS кита в
  `assets/styles/kit/`, без версий кита.
- `docs/design-system/README.md`: эталон для обоих интерфейсов.
- `site/screens/screen-intake.md` (второй регламент — макет → Twig + CSS; найден
  внутренним ревью): preflight требовал удалённый `check-ui-kit-classes.mjs` и вставал
  на каждом запуске — теперь проверяет CSS кита; сверка классов — `rg` по
  `assets/styles/kit/{components,patterns}`; шаблон PR и self-review без старого гейта.
- Оговорка (внутреннее ревью): новый слой пока на палитре кита, перевод на DS —
  отдельная задача; в других задачах токены кита под листы не подгонять (`CLAUDE.frontend.md`,
  README DS). Правило денег легаси — в `CLAUDE.frontend.md`.

Исторические `docs/tasks/*`, `docs/migration/*`, `docs/plan/*` не правились — это история.
