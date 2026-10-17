# Stage 3: факты размеченных данных по листингам

stage_base_commit: `5e194f226`

## План этапа

### Что нужно

Суммы, которые проведение отнесло бы на статьи ОПиУ, в разрезе «статья × листинг» — сырьё для
колонок тегов (этап 5). Те же источники, правила и знаки, что у закрытия месяца
(`CloseMonthStageAction`): для всех маркетплейсов — `SalesReturnsDataSource` и `CostsDataSource`,
для Ozon ещё `RealizationDataSource` и `RealizationReturnDataSource`.

### Решения

1. **Класс** — `App\Marketplace\Infrastructure\Query\PlPreviewFactsQuery` (`final`, DBAL), метод
   `fetch(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType
   $marketplace, bool $byListing): list<PlPreviewFactDTO>`.
2. **DTO** — `Application/DTO/PlPreviewFactDTO(plCategoryId, ?listingId, amount)`; `amount` —
   строка со знаком строки документа `marketplace_pl` (как после `is_negative`).
3. **Данные** — все за период, независимо от `document_id` / `pl_document_id` (решение Владельца).
   `preliminary`-фильтры не применяются: это свойство оперативного закрытия, а не данных.
4. **Источники и знак** (как у проведения):
   - продажи: активные правила `operation_type = sale`, сумма `MarketplacePlAmountSql::saleAmount(null)`,
     дата `sale_date`;
   - возвраты: активные правила `return`, кроме `return_realization`, сумма `returnAmount()`,
     дата `return_date`;
   - «Реализация» Ozon: правила `sale_realization` → `r.total_amount`, `return_realization` →
     `r.return_amount` (не NULL); в период входит документ целиком: `period_from >= from AND
     period_to <= to`, как у проведения;
   - знак продаж / возвратов / реализации: `is_negative` правила → «−»;
   - затраты: правила `include_in_pl = true` со статьёй; группа «категория × сторно» даёт
     `ABS(SUM(amount))`, знак — `is_negative` правила, у сторно — обратный (как
     `UnprocessedCostsQuery`).
5. **`byListing`.** Для продаж, возвратов и реализации сумма линейна, разрез по листингу её не
   меняет. У затрат `ABS(SUM)` по группе — нелинейная: если внутри группы суммы разных знаков,
   сумма модулей по листингам не равна модулю общей суммы. Поэтому «Итого» берётся отдельным
   вызовом с `byListing = false` — он совпадает с проведением, а колонки тегов — с `true`.
6. **Маркетплейс** — `null` = все; иначе фильтр по `marketplace`; реализация — только при `null`
   или Ozon.
7. **Скоуп** — каждый источник фильтруется по `company_id` и правило джойнится по `company_id`.
   `listing_id` у затрат и реализации может быть `NULL` — колонка «Общие / без тега».
8. **Агрегация в SQL** — один `UNION ALL` из пяти веток, `GROUP BY pl_category_id, listing_id`
   снаружи; нулевые суммы отбрасываются.

### Конечный результат

Один запрос отдаёт суммы со знаком по статьям (и листингам), и их итог по статье совпадает с
суммой строк документов, которые создало бы закрытие месяца на тех же данных.

### Чек-лист

- [x] Integration `PlPreviewFactsQueryTest`: каждый источник; знак продаж/возвратов по
      `is_negative`; затрата и сторно затраты; неактивное правило продаж не берётся; правило затрат
      `include_in_pl = false` и без статьи не берётся; строки другой компании не попадают; фильтр
      маркетплейса (реализация не попадает в WB); границы периода; затраты без листинга — с
      `listingId = null`.
- [x] Сверка с проведением: на общей фикстуре `byListing = false` по статьям = суммы строк из
      `Unprocessed*Query` (с `is_negative`).
- [x] Проведённые (`document_id` не NULL) строки тоже попадают.
- [x] PHPStan / CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md`: раздел «Marketplace: предпросмотр ОПиУ по тегам» (начало), Changelog.

## Stage Report — DONE

**Сделано.** `PlPreviewFactsQuery` (пять веток `UNION ALL`, снаружи `GROUP BY` статья × листинг),
`PlPreviewFactDTO`. Фикстура `tests/Integration/Marketplace/PlPreview/PlPreviewFixture.php` —
общая для этапов 3–5: все источники, сторно, затраты без листинга, исключённая и неразмеченная
категории, неактивное правило, чужая компания, граница периода, проведённая строка реализации.

**Проверки.**
- `PlPreviewFactsQueryTest` — OK (5 tests): разрез по листингам, итог без разреза, фильтр
  маркетплейса, пустой период, сверка с проведением.
- Сверка: итог по статьям = суммы строк `Unprocessed*Query` со знаком `is_negative` для REV, RET,
  COST; REAL отличается ровно на проведённую строку (+200) — она в предпросмотре есть, в
  непроведённых нет.
- Индексы: `idx_company_sale_date`, `idx_company_return_date`, `idx_company_cost_date`,
  `idx_ozon_realization_period` покрывают фильтры.
- CS — 0 исправлений; PHPStan по файлам — exit 0.

**Внутреннее ревью.** Скоуп компании в каждой ветке, правило джойнится по `company_id`.
`operation_type IS NULL` у затрат трактуется как начисление (`COALESCE(... , false)`), как
`UnprocessedCostsQuery` (`(bool) null`). Параметры — только через bind; в SQL подставляются
константы enum и фиксированные алиасы. BLOCKER/IMPORTANT: 0.
