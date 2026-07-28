# План: wb-listing-size-dedup

Спецификация: `TASK.md`. Base commit задачи: `b1a49db2`.

## Stage 1: источник задвоения закрыт
Risk: 🟡 MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: `b1a49db2`

Definition of Done:
- `normalizeWbSize()` приводит `'0'` к `'UNKNOWN'`; новые безразмерные строки WB не создают второй листинг.
- Баркод записывается при создании безразмерного листинга (иначе теряется покрытие баркодами).
- Unit-тесты: регрессия на `'0'` и запись баркода; красные на старом коде.

Work items:
- 1.1 — `WbListingResolverService::normalizeWbSize()`: `'0'` → `'UNKNOWN'`.
- 1.2 — `createListing()`: снять условие `size !== 'UNKNOWN'` для записи баркода.
- 1.3 — тесты в `tests/Unit/Marketplace/Application/Service/WbListingResolverServiceTest.php`.

Stage checks:
- `make site-test-unit`, `make site-cs-check`.

Reviewer focus:
- Не сломан ли поиск листинга по натуральному ключу для настоящих размеров.
- Не появляется ли привязка чужого баркода (проверить ветку `resolve()` при `size === 'UNKNOWN'`).

## Stage 2: существующие дубли слиты
Risk: 🟠 HIGH-LOCAL (миграция по денежным данным; на прод — только через Production Gate)
owner_gate: yes
release_candidate: yes
independently_deployable: no
stage_base_commit: Stage 1 head

Definition of Done:
- Миграция `Version20260728120000` схлопывает пары `size IN ('0','UNKNOWN')` одного nm_id WB.
- Все 14 таблиц с `listing_id` переключены; конфликты уникальных ключей разрешены; строки рекламных документов просуммированы.
- Идентичность (chrtId, product_id, supplier_sku, name) перенесена на выжившего.
- `size='0'` → `'UNKNOWN'` только для Wildberries; листинги с настоящими размерами не затронуты.
- Проверено на локальной тестовой БД: сценарий-двойник с продажами, себестоимостью, баркодами и рекламной строкой.

Work items:
- 2.1 — карта дубль → канонический (`_wb_size_merge_map`) со снимком идентичности дубля.
- 2.2 — перенос дочерних строк, включая разрешение конфликтов и суммирование рекламных строк.
- 2.3 — удаление дублей, перенос идентичности, нормализация `size`.
- 2.4 — прогон и проверка на локальной тестовой БД.

Stage checks:
- `make site-test-migrations` (миграция применяется), сценарная проверка SQL на тестовой БД, `make site-cs-check`.

Reviewer focus:
- Полнота списка таблиц с `listing_id`.
- Порядок шагов: перенос идентичности только после удаления дубля (частичные уникальные индексы на `marketplace_variant_id` и `product_id`).
- Рекламные строки суммируются, а не удаляются.

## Production Gate (отдельно, после явного разрешения Владельца)
- Бэкап затронутых таблиц.
- Прогон миграции на проде.
- Проверка: пар-двойников 0; сумма рекламного расхода по документам не изменилась; продажи/затраты не потеряны.
- Решение по пересчёту себестоимости продаж — отдельно.
