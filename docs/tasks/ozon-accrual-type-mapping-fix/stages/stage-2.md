# Stage 2: миграция маппингов в БД

## 1. Какую задачу решаем

Маппинги `ingest_external_category_mappings` сильнее каталога и применяются,
даже если категория `deprecated`. Пока в БД активны `type:29`/`type:45`/…
и старые цели `code:sellerreturns` и т. п., исправленный в Stage 1 каталог до
разбора не доходит.

## 2. Что будет результатом

Миграция `Version20261018120000`:
- `status = 'disabled'` у маппингов `type:29`, `type:45`, `type:76`, `type:77` (все scope);
- `code:`/`name:` ключи `sellerreturns`, `placements`, `itempacking`,
  `temporaryplacement` перенацелены на `ozon_warehouse_export`,
  `ozon_warehouse_placement`, `ozon_warehouse_additional_packaging`,
  `ozon_fbs_return_short_placement`;
- запись в `ingest_external_category_mapping_audit` на каждое изменение с меткой
  `reason = ozon-accrual-type-mapping-fix`;
- `down()` восстанавливает прежние значения и удаляет свои записи аудита.

## 3. Что считаем выполненной работой

- Меняются только строки без ручной правки и с ожидаемыми прежними значениями;
  ручная правка затронутого ключа останавливает миграцию.
- Интеграционный тест на сценарии прода: целевой эффект, контрольные строки
  неизменны, резолвер после миграции даёт целевые статьи, откат точный.
- `doctrine:migrations` up → down → up на локальной БД.

## 4. Чек-лист приёмки

- [x] на снимке прода затрагиваются ровно 16 строк (7 отключение + 9 перенацеливание)
- [x] `updated_by IS NOT NULL` у затронутого ключа → `AbortMigration`
- [x] контроль: `code:logistic`, `name:размещение товара партнерами`, строка с неожиданными значениями — без изменений
- [x] после up: 29 LastMileCourier → `ozon_delivery_to_pickup_partners`, 71 SellerReturns → `ozon_warehouse_export`
- [x] down возвращает значения дословно, аудит задачи удалён
- [x] миграция не трогает `ingest_financial_transactions`

## Итог

**DONE 2026-10-18.**

- `site/migrations/Version20261018120000.php`; тест
  `tests/Integration/Ingestion/Migration/OzonAccrualTypeMappingMigrationTest.php`
  (2 теста, 27 проверок) — зелёный.
- `doctrine:migrations:migrate`, `execute --down`, `execute --up` на
  `app_test_tmfix` — OK.
- PHPStan по миграции и тесту — No errors; php-cs-fixer — 0 замечаний
  (каталог `migrations/` гейт CS не сканирует).
- Сверка со снимком прода (`measurements/before-mappings.txt`): 16 строк,
  `updated_by` пуст у всех, прежние значения совпадают с ожидаемыми миграцией.

Внутреннее ревью: BLOCKER 0, IMPORTANT 0. Учтено:
- аудит пишется до UPDATE тем же WHERE, поэтому старые значения в нём —
  фактические, не реконструированные;
- `created_at`/`updated_at` — московское время без зоны, как пишет приложение;
- совместимость при деплое: миграция применяется до выкладки кода; старый код с
  новыми данными разбирает 29/45 по `code:`/`name:` ключам или каталогу (тоже
  старому) — промежуток до деплоя только метаданные `source_data`, которые
  обновит ремонт Stage 3.
