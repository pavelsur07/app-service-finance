# Marketplace Stage 0: варианты целевой архитектуры

Рекомендация основана на [фактах реализации](01-current-architecture.md), [очередях](02-queues-and-workers.md), [гипотезах нагрузки](03-performance-bottlenecks.md) и [финансовой границе](04-data-and-financial-flows.md). Измеренного CPU/RAM/SQL профиля production нет: Go сам по себе не доказанное средство ускорения.

## Сравнение

| Критерий | A. Оптимизировать текущий Symfony | B. Изолировать Ozon/WB внутри Symfony | C. Ozon Go + WB Go + Symfony Financial Core |
|---|---|---|---|
| Масштабирование | Улучшить парсинг, batch и запросы; общая очередь сохраняет конкуренцию | Раздельные транспорты/лимиты и владение обработчиками; горизонтальный рост PHP | Независимый fetch/parse по провайдеру; добавляет сеть, inbox и новые операционные зависимости |
| Разработка/поддержка | Самая низкая стоимость | Средняя; существующие каталоги `Ozon/`, `Wildberries/` помогают | Высокая: два runtime, два деплоя, контракты, мониторинг, новые storage/credential boundaries |
| Сбой/восстановление | Ограничен текущим Redis и status/retry | Изоляция backlog, по-прежнему нужен replay/reconcile | Хорошая изоляция только при durable handoff и повторяемой доставке; иначе выше риск потерь |
| Финансовая целостность | Локальные транзакции сохраняются | Локальные транзакции сохраняются | Только Symfony пишет `marketplace_*`, Finance, Financial Core; Go публикует source facts, Symfony принимает идемпотентно |
| Эксплуатационные расходы | Низкие | Средние: больше workers/queues и метрик | Высокие: отдельные сервисы, резервирование, обновления, алерты, секреты |
| Миграция/rollback | Небольшой diff, простой rollback | По одному провайдеру/очереди; флаг возвращает прежний путь | Dual-run без двойного posting, проверка паритета, cutover по кабинету; откат fetch возможен, но нужны синхронные курсоры |
| Совместимость с Financial Core | Полная | Полная | Полная **при условии** что Go не является posting engine и соблюдает ADR/roadmap |

Подтверждение сравнения: обе интеграции уже имеют каталоги провайдеров, но общие Message/Entity/обработку (`ARCHITECTURE.md:358-377`); `CloseMonthStageAction` вызывает `FinanceFacade` внутри транзакции (`site/src/Marketplace/Application/CloseMonthStageAction.php:47-104`); Ingestion уже имеет отдельный raw/canonical слой, но не пишет ОПиУ (`docs/ingestion/INGESTION_ARCHITECTURE.md:5-32`). Фактическая очередь — Redis (`site/config/packages/messenger.yaml`, `docker-compose.prod.yml:16-20`), поэтому расчёты для RabbitMQ здесь неприменимы.

## Рекомендация

**B как ближайшая целевая архитектура, A как обязательная оптимизация внутри B; C — условный следующий шаг после измерений.** Сначала изолировать provider fetch/processing, ограничить объём raw на единицу работы, проверить идемпотентность и создать метрики. Только если при доказанном насыщении PHP/очередей варианты A/B не достигают SLO, запускать Go по одному провайдеру. Это сохраняет текущую финансовую семантику и позволяет проверить, решает ли раздельная обработка проблему 3–4 кабинетов без цены двух новых сервисов. Критерии решения о C перечислены в [07](07-risks-and-open-questions.md).

## Обязательная граница, если будет C

```text
Ozon Go / WB Go (API, rate limit, pages, source raw, source cursor)
    → versioned immutable batch manifest / source fact envelope
    → Symfony inbox + validation + normalization + reconciliation
    → Marketplace / Ingestion owner transaction
    → Financial Event + Outbox (по roadmap Financial Core)
    → Posting Orchestrator → Recognition / Cash / AR/AP / Balance
```

Go может первым взять HTTP-клиент, планирование страниц/курсор, checksum, хранение raw и техническую source-specific очистку полей. Он **не** изменяет `marketplace_sales`, `marketplace_returns`, `marketplace_costs`, `documents`, `pl_daily_totals`, `cash_transaction`, `fincore_*` или Balance и не назначает признание, проводки, категорию ОПиУ либо период. `CloseMonthStageAction`, `FinanceFacade` и будущие Financial Core producer/orchestrator остаются в Symfony (`site/src/Marketplace/Application/CloseMonthStageAction.php`; `site/src/Finance/Facade/FinanceFacade.php`; `docs/financial-core/04-target-architecture.md:21-57`). Нынешний Ingestion canonical `FinancialTransaction` остаётся Symfony-owned до отдельного решения по его роли (`site/src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php`; `docs/financial-core/10-migration-roadmap.md`).

### Контракт handoff

Версионированный envelope: `schema_version`, `company_id`, `provider`, `connection_ref`, `resource_type`, бизнес-период/часовой пояс, `source_external_id`, стабильный ключ страницы/строки, `source_revision`/время изменения, `payload_hash`, `storage_uri` и checksum/размер, trace/job ID, результат полноты (`complete`, cursor, page count). Денежные значения передаются как точные строки или minor units с `currency`; никаких `float`. Symfony проверяет tenant/connection, schema version, hash, полноту и уникальность inbox до импорта. Неизвестная версия/категория/валюта карантинируется, а не silently skipped. Ответ ACK только после долговечной записи inbox; повтор должен вернуть прежний результат. Коррекция источника — новая ревизия с причинной ссылкой, а не бесконтрольный overwrite.

Это проект контракта, не утверждённый API. Он должен согласоваться с `docs/financial-core/05-financial-events.md`, `07-idempotency.md`, ADR-006/008 и существующими natural keys Ingestion (`site/migrations/Version20260618130000.php:29-35`). Межсервисная доставка не заменяет атомарность события+outbox внутри Symfony (`docs/financial-core/04-target-architecture.md:31-45`).
