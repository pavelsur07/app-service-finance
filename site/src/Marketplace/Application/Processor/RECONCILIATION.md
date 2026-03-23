# Ozon Costs Reconciliation — Руководство по сверке затрат

## Знаковое соглашение `MarketplaceCost.amount`

| Знак | Смысл | Пример |
|------|-------|--------|
| `amount > 0` | Затрата — расход продавца | Комиссия, логистика, хранение, реклама |
| `amount < 0` | Сторно — возврат от маркетплейса | Возврат комиссии при возврате покупателя, возврат эквайринга |

**Правило:** никогда не используй `abs()` при записи `amount`. Знак несёт бизнес-смысл.

---

## Структура `grand_total`

```
costs_amount   = SUM(amount) WHERE amount > 0   — все затраты брутто
storno_amount  = SUM(ABS(amount)) WHERE amount < 0 — все сторно
net_amount     = costs_amount − storno_amount   — итог нетто
```

`net_amount` — это то что идёт в ОПиУ как расходы по маркетплейсу.

---

## Формула сверки с xlsx Ozon

```
xlsx_comparable = net_amount + return_revenue_amount
```

Где:
- `net_amount` — наш `grand_total.net_amount`
- `return_revenue_amount` — сумма возвратов выручки покупателям из `marketplace_returns`

**Почему так:** Ozon в «Детализации начислений» включает возврат выручки покупателям
в расходные группы (группа «Возвраты»). Мы учитываем эти суммы отдельно
в `marketplace_returns`, поэтому наш `net_amount` на эту сумму меньше xlsx.

### Пример — январь 2026

```
net_amount             = 3 741 715.62
return_revenue_amount  =    20 006.00
─────────────────────────────────────
xlsx_comparable        = 3 761 721.62  ✅ совпадает с xlsx
```

---

## Почему комиссия в xlsx и у нас различается

Ozon показывает комиссию **брутто** — без вычета возвращённой комиссии.
Мы пишем комиссию **нетто** — возврат комиссии записывается отдельной
строкой `amount < 0` с категорией `ozon_sale_commission`.

```
xlsx «Вознаграждение за продажу» (брутто)  = 1 828 929.62
наш ozon_sale_commission (нетто)           = 1 821 488.31
─────────────────────────────────────────────────────────
storno_amount (возврат комиссии)           =     7 441.31  ← уже в net_amount
```

Это **правильно** для ОПиУ: стоимость продаж отражается нетто,
возврат комиссии уменьшает затраты периода.

---

## Как проверить период

### Быстрая проверка (передаёшь итог xlsx)

```
GET /marketplace/costs/debug/verify
  ?marketplace=ozon
  &year=2026
  &month=1
  &xlsx_total=3761721.62
```

Смотришь только:
```json
"period_health": {
  "status": "OK"
}
```

Если `MISMATCH` — смотришь `reconciliation.delta` и `unknown_service_names`.

### Без xlsx (получаешь число для сравнения вручную)

```
GET /marketplace/costs/debug/verify?marketplace=ozon&year=2026&month=1
```

Берёшь `reconciliation.xlsx_comparable` и сравниваешь с итогом xlsx вручную.

---

## Алгоритм переобработки периода

```
1. GET /marketplace/costs/debug/map-version
   → убедиться что VERSION соответствует последнему коммиту

2. GET /marketplace/costs/admin/clear-for-reprocess
   ?marketplace=ozon&year=YYYY&month=M&confirm=1

3. GET /marketplace/costs/admin/process-period
   ?marketplace=ozon&year=YYYY&month=M&run=1

4. GET /marketplace/costs/debug/verify
   ?marketplace=ozon&year=YYYY&month=M&xlsx_total=XXXXX
   → period_health.status: OK
```

---

## Что означают категории сторно

| Категория | Когда возникает | Знак |
|-----------|-----------------|------|
| `ozon_sale_commission` с `amount < 0` | Возврат комиссии при `ClientReturnAgentOperation` | сторно |
| `ozon_acquiring` с `amount < 0` | Возврат эквайринга при возврате покупателя | сторно |
| Любой `services[].price > 0` | Ozon возвращает стоимость услуги | сторно |

---

## Что НЕ входит в `marketplace_costs`

| Что | Где учитывается |
|-----|-----------------|
| Возврат выручки покупателям | `marketplace_returns.refund_amount` |
| Продажи | `marketplace_sales` |
| Компенсации от Ozon (потеря по вине Ozon) | не учитываются в затратах — это доход |

---

## Ключевые файлы

```
src/Marketplace/Application/Processor/OzonServiceCategoryMap.php   — маппинг service name → category
src/Marketplace/Application/Processor/OzonCostsRawProcessor.php    — процессор затрат, знаковая логика
src/Marketplace/Infrastructure/Query/CostsVerifyQuery.php           — сверка, xlsx_comparable
tests/Unit/Marketplace/Application/Processor/OzonCostsRawProcessorTest.php — тесты знакового соглашения
```

---

## Changelog маппинга `OzonServiceCategoryMap`

| Версия | Дата | Изменение |
|--------|------|-----------|
| `2026-03-23.1` | 23.03.2026 | Начальная версия после рефакторинга |
| `2026-03-23.2` | 23.03.2026 | Добавлены `ozon_crossdocking`, `ozon_warehouse_movement`, `ozon_seller_bonus`, `ozon_premium_cashback`, `ozon_storage_partner` как отдельные категории |
| `2026-03-23.3` | 23.03.2026 | Добавлен `MarketplaceServiceItemTemporaryStorageRedistribution → ozon_storage_partner` для операций с `services[]` |
| `2026-03-23.4` | 23.03.2026 | Добавлены `ozon_seller_bonus` и `ozon_warehouse_movement` в `getCategoryName()` |
