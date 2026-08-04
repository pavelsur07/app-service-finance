### Stage 2: команда ротации ключей + integration-тесты + runbook — DONE

**Risk:** MEDIUM
**Owner gate:** no (tooling; сама ротация на проде — отдельное действие владельца по runbook'у)
**Release candidate:** no
**Independently deployable:** no
**Next action:** Final Release Gate

#### Stage scope
- Stage base commit: `e45841c9`
- Work items completed: 2.1, 2.2, 2.3, 2.4, 2.5

#### What was done
- `ConnectionApiKeyCodec::rotateIfNeeded()` — ротация на `SecretRotationServiceInterface`: пропуск legacy plaintext-строк и строк на активной версии; трогает только encrypted-пару
- Команда `app:marketplace:rotate-connection-keys`: активная версия + таблица распределения по версиям; dry-run по умолчанию; `--execute` батчами по 100 (flush+clear); пост-контроль; вывод без ключевого материала
- Integration-тест `ConnectionApiKeyRotationTest` (3 теста): v1→v2 с сохранением plaintext, legacy-строки пропускаются, dry-run/execute/идемпотентность, отсутствие утечек в выводе
- 7 существующих unit-тестов адаптированы к 2-аргументному конструктору codec
- Runbook `docs/maintenance/encryption-key-rotation.md` (пошаговая ротация через GitHub Secrets, rollback, сбои)

#### Files changed
- `site/src/Marketplace/Infrastructure/Security/ConnectionApiKeyCodec.php` — modified
- `site/src/Marketplace/Command/RotateConnectionKeysCommand.php` — new
- `site/tests/Integration/Marketplace/Infrastructure/Security/ConnectionApiKeyRotationTest.php` — new
- 7 unit-тестов — modified
- `docs/maintenance/encryption-key-rotation.md` — new

#### Definition of Done
- [x] Команда: dry-run распределение + pending; --execute батчами; идемпотентна; вывод без секретов
- [x] rotateIfNeeded на SecretRotationServiceInterface
- [x] Integration-тест: v1→v2 round-trip, legacy skip, dry-run read-only, нет утечек
- [x] Runbook в docs/maintenance/

#### Baseline
- Stage 1 final: unit 1731 OK

#### Checks
- targeted: `ConnectionApiKeyRotationTest` — OK (3 tests, 18 assertions)
- module: `tests/Integration/Marketplace tests/Functional/Marketplace` — OK (346 tests)
- full stage: `make site-test-unit` — OK (1731); `lint:container` — OK

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: прогресс-строка команды без confusing-отношения N/M (по MINOR внешнего review)
- FOLLOW-UP: none

#### External Claude Code review
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: MINOR (progress ratio) — упрощено; FOLLOW-UP (батч-атомарность) — добавлено в runbook «Сбои»
- Rejected findings with reason: none

#### Review fixes applied
- Прогресс-команда: `обработано: N`; runbook: заметка про батч-коммиты при прерывании

#### Risks / reviewer focus
- Команда — read-only по умолчанию; execute безопасен при прерывании (идемпотентность)
- Прода ротация НЕ выполняется этой задачей — только tooling

#### Checkpoint
- `docs/tasks/encryption-key-rotation/checkpoint.md` updated
- exact next action: Final Release Gate — handoff, Draft PR, owner decision

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
