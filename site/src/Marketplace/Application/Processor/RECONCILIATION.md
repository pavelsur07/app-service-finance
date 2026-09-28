# Ozon Costs Reconciliation — Руководство по сверке затрат

## Знаковое соглашение `MarketplaceCost`

Сумма `amount` хранится **положительной**. Направление несёт `operation_type`:

| `operation_type` | Смысл | Пример |
|------------------|-------|--------|
| `charge` | Затрата — расход продавца | Комиссия, логистика, хранение, реклама |
| `storno` | Сторно — возврат от маркетплейса | Возврат комиссии при возврате покупателя, возврат эквайринга |

Процессоры определяют направление по знаку исходной суммы маркетплейса и пишут
её модуль:

- Ozon by-day (`OzonAccrualCostsRawProcessor::operationType()`): Ozon присылает
  списание с продавца отрицательным, возврат — положительным, поэтому
  `> 0` → `storno`, иначе `charge`;
- WB: калькуляторы задают `operation_type` явно; `storno` бывает у удержаний,
  комиссии и эквайринга (`WbDeductionCalculator`, `WbCommissionCalculator`,
  `WbAcquiringCalculator`), например отрицательное удержание → `storno`.

**Правило:** направление читается только из `operation_type`, не из знака
`amount`. Запросы применяют `ABS(amount)` безусловно — так строка считается
верно при любом знаке в исторических данных.

---

## Структура итогов затрат

```
costs_amount   = SUM(ABS(amount)) WHERE operation_type = 'charge'  — все затраты брутто
storno_amount  = SUM(ABS(amount)) WHERE operation_type = 'storno'  — все сторно
net_amount     = costs_amount − storno_amount                      — итог нетто
```

`net_amount` — это то, что идёт в ОПиУ как расходы по маркетплейсу. Так же
считают `CostReconciliationQuery` и `UnprocessedCostsQuery`.

---

## Формула сверки с xlsx Ozon

```
xlsx_comparable = net_amount + return_revenue_amount
delta           = |xlsx_comparable| − xlsx_total      — matched при |delta| < 0.01
```

Где:
- `net_amount` — итог нетто затрат за период (раздел выше);
- `return_revenue_amount` — `SUM(refund_amount)` возвратов покупателям из `marketplace_returns`.

**Почему так:** Ozon в «Детализации начислений» включает возврат выручки покупателям
в расходные группы (группа «Возвраты»). Мы учитываем эти суммы отдельно
в `marketplace_returns`, поэтому наш `net_amount` на эту сумму меньше xlsx.

Дополнительно сверка сравнивает суммы по группам xlsx с нашими категориями:
группа категории — `OzonCostCategory::$xlsxGroup`.

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
Мы пишем комиссию **нетто**: возврат комиссии — отдельная строка категории
`ozon_sale_commission` с `operation_type = storno` (описание «Возврат комиссии Ozon»).

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

Сверка идёт через UI закрытия месяца, отдельного debug-эндпоинта нет.

1. «Маркетплейсы → Закрытие месяца», выбрать маркетплейс и период.
2. На этапе затрат загрузить отчёт «Детализация начислений» из ЛК Ozon
   кнопкой «Сверить с xlsx» (после закрытия этапа — «Пересверить»).
3. `POST /marketplace/month-close/reconcile` → `ReconcileCostsAction` →
   `CostReconciliationQuery` считает `xlsx_comparable` по формуле выше и
   сравнивает с итогом xlsx: `|delta| < 0.01` — `matched`, иначе `mismatch`.
4. Результат — на странице этапа: `xlsx_comparable`, итог xlsx и `delta`.

Нераспознанные услуги сверка не показывает — их показывает проверка
«Нераспознанные операции» при проверке готовности этапа затрат.

---

## Алгоритм переобработки периода

1. Если этап затрат уже закрыт — переоткрыть его на странице закрытия месяца:
   переобработка не снимает отметки строк, попавших в документ ОПиУ.
2. Переобработать raw-документы за период:
   - UI: «Маркетплейсы → Подключения» → «Переобработка данных за период»
     (`POST /marketplace/reprocess`, тип документов `all` / `sales_report` / `realization`);
   - CLI: `bin/console app:marketplace:reprocess <companyId> <marketplace> <Y-m-d> <Y-m-d> [--only=all|sales_report|realization] [--dry-run]`.
   Обе точки вызывают `ReprocessMarketplacePeriodAction`.
3. Сверить период с xlsx (раздел выше) и закрыть этап.

---

## Что означает сторно по категориям

| Категория | Когда возникает |
|-----------|-----------------|
| `ozon_sale_commission`, `storno` | Возврат комиссии при возврате покупателя |
| `ozon_acquiring`, `storno` | Возврат эквайринга при возврате покупателя |
| Любая категория услуги, `storno` | Ozon вернул стоимость услуги (положительная сумма в начислении) |
| `ozon_compensation` / `ozon_decompensation` | Услуга `Compensation`: знак суммы выбирает **категорию** (компенсация продавцу / списание с продавца), а не только `operation_type` — см. `OzonAccrualServiceCategoryResolver::SIGN_SPLIT_SERVICES` |

---

## Что НЕ входит в `marketplace_costs`

| Что | Где учитывается |
|-----|-----------------|
| Возврат выручки покупателям | `marketplace_returns.refund_amount` |
| Продажи | `marketplace_sales` |

Компенсации от Ozon в затраты **входят**: категория `ozon_compensation`,
базовый маппинг — статья `OPEX_WH_COMPENSATION`
(`config/marketplace/default_cost_mapping.yaml`).

---

## Ключевые файлы

```
src/Marketplace/Ozon/Application/Processor/OzonAccrualCostsRawProcessor.php     — затраты Ozon by-day, знак → operation_type
src/Marketplace/Ozon/Application/Service/OzonAccrualServiceCategoryResolver.php — услуга by-day → категория, ozon_unknown_<type_id>
src/Marketplace/Ozon/Domain/OzonCostCategory.php                                — каталог категорий затрат Ozon, группы xlsx
src/Marketplace/Application/ReconcileCostsAction.php                            — загрузка xlsx и запуск сверки
src/Marketplace/Infrastructure/Query/CostReconciliationQuery.php                — сверка с xlsx, xlsx_comparable
tests/Unit/Marketplace/Ozon/Application/Processor/OzonAccrualCostsRawProcessorTest.php — тесты процессора by-day
```

---

## Changelog маппинга `OzonServiceCategoryMap` (история легаси-пути v3)

Ozon снял метод v3 `/v3/finance/transaction/list` в сентябре 2026: новые затраты
идут только через by-day. `OzonCostsRawProcessor` и `OzonServiceCategoryMap`
остались для переобработки исторических raw-документов v3. Таблица ниже — история
словаря этого пути. Текущий каталог — `OzonCostCategory`, его изменения — в git.

| Версия | Дата | Изменение |
|--------|------|-----------|
| `2026-03-23.1` | 23.03.2026 | Начальная версия после рефакторинга |
| `2026-03-23.2` | 23.03.2026 | Добавлены `ozon_crossdocking`, `ozon_warehouse_movement`, `ozon_seller_bonus`, `ozon_premium_cashback`, `ozon_storage_partner` как отдельные категории |
| `2026-03-23.3` | 23.03.2026 | Добавлен `MarketplaceServiceItemTemporaryStorageRedistribution → ozon_storage_partner` для операций с `services[]` |
| `2026-03-23.4` | 23.03.2026 | Добавлены `ozon_seller_bonus` и `ozon_warehouse_movement` в `getCategoryName()` |
