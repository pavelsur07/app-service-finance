## Current checkpoint

**Phase:** Stage 4 — Release Gate
**Status:** done
**Stage base commit:** `cf1ef467cda672362086e7738ba8deb822485346`
**Current Work item:** none — 4.1…4.4 завершены
**Owner gate:** yes

### Completed
**Stage 1 — DONE**, `d902c511`, CI зелёный. Гейт PHPStan level 8, baseline 3777.
**Stage 2 — DONE**, `53f31e25`, CI зелёный. Расширения Symfony/Doctrine/PHPUnit/
webmozart, baseline 3525.
**Stage 3 — DONE**, `cf1ef467`, CI зелёный («🔬 Static analysis» 1m55s).
PHPat: границы модулей и заморозка легаси-зоны, baseline 3643.

**Stage 4 (не закоммичен):**
- 4.1 — `spaze/phpstan-disallowed-calls`: dump/dd/var_dump/print_r/die/exit.
  Записей в baseline нет — замер до внедрения дал 0 вхождений.
- 4.2 — `site/tests/PHPStan/Rules/RepositoryCompanyScopeRule.php` + unit-тест
  (7 тестов, 10 негативных сценариев). 51 нарушение в baseline: правило применяется только к
  репозиториям, чья сущность принадлежит компании (в т.ч. транзитивно на один
  уровень). Ограничением считается только явный ненулевой параметр
  `string $companyId` / объектный `$company`; всё прочее объявляется тегом
  `@companyScopeExempt <причина>`. Известные ограничения эвристики перечислены
  в докблоке правила и в `CLAUDE.md`.
- 4.3 — `site/bin/phpstan-baseline-guard.sh` и шаг «📉 Baseline не должен расти»
  в CI-job `stan`, первым шагом после checkout.
- 4.4 — baseline 3643 → 3694 (+51), записей 2467 → 2518.

### Current diff / affected files
- `CLAUDE.md`, `docs/tasks/static-analysis/{plan,checkpoint}.md`, `stages/stage-4.md`
- `site/composer.json`, `site/composer.lock`
- `site/tests/PHPStan/Rules/RepositoryCompanyScopeRule.php`
- `site/tests/Unit/PHPStan/RepositoryCompanyScopeRuleTest.php`
- `site/tests/Unit/PHPStan/data/**` (фикстуры теста, из анализа исключены)
- `site/bin/phpstan-baseline-guard.sh`
- `site/phpstan.dist.neon`, `site/phpstan-baseline.neon`
- `.github/workflows/deploy.yml`

### Checks and baseline
- `composer stan` — exit 0
- пробы: `dump()` и метод-запрос Repository без `companyId` — exit 1 каждая
- guard baseline: без изменений — 0, рост — 1, сокращение — 0 (прогнано локально)
- `composer cs:check`, `composer cs:strict-types` — exit 0, `Found 0 of 2363`
- `composer test:unit` — 1946 OK (+19 новых), 4 deprecations (pre-existing)
- PHPStan анализирует 2347 из 2363
- guard: 11 сценариев оформлены тестом `tests/Unit/Bin/PhpstanBaselineGuardTest.php` —
  подмена записи, компенсация count, flow-форма, смешанная форма, закавыченные
  ключи, запись без count, count: 0, count: abc, пустая база, честное сокращение,
  без изменений

### Инцидент
В ходе работы проба удалила рабочий `site/src/Shared/Repository/AuditLogRepository.php`
(`mkdir -p` в существующий каталог, затем `rm -rf` каталога). Поймано падением
`composer test:unit`; файл восстановлен из `HEAD` и совпадает байт-в-байт.
Подробности и вывод на будущее — в `stages/stage-4.md`.

### Review status
- iteration: внутреннее — 2, внешнее — 6, итог **REVIEW_GREEN**
- внешняя итерация 1: 3 IMPORTANT + 1 MINOR — все подтверждены самостоятельно
  и исправлены (false negative правила; глобальные запросы, ошибочно
  объявленные IDOR-долгом; агрегатное сравнение в guard)
- внешняя итерация 2: 3 IMPORTANT + 2 MINOR — все подтверждены и приняты
  (непокрытые унаследованные finder'ы → FOLLOW-UP с замером 84 вызова и снятие
  переоценки в документации; false positive на DTO-носителях и отсутствие
  явного отказа; fail-open guard на flow-форме NEON)
- внешняя итерация 3: 3 IMPORTANT + 2 MINOR — все приняты (nullable-носитель
  принимался за гарантию; тег отказа срабатывал без причины; guard оставался
  fail-open на смешанном NEON). Все три — дыры, открытые моими же послаблениями
  итерации 2; на каждое послабление добавлена негативная фикстура
- внешняя итерация 4: 4 IMPORTANT + 1 MINOR — все приняты. После неё правило
  не залатано, а **упрощено**: инференс параметра-носителя удалён, ограничением
  считается только явный ненулевой `string $companyId` / объектный `$company`,
  всё прочее объявляется тегом `@companyScopeExempt <причина>`. Находок стало
  39 вместо 25 — это честнее
- внешняя итерация 5: 4 IMPORTANT + 2 MINOR + 1 FOLLOW-UP — все приняты
  (транзитивное владение, префикс stream, обход guard закавыченными ключами,
  невалидируемый count, однострочный тег). Неустранимые в этой конструкции
  ограничения названы прямо в докблоке правила и в CLAUDE.md
- unresolved findings: нет

### Exact next action
Решение Владельца после закрытия Stage 4.

Коммит Stage 4 выполнен pathspec'ом; посторонние staged-файлы Владельца
(`docs/tasks/ui-dashboard/**`, `docs/tasks/ui-pnl/**`) остались в индексе
нетронутыми — проверено `git show --stat HEAD` и `git status --short`.

### Files to inspect first on resume
- `site/tests/PHPStan/Rules/RepositoryCompanyScopeRule.php`
- `site/bin/phpstan-baseline-guard.sh`
- `docs/tasks/static-analysis/stages/stage-4.md`
