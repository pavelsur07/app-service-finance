# Checkpoint — ozon-perf-campaign-404

### Текущее состояние
- Ветка: `fix/ozon-perf-campaign-404-locale`
- `stage_base_commit`: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Stage 1 — DONE, см. `stages/stage-1.md`.

### Checks and baseline
- baseline (до правки): `php bin/phpunit --testsuite integration --filter OzonPerformanceReportClientTest` — OK (8 тестов)
- baseline (до правки): `php bin/phpunit --testsuite unit --filter OzonPerformanceReportConnectorTest` — OK (8 тестов)
- красный регресс доказан: на коде до правки новые тесты дали 3 failures + 1 error
- после правки: `make site-test-unit` — OK (2291), `make site-test-integration` — OK (1223)
- `make site-cs-check` — Found 0 of 2466 (перепроверено с `--using-cache=no`)
- `make site-cs-strict-types` — Found 0 of 2466
- `make site-stan` — No errors, baseline не рос
- CI на PR #2415 — все проверки зелёные

### Review status
- внутренний review: зелёный, iteration 1
- внешний review (Codex): REVIEW_GREEN, iteration 1

### Exact next action
- Ждём решения Владельца по merge PR и по 13 сообщениям в failed на проде.

### Files to inspect first on resume
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonPerformanceReportClient.php`
- `site/tests/Integration/Ingestion/Infrastructure/Api/Ozon/OzonPerformanceReportClientTest.php`
