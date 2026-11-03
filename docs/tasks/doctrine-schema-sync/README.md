# Синхронность схемы БД с маппингом Doctrine

Задача закрыта 03.11.2026: этапы 1–2 — PR #2869, этап 3 — PR #2870 (миграция применена на проде). Устройство — `ARCHITECTURE.md`,
«Схема БД: миграции — источник истины»; гейт — `docs/maintenance/quality-gates.md`.

## Задача

Схему на проде, в CI и в тестах создают только миграции, но атрибуты сущностей описывали её не
полностью. `doctrine:schema:update --dump-sql` на БД после миграций давал 409 строк, и
сгенерированная миграция удалила бы живые DBAL-таблицы (`marketplace_listing_tag_assignments`,
`deals_deal_sequence`, `product_import_sequences`), последовательность `companies_public_id_seq`,
около 50 внешних ключей и частичные уникальные индексы. Гейта не было.

## Результат

- Этап 1: маппинг и `SchemaExtension` модулей описывают БД после миграций; схема БД не меняется.
- Этап 2: гейт `SchemaInSyncWithMigrationsTest` в CI.
- Этап 3 (отдельный PR с миграцией): закрыть `KNOWN_DRIFT` гейта и расхождения прода с миграциями.

## Замеры «до» (03.11.2026)

Дифф маппинга с БД после миграций: 409 строк — 233 изменения и 176 пустых комментариев
`(DC2Type:…)`.

| Группа | Строк | Чья правда |
|---|---|---|
| DROP TABLE живых DBAL-таблиц + DROP SEQUENCE | 4 | БД |
| DROP CONSTRAINT ключей на строковые ссылки и составных `(company_id, …)` | ~50 | БД |
| Частичные индексы и индексы по выражению | ~30 | БД |
| `default`, длина, `jsonb`, точность `timestamp` | ~75 | БД |
| Имена индексов | ~15 | БД |
| Неиспользуемые таблицы | 3 | этап 3 |
| Ключ `document_operations.project_direction_id` | 1 | маппинг, этап 3 |
| Дубли уникальных индексов | 3 | этап 3 |
| `messenger_messages` (очередь failed) | 7 | этап 3 |

После этапа 1: 0 строк вне `KNOWN_DRIFT` и комментариев.

Скрытое расхождение, вскрытое нормализацией точности: 51 колонка Ingestion и Inventory —
`timestamp(6)` в БД при `datetime_immutable` (`TIMESTAMP(0)`) в маппинге. Приложение пишет время до
секунды, данные не страдают. Хранение описано в `IngestionSchemaExtension` и
`InventorySchemaExtension`; перевод на `datetime_immutable_us` меняет поведение — отдельная задача.

### Прод против БД после миграций (pg_catalog, read-only)

Только на проде (`>`), нет на проде (`<`):

| Объект | Строк на проде | Вывод |
|---|---|---|
| `> bank_connection`, `bank_import_cursor` (int id) | 0 / 0 | остатки до BankSync, кода нет |
| `> marketplace_raw_processing_step_runs` | 3, последняя 06.04.2026 | остаток, кода нет |
| `> repair_backup_ad_load_jobs_20260527`, `…_ad_scheduled_batches_20260527` | 6 / 97 | бэкап ремонта 27.05 |
| `> idx_6250404a979b1ad6`, `idx_d79dc3ba979b1ad6` | — | индексы мёртвых `balance_categor*` |
| `< FK_LISTING_PRODUCT` (`marketplace_listings.product_id → products`) | сирот 0 | условная миграция `Version20260321120000` не создала ключ |
| `< FK_SALE_LISTING` (`marketplace_sales.listing_id → marketplace_listings`) | сирот 0 | то же, `Version20260318130000` |
| `< idx_89d7f81e85d43df4`, `idx_a2b0728885d43df4` (`project_direction_id` у `document_operations`, `documents`) | — | `Version20260115105426` переименовывал индекс, только если тот был |
| `fk_documents_project_direction` | — | на проде `ON DELETE RESTRICT`, в миграциях `NO ACTION`: поведение то же |
| `telegram_bots.updated_at` | — | на проде `DEFAULT now()`, в миграциях без default |

