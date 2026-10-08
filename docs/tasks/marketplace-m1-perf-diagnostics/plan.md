# marketplace-m1-perf-diagnostics: измеримый baseline производительности Ozon/WB без изменения поведения

Baseline: `make site-test-unit` — OK (3223 tests, 18079 assertions), pre-existing failures нет.

Факт Phase 0, расходящийся с брифом: raw финансовых документов Ozon/WB (`MarketplaceRawDocument.rawData`)
хранится JSON-колонкой PostgreSQL, не в S3. S3 (ObjectStorage) держит raw Ingestion и бронзу
MarketplaceAds. Замер storage поэтому разделён по `backend` (s3/local — декоратор ObjectStorage;
postgres — загрузка документа и flush). Хранение не меняется.

## Stage 1: инструментирование, отчёт, тесты
Risk: HIGH-LOCAL (правки в коде денежного конвейера Marketplace — только замеры; monolog/services конфиг)
stage_base_commit: 5a8c36d9
Definition of Done:
- PerformanceRecorder (область на сообщение/запуск, накопление этапов, лимит событий, отказоустойчивость);
  канал `performance` → `var/log/performance-<дата>.jsonl` (14 файлов); флаг `MARKETPLACE_PERF_DIAGNOSTICS`.
- Messenger: queue_wait из id Redis Stream, handler duration/outcome/retry; ничего в сообщениях не меняется.
- Этапы: api_fetch (Ozon by-day, types, realization; WB), source_parse (WB json_decode), storage_read/write
  (ObjectStorage; postgres-загрузка raw и flush raw), source_normalize (классификация строк, Ozon extract*),
  financial_mapping (категории затрат, себестоимость), financial_posting (flush строк учёта/Finance),
  processor_total.
- CLI `app:marketplace:perf-report` (md/json, период ≤31 дн., потолок байт/событий, снимок очередей).
- Тесты: успех, ошибки, выключенный флаг, отказ логирования, отчёт с данными и без, безопасность полей.
- Исключено: любые изменения сообщений, routing, raw, нормализации, финансов.
Work items:
- 1.1 ядро Shared/Infrastructure/Performance + конфиг
- 1.2 точки замеров Marketplace
- 1.3 CLI-отчёт + снимок очередей
- 1.4 тесты
Stage checks: unit-набор, phpstan/cs по изменённым файлам, локальный e2e на воркере.
Reviewer focus: поведение денежного кода не изменено; исключения не проглатываются и не подменяются;
нет payload/сумм/секретов в событиях; overhead при выключенном флаге.

## Stage 2: документация и prod-флаг
Risk: LOW (docs) + MEDIUM (`docker-compose.prod.yml`: проброс одного env-флага)
Definition of Done:
- `08-normalization-boundary.md`, `09-m1-diagnostics.md`, `10-m1-baseline-template.md`; строка M1 в `06`.
- `MARKETPLACE_PERF_DIAGNOSTICS: ${MARKETPLACE_PERF_DIAGNOSTICS:-1}` в x-php-env и scheduler.
- ARCHITECTURE.md: Shared-сервис PerformanceRecorder, команда.
Stage checks: ссылки/пути в документах сверены с кодом.
