### Stage 3: постоянный deployment — DONE

Risk: HIGH-EXTERNAL (изменения CI/CD и production-конфигурации прямо предусмотрены запросом реализации).
Stage base: `ed99b3518`. Production и GitHub Variables не изменены.

- Удалено чтение шести Variables из deploy/Compose/DI/env; bootstrap секретов,
  БД/ролей, миграции сервисов, gateway-check, consumer-first и stdin сохранены.
- Production Ozon/WB/AI/business/payment workers без optional profiles.
  Отдельный `ai-service-eval` включается явно при nightly=true; false → stop.
  Dev profiles сохранены. Cron: PHP recovery --all, shell runner удалён.
- Уточнены active runbooks, architecture, admin UI copy и health gate documentation.
- Final internal IMPORTANT fixed: failOnError для автоматического Cash recovery;
  интерактивный Confirm сохраняет best-effort. Реальные dispatch/event failures RED→GREEN.
- Deployment shell + 6 runtime tests PASS; 14 Python config tests PASS;
  synthetic Compose config PASS; реальный local Compose profile smoke PASS.
- Python ruff/format PASS; mypy 68 source files PASS; pytest 540 passed,
  61 skipped (DB DSN не задан + pre-existing moved Symfony action path), 10 evals deselected,
  2 pre-existing sentry_sdk deprecation warnings. Live providers не вызывались.
- PHP full CS/strict PASS (0/4272); unit 4804 tests /26738 assertions PASS.
- Final focused PHPStan PASS; Telegram/PaymentIntake 184 tests /1304 assertions PASS.
- Full PHP tests: 9147 tests /51337 assertions PASS (unit + integration +3 CI functional shards).
- Full PHPStan: CI PASS; local worker OOM (Docker OOMKilled=true).
- Final external: round 1 REVIEW_GREEN; BLOCKER 0 / IMPORTANT 0 / MINOR 5.
  4 локальные группы MINOR исправлены. FOLLOW-UP: fail-fast внутри Cash-канала
  одного кабинета откладывает последующие намерения этого кабинета до cron;
  Telegram и другие кабинеты продолжаются. Перестройка per-draft retry вне цели.
- Ранее: Composer CS timeout 300s (повтор с timeout=0 PASS); monolithic full tests
  exit137 при 6771/9145 (повтор bounded); PHPStan worker OOM; повтор --debug также OOM, полный CI PHPStan PASS.
- Internal: fresh independent BLOCKER 0 / IMPORTANT 1 fixed + author final IMPORTANT 1 fixed; external Stage 2 IMPORTANT 1 fixed.
  Открытых BLOCKER/IMPORTANT нет. Handoff: подтверждённые проверки и review завершены; CI WB timing-flake retry PASS без правок исходников.
