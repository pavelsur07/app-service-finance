## Current checkpoint

**Phase:** Stage 2 — Release Gate
**Status:** done
**Stage base commit:** `d902c511deb2d8867d58419645b798b417e0f329`
**Current Work item:** none — 2.1…2.5 завершены
**Owner gate:** yes

### Completed
**Stage 1 — DONE, смёржен в ветку, CI зелёный, PR #2386 (Draft).**
Гейт PHPStan level 8, baseline 3777, CI-job `🔬 Static analysis` (1m20s, pass).

**Stage 2:**
- 2.1 — `phpstan/extension-installer`, `phpstan-symfony`, `phpstan-doctrine`,
  `phpstan-phpunit`, `phpstan-webmozart-assert` в require-dev.
- 2.2 — `site/tests/object-manager.php`: окружение `test`, 117 маппингов, без БД.
- 2.3 — `symfony.containerXmlPath` (test-контейнер) и `doctrine.objectManagerLoader`.
- 2.4 — `site-stan-prepare` как предусловие целей Makefile; шаг прогрева в CI-job.
- 2.5 — baseline 3777 → 3525 (2358 записей, 1428 `src` / 930 `tests`), дельта разобрана.

### Current diff / affected files
- `site/composer.json`, `site/composer.lock` — 5 dev-зависимостей
- `site/tests/object-manager.php` — новый
- `site/phpstan.dist.neon`, `site/phpstan-baseline.neon`
- `Makefile`, `.github/workflows/deploy.yml`, `CLAUDE.md`
- `docs/tasks/static-analysis/{plan,checkpoint}.md`, `stages/stage-2.md`

### Checks and baseline
- `composer stan` — exit 0, `[OK] No errors`
- проба `find()->definitelyNotAMethod()` — exit 1, тип разрешён до `MoneyAccount|null`
- без прогретого контейнера — exit 1, явный `hash_file(...): No such file or directory`
- `composer cs:strict-types` — `Found 0 of 2346`, exit 0
- `composer cs:check` — `Found 0 of 2346`, exit 0
- `composer test:unit` — 1927 OK, 4 deprecations (pre-existing)

### Review status
- iteration: внутреннее — 1, внешнее — 2, итог **REVIEW_GREEN**
- подтверждено и исправлено: 1 IMPORTANT + 5 MINOR; отклонённых находок нет
- unresolved findings: нет

### Exact next action
Решение Владельца: начинать Stage 3 (PHPat, границы модулей) или остановиться.

### Files to inspect first on resume
- `site/phpstan.dist.neon` (блоки `symfony` и `doctrine`)
- `site/tests/object-manager.php`
- `docs/tasks/static-analysis/stages/stage-2.md`
