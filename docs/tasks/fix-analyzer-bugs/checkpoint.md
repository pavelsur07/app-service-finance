## Current checkpoint

**Phase:** Stage 1 — Release Gate
**Status:** done
**Stage base commit:** `015d92e088b71050a8b89fc6d26fb8b2f64b3caf`
**Current Work item:** none — 1.1…1.4 завершены
**Owner gate:** yes

### Completed
- 1.1 — регрессионный тест на баг A (`$this->_em`), доказан красным:
  `Call to a member function persist() on null`.
- 1.2 — правка бага A: `$this->_em` → `$this->getEntityManager()` в двух
  репозиториях модуля Ai, по 2 обращения в каждом.
- 1.3 — регрессионный тест на баг B (`$roleId` вне `use`), доказан красным:
  `TypeError ... Argument #1 ($roleId) must be of type string, null given`.
- 1.4 — правка бага B: `$roleId` добавлен в `use (...)` замыкания.

### Current diff / affected files
- `site/src/Ai/Repository/AiRunRepository.php`
- `site/src/Ai/Repository/AiSuggestionRepository.php`
- `site/src/Company/Application/AssignCompanyMemberAccessRoleAction.php`
- `site/tests/Unit/Ai/Repository/AiRepositorySaveTest.php` — новый
- `site/tests/Unit/Company/Application/AssignCompanyMemberAccessRoleActionTest.php` — новый
- `docs/tasks/fix-analyzer-bugs/{plan,checkpoint}.md`, `stages/stage-1.md` — новые

Всего в исходниках 5 изменённых строк в 3 файлах.

### Checks and baseline
- новые тесты: `OK (5 tests, 11 assertions)`
- те же тесты на исходниках `master`: 4 ошибки + 1 падение (доказательство регрессии)
- `composer test:unit` — 1932 OK (на `master` 1927), 4 deprecations (pre-existing)
- `composer cs:check` — exit 0; `composer cs:strict-types` — exit 0
- PHPStan на ветке нет: он вводится в PR #2386

### Review status
- iteration: внутреннее — 1, внешнее — 2, итог **REVIEW_GREEN**
- внутреннее: BLOCKER нет, IMPORTANT нет; исправлены 2 MINOR по `cs:check`
- внешняя итерация 1: 1 IMPORTANT — принята. DoD требовал недостижимого на этой
  ветке сокращения baseline; исправлен критерий, а не отметка
- unresolved findings: нет

### Exact next action
Решение Владельца о merge. Порядок имеет значение: если первым мержится этот PR,
то в PR #2386 нужно выполнить `make site-stan-baseline` — три записи протухнут,
baseline сократится на 5 ошибок. Guard роста при этом не сработает: он
запрещает рост, а не сокращение.

### Files to inspect first on resume
- `docs/tasks/fix-analyzer-bugs/stages/stage-1.md`, раздел «Взаимодействие с PR #2386»
- `site/tests/Unit/Ai/Repository/AiRepositorySaveTest.php`
