# Stage 2 — фильтр тегов в расширенной юнит-экономике

```yaml
risk: HIGH-LOCAL          # меняется финансовая семантика totals
owner_gate: no
stage_base_commit: a3723a57
```

## Definition of Done

- [ ] API юнит-экономики принимает `tags[]` + `tagsMatch=any|all`, невалидный uuid → 422.
- [ ] Фильтр сужает набор листингов **до** цикла → totals считаются по отфильтрованному набору.
- [ ] При активном фильтре `totals.adSpend` = сумма построчного adSpend, а НЕ `getTotalAdCostForPeriod()`
      (тот включает неатрибутированную рекламу за весь период — с фильтром это мусор).
- [ ] Экспорт XLSX уважает тот же фильтр (иначе выгрузка расходится с экраном) + колонка «Теги».
- [ ] Фронтенд: выбор тегов в фильтрах, прокидка в запрос данных и в URL экспорта.
- [ ] `MarketplaceAnalytics` ходит в теги только через `ListingTagFacade` (кросс-модульная граница).
- [ ] Целевые тесты зелёные, `make site-test` зелёный.

## Что делаю

1. **`ListingTagFacade`** (`src/Marketplace/Facade/`) — единственная точка входа для MarketplaceAnalytics:
   - `list(companyId): list<ListingTagDTO>` — справочник для фильтра;
   - `listingIdsByTags(companyId, tagIds, matchAll): list<string>`;
   - `tagsForListings(companyId, listingIds): array<listingId, list<ListingTagDTO>>`.
2. **`ListingTagAssignmentRepository::listingIdsByTags`** (DBAL):
   - any: `SELECT DISTINCT listing_id … WHERE tag_id IN (:tags)`;
   - all: `… GROUP BY listing_id HAVING COUNT(DISTINCT tag_id) = :n`.
3. **`UnitExtendedQuery::execute`** — новые параметры `?array $tagIds`, `bool $tagsMatchAll`:
   - после сборки `$allListingIds` пересечь с `listingIdsByTags`, если фильтр активен;
   - в цикле копить `$rowAdSpendSum`; в финале при активном фильтре `totals.adSpend = $rowAdSpendSum`
     и пересчёт totalCosts/profit/drr/cac от него; без фильтра — прежний путь;
   - добавить `tags` в каждую строку (batch через facade).
4. **API `UnitExtendedController`** — распарсить `tags[]` (uuid → 422), `tagsMatch` (не «all» = «any»).
5. **Экспорт** — `tagIds`/`tagsMatchAll` в `UnitExtendedExportRequest` и контроллере, колонка «Теги» в exporter.
6. **Фронтенд** — `data-tags` на странице; фильтр-чипы + переключатель any/all; прокидка в `useUnitExtended`
   и `ExportXlsButton`; `tags` в типе `UnitExtendedItem`.

## Сознательный ponytail-skip

**Колонку «Теги» в React-таблицу не добавляю.** `UnitExtendedTable.tsx` (610 строк) — фиксированная
шапка с клоном через портал и синхронизацией ширин по индексу колонки, `colCount` для colSpan
разворота breakdown, замороженные sku/title. Вставка несортируемой колонки туда — дорогая правка
индексов ради данных, которые при активном фильтре по тегу и так одинаковы во всех строках.
Теги возвращаются в API-контракте (`item.tags`) и попадают в XLSX-экспорт — этого достаточно;
колонку в таблицу добавить отдельно, когда появится реальный запрос «просматривать теги не фильтруя».

## Тесты

- Facade/repository: `listingIdsByTags` any vs all.
- `UnitExtendedQuery`: фильтр сужает items и totals; при фильтре adSpend = сумма строк, без фильтра —
  полный период.
- API: `tags[]` с битым uuid → 422; корректный фильтр отдаёт только тегированные листинги.
- Экспорт: с фильтром в XLSX только тегированные строки (через query-результат).
