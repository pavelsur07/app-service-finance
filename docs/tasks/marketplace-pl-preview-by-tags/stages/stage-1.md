# Stage 1: общие формулы сумм продаж и возвратов

stage_base_commit: `4cb242719`

## План этапа

### Проблема

Формула «сколько денег даёт строка продажи / возврата по источнику суммы» (`CASE m.amount_source`)
скопирована в пять мест:

- `UnprocessedSalesQuery::execute` — SELECT и HAVING (одна переменная `$amountCase`);
- `MarkProcessedQuery::markSales` — свой `$amountCase`;
- `UnprocessedReturnsQuery::execute` — в SELECT и HAVING два одинаковых текста;
- `MarkProcessedQuery::markReturns` — третий текст.

Предпросмотр ОПиУ (этап 3) должен считать суммы ровно так же, иначе он разойдётся с проведением.
Четвёртая копия — источник будущего расхождения.

### Решения

1. Новый класс `App\Marketplace\Infrastructure\Query\MarketplacePlAmountSql` (`final`, статические
   методы, только SQL-фрагменты):
   - `saleAmount(?MarketplaceType $marketplace): string` — CASE по `m.amount_source` для алиасов
     `s` (продажа) и `m` (правило). Конкретный маркетплейс → тот же текст, что сейчас
     (`AmountSource::SALE_GROSS->getSqlExpression()`); `null` → SALE_GROSS ветвится по
     `s.marketplace` в SQL — для отчёта по всем маркетплейсам сразу (нужно этапу 3).
   - `returnAmount(): string` — CASE для алиасов `r` (возврат), `ms` (связанная продажа), `m`.
2. Тексты формул переносятся без изменений, включая `COALESCE`-фолбэки возвратов
   (`return_gross` → `ms.price_per_unit * r.quantity`, иначе `r.refund_amount`). Расхождение
   `AmountSource::getSqlExpression()` с этими фолбэками не трогаю: это вне задачи.
3. Realization Ozon (`r.total_amount`, `r.return_amount`) и затраты не трогаю: там формулы нет,
   есть одно поле.
4. Сигнатуры и результаты публичных методов запросов не меняются.

### Конечный результат

Пять копий заменены вызовами одного класса; проведение ОПиУ даёт те же суммы; этап 3 сможет
переиспользовать формулы, в том числе для всех маркетплейсов сразу.

### Чек-лист

- [x] В `src/` не осталось `CASE m.amount_source` вне `MarketplacePlAmountSql`.
- [x] Integration-тест на `MarketplacePlAmountSql` против БД: каждый `amount_source` продаж
      (WB и Ozon для `sale_gross`, `null`-вариант для обоих), каждый `amount_source` возвратов,
      включая фолбэк `return_gross` без связанной продажи и `cost_price = NULL`.
- [x] Тест сумм `UnprocessedReturnsQuery` (раньше не было) — зелёный до и после переноса.
- [x] Существующие тесты (baseline, 26) зелёные.
- [x] PHPStan и CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md`: строка о `MarketplacePlAmountSql` в разделе маппинга + Changelog.

## Stage Report — DONE

**Сделано.** `MarketplacePlAmountSql::saleAmount(?MarketplaceType)` / `returnAmount()`; пять копий
`CASE m.amount_source` в `UnprocessedSalesQuery`, `UnprocessedReturnsQuery`, `MarkProcessedQuery`
заменены вызовами. Тексты формул перенесены без изменений.

**Файлы.** `src/Marketplace/Infrastructure/Query/{MarketplacePlAmountSql,UnprocessedSalesQuery,UnprocessedReturnsQuery,MarkProcessedQuery}.php`,
`tests/Integration/Marketplace/{MarketplacePlAmountSqlTest,UnprocessedReturnsQueryAmountTest}.php`, `ARCHITECTURE.md`.

**Проверки.**
- `UnprocessedReturnsQueryAmountTest` на старом коде — OK (4 tests), после переноса — OK.
- Baseline + новые: 35 tests / 104 assertions OK.
- php-cs-fixer (`.php-cs-fixer.php`, без кеша) по 6 файлам — 0 исправлений; PHPStan по
  изменённым файлам — exit 0.

**Внутреннее ревью.** Сверил SQL до/после построчно: продажи — тот же текст; возвраты — те же
ветки и фолбэки, изменено только форматирование; `MarkProcessedQuery` — те же условия. Вариант
`saleAmount(null)` новый, покрыт тестом (WB + Ozon в одном запросе). BLOCKER/IMPORTANT: 0.

**Внешнее ревью.** Не требуется на этапе (§7.2: diff < 500 строк), будет на handoff.
