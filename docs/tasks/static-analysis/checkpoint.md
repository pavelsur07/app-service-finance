## Current checkpoint

**Phase:** Stage 3 — Release Gate
**Status:** done
**Stage base commit:** `53f31e2588144a20876ff5f6fc03610e5a53f5c8`
**Current Work item:** none — 3.1…3.5 завершены
**Owner gate:** yes

### Completed
**Stage 1 — DONE**, коммит `d902c511`, CI зелёный. Гейт PHPStan level 8, baseline 3777.
**Stage 2 — DONE**, коммит `53f31e25`, CI зелёный («🔬 Static analysis» 1m46s).
Расширения Symfony/Doctrine/PHPUnit/webmozart, baseline 3525.

**Stage 3 (не закоммичен):**
- 3.1 — `phpat/phpat 0.12.4` в require-dev.
- 3.2 — `site/tests/Architecture/ModuleBoundaryRules.php`: правило закрытых слоёв
  (10 модулей с Facade-классом; `Application/DTO` открыт) и заморозка легаси-зоны
  через `shouldNot()->exist()` для `App\Entity`, `App\Service`, `App\Repository`,
  `App\Controller`.
- 3.3 — регистрация тегом `phpat.test`, `phpat.show_rule_names: true`.
- 3.4 — baseline 3525 → 3643 (+118), записей 2358 → 2467, из них 109 PHPat.
- 3.5 — негативные пробы обоих правил и замеры производительности.

### Current diff / affected files
- `site/composer.json`, `site/composer.lock` — `phpat/phpat`
- `site/tests/Architecture/ModuleBoundaryRules.php` — новый
- `site/phpstan.dist.neon`, `site/phpstan-baseline.neon`
- `CLAUDE.md` — счётчики и описание машинного контроля границ
- `docs/tasks/static-analysis/{plan,checkpoint}.md`, `stages/stage-3.md`

CI не менялся: PHPat не требует ни отдельного шага, ни окружения.

### Checks and baseline
- `composer stan` — exit 0
- пробы: импорт чужого `Repository` и неиспользуемый `App\Service\LegacyProbe` — exit 1, оба правила по имени
- `composer cs:check`, `composer cs:strict-types` — `Found 0 of 2347`, exit 0
- `composer test:unit` — 1927 OK, 4 deprecations (pre-existing)
- холодный прогон 7m07s (2 ядра), тёплый 5.8 s — PHPat кэш не ломает
- нарушения границ: Company 49, Cash 41, Finance 13, Marketplace 8, Ingestion 6, Balance 1

### Review status
- iteration: внутреннее — 2, внешнее — 8, итог **REVIEW_GREEN**
- итерация 1: 3 IMPORTANT + 4 MINOR — подтверждены самостоятельно, исправлены
- итерация 2: 1 IMPORTANT + 2 MINOR (рассинхрон checkpoint и Stage Report,
  допущение PSR-4 в правиле легаси-зоны) — исправлены
- итерация 3: 2 IMPORTANT + 1 MINOR (преждевременный owner gate в отчёте,
  неподтверждённый повтор внутреннего review, отсутствие истории итерации 2) —
  исправлены; внутреннее ревью проведено повторно и записано
- итерация 4: 2 IMPORTANT (расхождение правила `Application/DTO` с нормативным
  разделом CLAUDE.md; безусловный порядок commit при посторонних staged-файлах) —
  исправлены
- итерация 5: 1 IMPORTANT (правка порядка commit не применилась из-за упавшего
  скрипта) + 2 MINOR (неверный Facade в примере, «одиннадцать» методов) —
  исправлены
- итерация 6: 1 IMPORTANT (записанная проверка индекса была невыполнима при
  посторонних staged-файлах) — исправлена через pathspec
- итерация 7: 1 IMPORTANT (сокращение `$P` читалось как незаданная shell-переменная,
  что делало `git commit -- $P` коммитом всего индекса) — процедура заменена
  дословным блоком команд, read-only часть прогнана, вывод записан как факт
- unresolved findings: нет

### Exact next action
Решение Владельца: начинать Stage 4 (собственные правила: `companyId` в
Repository, запрещённые вызовы) или остановиться на Stage 3.

Коммит Stage 3 выполнен pathspec'ом по девяти путям; посторонние staged-файлы
Владельца (`docs/tasks/ui-dashboard/**`, `docs/tasks/ui-pnl/**`) остались в
индексе нетронутыми.

### Files to inspect first on resume
- `site/tests/Architecture/ModuleBoundaryRules.php`
- `docs/tasks/static-analysis/stages/stage-3.md`
