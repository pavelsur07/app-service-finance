## Current checkpoint

**Phase:** Release Gate
**Status:** done
**Stage base commit:** 45063ab91da73642ac5649e99692a85bb5adcb64
**Current Work item:** none (1.1–1.6 done)
**Owner gate:** yes

### Completed
- 1.1 `recoveryWindowStart()` в резолвере периодов + 6 unit-тестов на границы.
- 1.2 Оркестратор использует правило, help опций обновлён.
- 1.3 `planEmptyRefresh` использует то же правило по умолчанию.
- 1.4 Три теста оркестратора (пустые дни, пропущенные дни, диапазон запросов).
- 1.5 Тест планировщика на дефолтное окно.
- 1.6 Комментарий в `docker/cron/app.cron`.

### Current diff / affected files
- `site/src`: 3 файла; `site/tests`: 3 файла; `docker/cron/app.cron`;
  `docs/tasks/wb-empty-recovery-month-boundary/*`

### Checks and baseline
- baseline: целевые классы OK (59), `composer test:unit` OK, 4 pre-existing deprecations
- после: целевые OK (68, 260), unit OK (2290), integration Marketplace OK (500),
  PHPStan `[OK] No errors`, cs:check и cs:strict-types `Found 0 of 2464`

### Review status
- internal: 1 итерация — уточнена формулировка дефекта, добавлен тест на
  пропущенный день, план перенесён из `site/docs` в `docs`
- external Codex: 1 итерация — REVIEW_GREEN, находок нет

### Exact next action
- решение Владельца по Draft PR (Ready + merge с автодеплоем, либо оставить Draft)

### Files to inspect first on resume
- docs/tasks/wb-empty-recovery-month-boundary/handoff.md
- site/src/Marketplace/Application/Service/WbFinancialReportPeriodResolver.php
