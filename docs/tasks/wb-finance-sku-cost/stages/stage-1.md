### Stage 1: Raw-агрегация по SKU — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously

#### Stage scope

- Stage base commit: `cbc5775c7474e266486c07ea49bcedc09f97bd09`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`

#### What was done

- Валидные продажи и возвраты WB агрегируются по варианту SKU в том же
  streaming-проходе, что и существующие статьи.
- Раздельно сохраняются количества и суммы продаж/возвратов.
- Для исторической себестоимости сохраняются quantity buckets по утверждённой
  цепочке дат.
- Коррекции перечисления без товара не становятся SKU.
- Строки без стабильного SKU остаются раздельными и дают quality-сигнал.
- Размер WB нормализуется одинаково с каталогом; seller SKU fallback
  регистронезависим.

#### Files changed

- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
- `site/src/Marketplace/WB_API_V5_FIELDS.md`
- `docs/tasks/wb-finance-sku-cost/*`

#### Definition of Done

- [x] Одна валидная товарная строка входит ровно в один raw SKU aggregate.
- [x] Размеры не объединяются по артикулу продавца.
- [x] Продажи и возвраты разнесены по количествам и суммам.
- [x] Исторические cost-date buckets сформированы без второго прохода.
- [x] Коррекции без товара исключены из SKU.
- [x] Старые статьи и summary сохраняют семантику.
- [x] Aliases, reportId, даты, возвраты, корректировки и missing identity
  покрыты тестами.

#### Baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
  — `OK (15 tests, 116 assertions)`.

#### Checks

- targeted builder + controller:
  `OK (20 tests, 148 assertions)`.
- full unit suite: `OK (1642 tests, 9543 assertions)`.
- targeted PHP CS Fixer: `Found 0 of 2 files that can be fixed`.
- `git diff --check`: clean.
- full `make site-cs-check`: pre-existing repository failure, 582 из 2153
  файлов требуют форматирования; task-owned PHP-файлы проверены отдельно и
  зелёные.

#### Internal automatic review

- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed:
  - защищено сложение количеств от переполнения;
  - канонизированы date bucket keys;
  - добавлен quality-сигнал missing SKU;
  - расширена сверка сумм и покрытие aliases/date fallbacks.
- FOLLOW-UP: сопоставление и итоговая сортировка выполняются в Stage 2.

#### External Claude Code review

- Iterations: 4
- Result: `REVIEW_GREEN`
- Confirmed findings fixed:
  - heading финансовой документации;
  - canonical dates;
  - case-insensitive seller SKU fallback;
  - missing SKU quality;
  - тесты reportId, date chain, with-SPP, attribute backfill;
  - удалён потенциально небезопасный emitted key с NUL;
  - покрыты fallback без rrdId и barcode-only size backfill.
- Rejected findings:
  - отдельный mutable accumulator не добавлен: он увеличивает число
    абстракций без изменения поведения;
  - quantity overflow остаётся fail-fast, чтобы не возвращать расходящиеся
    денежные и количественные агрегаты.

#### Risks / reviewer focus

- `nm_id = 0` в Stage 2 должен считаться отсутствующим.
- Unidentified raw groups должны оставаться раздельными после enrichment.
- Нераспределённая коррекция `forPay` должна быть показана отдельно.

#### Checkpoint

- `docs/tasks/wb-finance-sku-cost/checkpoint.md` updated.
- Exact next action: Stage 1 commit/push/Draft PR, then Stage 2.

#### Open questions

- none

#### Expected owner response

- not required; continuing autonomously
