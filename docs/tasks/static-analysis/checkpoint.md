## Current checkpoint

**Phase:** Stage 1 — Release Gate
**Status:** done
**Stage base commit:** `015d92e088b71050a8b89fc6d26fb8b2f64b3caf`
**Current Work item:** none — 1.1…1.5 завершены
**Owner gate:** yes

### Completed
- 1.1 — `phpstan/phpstan ^2.2` в require-dev; `require.php: "^8.4"`, `config.platform.php: 8.4.24`.
- 1.2 — `site/phpstan.dist.neon` (level 8, src+tests, phpVersion 80400, tmpDir var/cache/phpstan, reportUnmatchedIgnoredErrors: true); composer-скрипты `stan`, `stan:baseline`.
- 1.3 — `site/phpstan-baseline.neon`: 3777 ошибок в 2412 записях, 14473 строки.
- 1.4 — `make site-stan`, `make site-stan-baseline`; `CLAUDE.md` обновлён.
- 1.5 — CI-job `🔬 Static analysis` в `.github/workflows/deploy.yml`.

### Current diff / affected files
- `Makefile` — 2 таргета
- `CLAUDE.md` — раздел «Красный baseline»
- `.github/workflows/deploy.yml` — новый job `stan`
- `site/composer.json`, `site/composer.lock`, `site/symfony.lock`, `site/.gitignore` — зависимость и рецепт Flex
- `site/phpstan.dist.neon`, `site/phpstan-baseline.neon` — новые
- `docs/tasks/static-analysis/{plan,checkpoint}.md`, `stages/stage-1.md` — новые

### Checks and baseline
- `composer stan` — exit 0, `[OK] No errors`
- негативная проба (`?DateTimeImmutable->format()` в новом файле) — exit 1, `method.nonObject`
- `composer stan -- --error-format=github` — exit 0 (формат для CI валиден)
- `composer cs:strict-types` — `Found 0 of 2345`, exit 0 (baseline был зелёный)
- `composer test:unit` — 1927 тестов OK, 4 deprecations (pre-existing)
- `composer validate --strict` — exit 1 из-за pre-existing constraints openspout/phpspreadsheet, к Stage не относится
- `cs:check` (хронически красный, 506/2342) неприменим

### Review status
- iteration: внутреннее — 1, внешнее — 3, итог **REVIEW_GREEN**
- подтверждено и исправлено: 3 IMPORTANT + 7 MINOR; отклонённых находок нет
- unresolved findings: —

### Exact next action
Решение Владельца: начинать Stage 2 или остановиться на Stage 1.

### Files to inspect first on resume
- `site/phpstan.dist.neon`
- `.github/workflows/deploy.yml` (job `stan`)
- `docs/tasks/static-analysis/plan.md`