Строки в мёртвых таблицах на проде: `balance_categories` 13, `balance_category_links` 3,
`document_counterparty_repair_backup` 5. `document_operations` без существующего проекта — 0;
очередь failed (`messenger_messages`) — 0.

## Этап 3 — миграция `Version20261103150000`

Решение Владельца 03.11.2026: пустые таблицы удалить, остальные — с бэкапом.

1. Внешние ключи: `document_operations.project_direction_id` (везде), на проде — `fk_listing_product`,
   `fk_sale_listing` (проверка «уже есть» по колонке, не по имени). Индексы `project_direction_id`.
2. Удалены дубли индексов `idx_month_close_lookup`, `idx_marketplace_order`,
   `idx_moysklad_connections_company_name` и их атрибуты.
3. Сняты комментарии `(DC2Type:…)` (на проде 190 колонок).
4. `messenger_messages` — identity и составной индекс по схеме doctrine-транспорта.
5. `telegram_bots.updated_at` без default (на проде появился вне миграций).
6. Удалены таблицы: пустые `bank_connection`, `bank_import_cursor` (миграция прерывается, если в них
   появились строки); с бэкапом — `balance_categories`, `balance_category_links`,
   `document_counterparty_repair_backup`, `marketplace_raw_processing_step_runs`,
   `repair_backup_ad_load_jobs_20260527`, `repair_backup_ad_scheduled_batches_20260527`.
7. `KNOWN_DRIFT` удалён: гейт без исключений.

Миграция необратима (`down()` — `IrreversibleMigration`). Бэкап — `backup-production.sh` от root на
проде до `production_action=migrations`: pg_dump восьми таблиц в `/var/backups/app-service-finance/`,
счётчики строк, проверка архива и sha256. Тест — `SchemaSyncStage3MigrationTest` (состояние «как на
проде», повторный прогон, отказ на непустой таблице).

Релиз — «run the migration and deploy #N», затем `production_action=migrations`, проверка схемы,
`deploy`. После — сверить на проде: ключи есть, таблиц нет, комментариев DC2Type 0, дифф прода с БД
после миграций пуст.

## Чек-лист приёмки

- [x] Расхождения прода с миграциями найдены и объяснены (таблица выше), дефекты прода — в этап 3.
- [x] Дифф на БД после миграций — только `KNOWN_DRIFT` и пустые комментарии.
- [x] Нет `DROP TABLE` / `DROP SEQUENCE` / `DROP CONSTRAINT` живых объектов.
- [x] Частичные уникальные индексы описаны; составные ключи Баланса и МойСклад — составные.
- [x] Этапы 1–2 без миграций.
- [x] Гейт красный при откате правки этапа 1 (убрана DBAL-таблица и `WHERE` в атрибуте — 4 строки).
- [x] PHPStan без роста baseline, CS, strict_types, unit + integration + functional зелёные (#2869).
- [x] ARCHITECTURE.md, quality-gates.md, CLAUDE.md.
- [x] Этап 3: решение Владельца по таблицам (03.11.2026), миграция и тест, гейт без исключений.
- [x] Этап 3 на проде: бэкап снят, счётчики до совпали, миграция применена, сверка после.

## Прод после этапа 3 (03.11.2026)

Бэкап: `/var/backups/app-service-finance/schema-sync-stage3-20261103T165316Z.iSbf1i.dump` (27 883 байт,
sha256 проверен), строки 13 / 3 / 5 / 3 / 6 / 97 / 0 / 0 — как в замерах.

| | До | После | Ожидалось |
|---|---|---|---|
| Таблицы | 157 | 149 | 149 |
| Внешние ключи | 182 | 181 | 181 (−4 на удалённых таблицах, +3) |
| Индексы | 592 | 575 | 575 |
| Комментарии DC2Type | 190 | 0 | 0 |

Ключи `fk_doc_ops_project_direction`, `fk_listing_product`, `fk_sale_listing` есть; удалённых таблиц 0;
`messenger_messages.id` — identity. Сверка pg_catalog прода с БД после миграций: 2653 = 2653 объекта,
единственное отличие — `fk_documents_project_direction` на проде `ON DELETE RESTRICT`, в миграциях
`NO ACTION`; для неотложенного ключа поведение одинаковое, оставлено. Деплой без даунтайма,
новых событий в GlitchTip нет.
