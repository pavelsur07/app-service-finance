## Stage B2: Канонизация порядка строк raw payload — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (B3)

### Что сделано
- `OzonSellerReportConnector::pullAccrualByDay` — перед формированием `RawBatch` строки
  сортируются `sortRowsCanonically()`. Ключ: `date | accrual_id | sourceDataHash(row)`
  (полный контент-хеш как tie-breaker).
- `WbFinanceReportConnector::pull` — перед `RawBatch` строки сортируются `sortRowsCanonically()`.
  Ключ: `rrdId|rrd_id | sourceDataHash(row)` (поддержаны оба варианта имени поля, как в остальном коде).
- Внедрён `SourceDataHasher` в оба коннектора как последний параметр конструктора с дефолтом
  `= new SourceDataHasher()` — production-DI инжектит общий сервис (named-args в config сохранены),
  unit-тесты с позиционными аргументами не затронуты.
- `RawNdjsonCodec` не тронут (канонизация — ответственность коннектора).

### Затронутые файлы
- `site/src/Ingestion/Application/Source/Ozon/OzonSellerReportConnector.php` — modified
- `site/src/Ingestion/Application/Source/Wildberries/WbFinanceReportConnector.php` — modified
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonSellerReportConnectorTest.php` — modified (+1 тест)
- `site/tests/Unit/Ingestion/Application/Source/Wildberries/WbFinanceReportConnectorTest.php` — modified (+1 тест)

### Self-review
- [x] Scope compliance — только B2
- [x] Patterns / naming — `final readonly class` коннекторов сохранён
- [x] Forbidden actions — none (нет миграций, messenger.yaml не тронут)
- [x] Security — N/A (коннектор; companyId протекает через PullRequest без изменений)
- [x] CS-Fixer — green на изменённых файлах
- [x] Tests — полный unit-suite green (1124 теста); новые тесты: одни и те же rows в разном
      порядке → идентичный `encodeRows`-вывод; регрессия — существующие порядковые ассерты целы
- [x] PHPStan — N/A (не установлен)
- [x] ARCHITECTURE.md — N/A

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter 'OzonSellerReportConnectorTest|WbFinanceReportConnectorTest'`

### Риски / на что обратить внимание ревьюеру
- WB raw rows используют `rrdId` (camelCase) в реальных данных; sort-ключ поддерживает оба
  (`rrdId`/`rrd_id`). При отсутствии обоих сортировка опирается на полный контент-хеш — детерминизм
  сохраняется.

### Открытые вопросы
- нет
