# Stage 1 — пакет и токены

**Статус:** реализация и локальная приёмка завершены; Stage не закрыт:
обязательный внешний Claude review недоступен. PR остаётся Draft.
**Risk:** MEDIUM.
**PR:** [#3027](https://github.com/pavelsur07/app-service-finance/pull/3027) — Draft, base master.
**Implementation commit:** `c5233de52`.
**Stage base commit:** `cd19c36b043746e6502ba2cdebb9b09616127b81`.
**Work items:** 1.1, 1.2, 1.3 (review blocked).

## Что сделано

- Phase 0: подтверждён apps/packages/ds, исследованы сборка, потребители старого
  UI, API компонентов и снимки; решения денег/иконок записаны в plan.md.
- @vashfindir/ds 3.4.0: exports `.`, `./tokens.css`, `./styles.css`.
- Точная копия светлого JSON из эталонного PR #3022; Style Dictionary 5.8.0
  нормализует слои в плоские имена, сохраняет CSS refs с --ds-, генерирует
  resolved TS и тип TokenName. Все 361 токен, dark не включён.
- Generated исходники коммитятся; `check:generated` собирает отдельно в tmp,
  проверяет воспроизводимость побайтно, чистит свой временный каталог.
- Vitest проверяет 4 слоя, значения независимого CSS-снимка, граф ссылок,
  отсутствие dark и лишних селекторов, TS-значения и exports.
- Существующие typecheck/lint/test apps включают пакет. В Vitest открыт только
  путь DS, нужный тестам; Vite runtime/fs allow приложений не менялся.

## Файлы

- `apps/packages/ds/` — package/lock, generator, source JSON, CSS/TS outputs,
  tests/fixture, strict tsconfig, README и настройки форматирования.
- `apps/{account,admin}/{package.json,tsconfig.json,vitest.config.ts}` — проверки
  нового пакета; runtime imports и manifests dependencies apps не менялись.
- `docs/tasks/ds-v3-apps/` — plan, checkpoint, отчёт, handoff.
- `site/`, production Docker, Compose, CI/CD — не изменены.

## Definition of Done

- [x] Пакет/exports и light-only.
- [x] Точное соответствие эталону и полный охват semantic/component токенов.
- [x] TDD: 8 тестов RED до генерации (ENOENT), затем 8 GREEN.
- [x] Strict typecheck/lint/test/build обоих apps и проверки пакета.
- [x] Preview E2E, снимки 1440, неизменность runtime артефактов.
- [x] Независимое internal review.
- [ ] Обязательное внешнее Claude review: отсутствует executable.
- [ ] Ready/merge/deploy: заблокировано до внешнего review и решения Владельца.

## Проверки

Baseline до кода: `make front-install` PASS (lock apps не менялся),
`APP=account ./bin/frontend.sh test` 35/35,
`APP=admin ./bin/frontend.sh test` 31/31.

Handoff gates (реальные команды apps, PHP/site гейты вне scope):

- `make front-typecheck` — PASS, оба apps и исходники пакета, strict flags.
- `make front-lint` — PASS, ESLint/Prettier, apps/DS/старые shared packages read-only.
- `make front-test` — PASS: account 43/43, admin 39/39 (по 8 DS tests).
- `make front-build` — PASS, оба apps.
- `make front-e2e MODE=preview` — PASS: account 12, admin 10;
  по 2 production-only теста штатно skipped, production acceptance не заявляется.
- В Node-контейнере, cwd apps/packages/ds: `npm ci --ignore-scripts --no-audit --no-fund`,
  `npm run build`, `npm test` (8/8), `npm run typecheck`, `npm run check:generated`
  — PASS. После перехода на SD 5.8 повторены build/check/test/typecheck; output
  CSS/TS остался побайтно прежним. Fresh install проверен также на SD 5.8.
- `cmp` source JSON с извлечённым эталоном — exact; `git diff --check` PASS;
  `git diff --stat origin/master -- site/` и git status site — пустые.
- npm audit builder package: HIGH/CRITICAL 0, 4 moderate + 2 low (транзитивные
  build-only зависимости); 4.4 заменён на 5.8 из-за HIGH GHSA-vj5c-m527-mpff.
  Существующие apps dependencies не обновлялись: install baseline сообщает
  по 8 moderate + 2 critical, отдельно от нового пакета.

Исправленные сбои проверок: default Compose UID вызвал EACCES (явный --user);
сравнение ссылок в тесте учитывает префикс --ds-; lint генератора потребовал
явный import URL; Vitest потребовал fs.allow для тестов вне app root.
Повторённые проверки прошли. Первый screenshot-run получил host 403 от
baseline Vite; разрешён только временный hostname в /tmp checkout, repo не менялся.

## Визуальная регрессия

Базовый commit собран отдельно из git archive в /tmp теми же зависимостями.
HTML/CSS/JS обоих apps **побайтно совпали** с новыми артефактами; роста бандла нет:
account JS 277959 B, admin JS 275812 B, CSS обоих 73766 B.
Открыты отдельные preview baseline и текущей версии, только синтетические
Playwright API fixtures. 30 PNG 1440×900, 15 пар SHA-256 совпали:
login, неверный пароль, главная, about, 404, session error, 403 обоих apps;
выбор компании account дополнительно. Просмотрен account home: UI baseline
сохранён. Новые значения DS ещё не применяются, контраст/Manrope/фокус новых
компонентов проверяются при подключении в следующих Stage.

Артефакты (локальные, Git ignored):
`apps/{account,admin}/test-results/ds-v3-stage-1/{before,after}/*-1440.png`,
сводка `apps/account/test-results/ds-v3-stage-1/comparison.json`.
Временные baseline preview containers остановлены/удалены.

## Internal review

Полный diff рассмотрен отдельным агентом в свежем контексте (только spec,
diff и checklist), затем focused review обновления SD 5.8/lock. Две проверки,
BLOCKER/IMPORTANT/MINOR: 0/0/0. Дополнительно независимый reviewer проверил
admin typecheck/lint без монтирования account и admin Vitest 39/39.
FOLLOW-UP: включение генерации/проверки в production build — Stage 2.

## External review — BLOCKED

Preflight (review rounds: 0, reviewer не запустился):

```sh
site/bin/external-review.sh cd19c36b043746e6502ba2cdebb9b09616127b81 \
  --effort medium --out /tmp/vf-ds-v3-review/external \
  --context /tmp/vf-ds-v3-review/context.patch
```

Exit 2: `ревьюер 'claude' не установлен`.
Проверены PATH и стандартные install locations, исполняемый файл не найден.
Повтор без установки reviewer не исправит причину; REVIEW_GREEN не заявляется.
Штатный script не включает apps: даже после появления claude нужно передать
полный apps diff в read-only review напрямую или адаптировать wrapper вне site;
пустой штатный diff возвращает формальный green и не является проверкой.
`site/` ради исправления wrapper менять нельзя.

Review metrics: internal BLOCKER 0 / IMPORTANT 0; external не состоялся,
BLOCKER/IMPORTANT не оценивались, rounds 0 (один failed preflight).

## Риски, отклонения, следующее действие

- Реализация Stage 1 не подключена к runtime; styles.css содержит только tokens.
  Старый CSS UI, money formatter, shell принимаются в соответствующих Stage,
  глобальный CHECKLIST-ds-v3 ещё не закрыт. Новых TODO(ds) на Stage 1 нет.
- Эталон PR #3022 ещё не в master; provenance source и fixture записан в README.
- Stage 2 требует отдельного §3.3 одобрения production Docker/CI changes.
  Выявлен CI spa filter без apps/packages/** — включить вместе с интеграцией
  нового пакета; в этом Stage CI срабатывает через изменённые файлы обоих apps.
- Продолжение: восстановить доступ к Claude Code, провести реальное read-only
  review полного diff, исправить находки; затем Ready и единственный запрос
  merge+deploy для Stage 1. Stage 2–5 не начинались, merge/deploy не выполнялись.
