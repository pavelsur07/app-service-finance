# Marketplace Stage 0: риски, неизвестные, измерения

Аудит проверял репозиторий, а не production. Суждения ниже — ограничения и проверяемые гипотезы. `03-performance-bottlenecks.md` содержит технические основания. Наблюдение пользователя «задержки уже при 3–4 кабинетах» не подтверждено телеметрией в доступном срезе и не локализует причину.

## Приоритетные риски

| Риск | Основание | Что проверить до изменения |
|---|---|---|
| Потеря/удвоение при разрыве raw→dispatch | fetch и обработка разделены сообщениями; `site/src/Marketplace/Ozon/MessageHandler/SyncOzonAccrualByDayHandler.php:199-226`, `site/src/Marketplace/Wildberries/MessageHandler/SyncWbFinancialReportDayHandler.php:318-330` | Доля raw без terminal processing, возраст, replay idempotence, sent/handled counts |
| Конкурентная перезапись исправленного отчёта | WB и Ozon имеют refresh/reprocess; `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php:103-145,185-219` | Уникальные ключи, locks, обработка старой ревизии после новой, linked rows |
| Пиковая память и I/O raw | WB append full JSON, Ingestion full gzip decode; подробности в [03](03-performance-bottlenecks.md) | RSS/peak, payload bytes/rows, сериализация и DB write time по percentiles |
| Задержка финансовых задач при общем pipeline | routing и worker limits — [02](02-queues-and-workers.md) | Queue age/depth и service time по message type, saturation на 3–4 кабинетах |
| Двойной posting при shadow Go | Новый provider и legacy могли бы передать один факт в Symfony | Запрет финансового side effect shadow, unique inbox, single writer lease, reconciliation |
| Смешение source и finance semantics | `ProcessMarketplaceRawDocumentAction` выбирает формат и пишет строки; `CloseMonthStageAction` создаёт P&L | Контракт Go↔Symfony, контроль денежных полей и версий правил |
| Неполное/неправильное закрытие месяца | preflight зависит от готовности raw и linked rows; `site/src/Marketplace/Application/CloseMonthStageAction.php:29-39,401-465` | Ready-day coverage, raw↔ledger, month-close reconciliation |
| Поздняя правка Ozon не доходит до PL прошлого предварительно закрытого месяца | `site/src/Marketplace/Application/Service/ByDayRowReplacement.php:132-145` сохраняет linked rows; для текущего предварительного месяца есть временной разрыв до пересборки (`:37-40`) | Отдельно считать исправленные raw, linked rows и дельту PL по периоду; решение о восстановлении — в финансовом Stage, не в Go fetch |
| Исторические queued messages при deploy | FQCN в Redis payload; `ARCHITECTURE.md:373-375` | Mixed-version contract tests, age отложенных/failed сообщений, drain перед удалением классов |
| Ошибочный перенос Ingestion в posting | `docs/ingestion/INGESTION_ARCHITECTURE.md:5-32` исключает запись ОПиУ | Согласовать ownership с Financial Core roadmap и module boundary tests |
| Потеря rollback после legacy cleanup | сохранённые v3 raw документы ещё переобрабатываются; `ARCHITECTURE.md:393-398` | Исторический replay test и retention перед M8 |

## Неизвестные production-данные

- По каждому кабинету/ресурсу/суткам: объём API (страниц, строк, compressed/uncompressed bytes), время до полной загрузки и нормализации, повторы и исправления задним числом.
- По каждому Message/transport: enqueue→start lag p50/p95/p99, runtime, throughput, retry count, failure age/depth, Redis stream pending/idle, число consumers и фактическая конкуренция.
- По PHP workers: RSS peak/median, CPU seconds, OOM/restart, GC, время `json_decode`/`json_encode`/hash/gzip, величина batch.
- По PostgreSQL: `pg_stat_statements` top queries, calls/mean/p95, shared blocks, WAL bytes, autovacuum/bloat, lock waits/deadlocks, размер таблиц/индексов/TOAST raw JSON, время commit и update полного raw.
- По API: 429/5xx/timeout и Retry-After, quota/credential bucket, response sizes, cursor resets, gap/duplicate pages.
- Финансовые инварианты: количество и точная сумма по raw/sale/return/cost/document за день/месяц, unmatched rows, дубли natural key, `raw_loaded` без processing, закрытые периоды с поздними corrections.

Из этих метрик нельзя печатать токены, полный raw, PII или production IP. Измерения собираются в штатной телеметрии; этот аудит production не касался (`AGENTS.md` §9).

## Открытые решения до Go

1. SLO: максимальный lag загрузки/финансового учёта и допустимая стоимость на кабинет. Без SLO нельзя сравнить B и C количественно.
2. Владелец raw и retention: собственное хранилище Go или текущий `RawStorageFacade`; нужна политика восстановления и удаления, особенно для исторического v3.
3. Ключ source fact для WB `rrd_id`/страниц и Ozon revisions: формально определить дубликат, исправление, отмену, связь со старым raw и cursor rollback.
4. Источник credentials и его scope при Go: сервис получает только нужный секрет кабинета, rotation и audit; без прямого доступа к финансовой БД.
5. Кто владеет source-specific money mapping: при изменении схемы Ozon/WB требуется совместная версия parser и Symfony validator; финансовые категории/периоды остаются в Symfony.
6. Реальная нагрузка Ads, Analytics и Ingestion на общие Redis/PostgreSQL ресурсы: исключить ошибочную атрибуцию seller pipeline.
7. Финансовые выплаты: пока не утверждены правила marketplace settlement AR/AP и Cash, не создавать их в Go или в этом roadmap (`docs/financial-core/adr/005-settlement-ar-ap.md`, `docs/financial-core/10-migration-roadmap.md:1-35`).

**Условие перехода от B к C:** после M1/M2 есть измеренный SLO miss и профиль показывает, что именно provider fetch/parser занимает CPU/RAM или очередь и не устраняется bounded work/раздельной обработкой в Symfony; при этом согласованы контракт, хранение, квоты API и стоимость двух сервисов. Иначе C добавит распределённый риск без доказанной выгоды.
