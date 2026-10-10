### Stage 2: гейт «затраты вне ОПиУ», cron, документация — DONE

**Risk:** MEDIUM
**Stage base commit:** `92876bd8`
**Work items:** 2.1, 2.2

#### What was done
- `UnmappedCostsQuery` + `app:marketplace:cost-pl-mapping:unmapped-check`: окно с
  1-го числа прошлого месяца (МСК), активные SELLER-подключения, строки без
  `document_id`, Ozon — только распознанные коды (PreliminaryCostFilter).
- Условие «нет решения по ОПиУ» вынесено в `PreflightCostsQuery::WITHOUT_PL_DECISION`
  и используется preflight (2 места) и гейтом — одно определение вместо трёх копий.
- Cron: sync-default 04:35, unmapped-check 06:50; ARCHITECTURE.md (2.00),
  health-gates.md — шестой пример.

#### Checks
- phpunit `UnmappedCostsCheckCommandTest|Preflight` — OK, 32 tests
- phpstan по изменённым файлам — No errors; php-cs-fixer dry-run — 0 файлов

#### Review
- Охват гейта = охват починки: красное только при отсутствии решения по ОПиУ,
  назначение статьи зеленит гейт сразу (условие не зависит от пересборки).
- Нераспознанные коды Ozon и `include_in_pl=false` не краснят — тест.
- Один агрегированный `error`, стабильный текст, топ-20 кодов в контексте.
