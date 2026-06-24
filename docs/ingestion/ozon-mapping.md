# Ozon → Канон: маппинг полей

## Источники данных

| Resource type | Endpoint Ozon API | Mapper |
|---|---|---|
| `ozon_finance_accrual_by_day` | `POST /v1/finance/accrual/by-day` | `OzonAccrualByDayMapper` |
| `ozon_finance_accrual_postings` | `POST /v1/finance/accrual/postings` | `OzonAccrualShadowMapper` |
| `ozon_finance_accrual_types` | `POST /v1/finance/accrual/types` | `OzonAccrualShadowMapper` |

Legacy-пайплайн Ozon (`app:marketplace:ozon-daily-sync`) продолжает работать параллельно.
Новый Ingestion-пайплайн работает в shadow-режиме до переключения.

### Справочник accrual/by-day категорий

Для `ozon_finance_accrual_by_day` стандартные строки Ozon резолвятся через
`App\Ingestion\Application\Source\Ozon\OzonAccrualCategory`.

Это отдельный кодовый справочник для `/v1/finance/accrual/by-day` и
`/v1/finance/accrual/types`. Он не заменяет `OzonCostCategory`: старый
`OzonCostCategory` остаётся источником правды для legacy `services[].name` и
`operation_type`.

В `sourceData` транзакции сохраняются поля:

| Поле | Значение |
|---|---|
| `_ozon_category_code` | стабильный внутренний код строки Ozon |
| `_ozon_category_label` | название как в кабинете Ozon |
| `_ozon_category_group` | группа как в кабинете Ozon |
| `_ozon_category_parent` | родительская строка, если у Ozon есть подгруппа |
| `_ozon_category_sort_order` | порядок строки внутри стандартного Ozon-справочника |
| `_ozon_category_known` | `false`, если `type_id` пока не добавлен в справочник |

Для расширения справочника:

1. Загрузить справочник:
   `app:ingestion:ozon-accrual:load-types --company-id=<uuid> --connection-ref=<uuid> --execute-inline`.
2. Проверить сохранённый raw record `ozon_finance_accrual_types` и напечатанный список `type_id`/названий Ozon.
3. Сравнить названия Ozon с `OzonAccrualCategory::all()`.
4. Добавить alias или новый `type_id` в существующую строку; новую строку создавать только если в стандартном списке Ozon появилась новая статья.
5. Добавить/обновить unit-тест справочника и mapper-а.

Исчезнувшие у Ozon `type_id` сразу не удаляются: старые нормализованные данные
должны оставаться читаемыми.

---

## Natural key транзакции

```
externalId = "{base_id}:{component}"
```

### Base ID

| Условие | Base ID |
|---|---|
| Есть `operation_id` | `ozon:operation:{operation_id}` |
| Нет `operation_id` | `ozon:fallback:{posting_number}:{sku}:{date}` |

### Component

Суффикс компонента добавляется потому что одна операция Ozon может иметь
несколько строк одного `TransactionType` (например, несколько логистических сборов).

Примеры: `sale`, `commission`, `logistics_delivery`, `service_MarketplaceServiceItemDirectFlowLogistic`.

### operationGroupId

```
UUID v5 от строки "{companyId}:{base_id}"
```

Все компоненты одной операции Ozon получают одинаковый `operationGroupId`.
Используется для сверки контрольных сумм.

---

## Маппинг полей операции (accrual by day)

### Даты

| Канон | Источник |
|---|---|
| `occurredAt` | `operation_date` (UTC, без конвертации) |
| `externalUpdatedAt` | `operation_date` (для ежедневного отчёта) |
| `sourceTz` | `Europe/Moscow` (для UI-отображения) |

### Ссылки

| Канон | Источник |
|---|---|
| `orderRef` | `posting.posting_number` |
| `payoutRef` | не передаётся в ежедневном отчёте |
| `description` | `operation_type_name` |

### Декомпозиция полей операции → компоненты

Одна строка `transaction_list` → несколько `FinancialTransaction` с общим `operationGroupId`.

| Поле Ozon | component суффикс | TransactionType | Direction |
|---|---|---|---|
| `accruals_for_sale` | `sale` | `SALE` | `>0` → IN, `<0` → OUT |
| `sale_commission` / `sale_commission_amount` | `commission` | `COMMISSION` | по знаку |
| `delivery_charge` / `deliv_charge_amount` | `logistics_delivery` | `LOGISTICS` | по знаку |
| `return_delivery_charge` / `return_delivery_charge_amount` | `logistics_return_delivery` | `LOGISTICS` | по знаку |
| `amount` при `operation_type=ClientReturnAgentOperation` | `refund` | `REFUND` | `OUT` |
| ненулевой `amount` (fallback) | `other` | `OTHER` | по знаку |

### Декомпозиция services[] → компоненты

| service.name | component суффикс | TransactionType | Direction |
|---|---|---|---|
| `MarketplaceRedistributionOfAcquiringOperation` | `acquiring` | `ACQUIRING` | `price<0` → OUT (CHARGE), `price>0` → IN (STORNO) |
| `MarketplaceServiceItemDelivToCustomer` | `service_{name}` | `LAST_MILE` | по знаку |
| `MarketplaceServiceItemRedistributionLastMileCourier` | `service_{name}` | `LAST_MILE` | по знаку |
| `MarketplaceServiceItemDirectFlowLogistic` | `service_{name}` | `LOGISTICS` | по знаку |
| `MarketplaceServiceItemReturnFlowLogistic` | `service_{name}` | `LOGISTICS` | по знаку |
| `MarketplaceServiceItemReturnAfterDelivToCustomer` | `service_{name}` | `LOGISTICS` | по знаку |
| `MarketplaceServiceBrandCommission` | `service_{name}` | `COMMISSION` | по знаку |
| `OperationMarketplaceCostPerClick` | `service_{name}` | `ADVERTISING` | по знаку |
| `OperationMarketplaceServiceEarlyPaymentAccrual` | `service_{name}` | `FEE` | по знаку |
| остальные `services[]` | `service_{name}` | `FEE` | по знаку |

**Правило знака для services[]:**
- `price == 0` → пропустить.
- `price < 0` → `direction=OUT`.
- `price > 0` → `direction=IN` (STORNO — возврат ранее начисленного).

**Источник маппинга для legacy services[]:** `OzonCostCategory::findByServiceName($service['name'])`.
Не хардкодить в маппере — добавлять только в `OzonCostCategory`.

---

## Маппинг operation_type → TransactionType

Используется для операций без `services[]` или с нераспознанными services.

**Источник маппинга:** `OzonCostCategory::findByOperationType($operationType)`.

| OzonCostCategory.widgetGroup | TransactionType |
|---|---|
| «Вознаграждение» | `COMMISSION` |
| «Услуги доставки и FBO» | `LOGISTICS` |
| «Хранение» (ozon_storage, ozon_temporary_storage) | `STORAGE` |
| «Услуги партнёров» (кроме ozon_acquiring) | `FEE` |
| «Продвижение и реклама» | `ADVERTISING` |
| «Другие услуги и штрафы» | `FEE` |
| «Компенсации и декомпенсации» | `BONUS` |
| не найден в OzonCostCategory | `OTHER` + `NormalizationIssue(UNKNOWN_FIELD)` |

---

## Legacy daily/realization

Ресурсы `ozon_seller_daily_report` и `ozon_seller_realization` больше не доступны
для `app:ingestion:start-backfill`. Старые cursor rows по ним используются только
как seed для `ozon_finance_accrual_by_day`.

Legacy ресурсы не нормализуются текущим production path. Если реализация снова
понадобится в Ingestion, это должен быть отдельный ресурс и отдельный mapper.

---

## Контрольная сумма (сверка)

После маппинга строки маппер вычисляет контрольную сумму:

```
controlSum = sum(abs(amountMinor) для всех компонентов одной операции)
```

`NormalizeRawRecordAction` сверяет: сумма канона по `operationGroupId` == `controlSum`.
Расхождение → `NormalizationIssue(kind=SUM_MISMATCH)`.

---

## Эквайринг — важное

**Эквайринг НЕ передаётся как отдельное поле** в `/v3/finance/transaction/list`.
Поля `acquiring` / `acquiring_amount` в ответе отсутствуют.

Эквайринг приходит как элемент `services[]` с
`name=MarketplaceRedistributionOfAcquiringOperation`.

Маппер не должен искать `acquiring` / `acquiring_amount` в `source_data`.

**STORNO эквайринга** (при возврате товара):
- `price > 0` для `MarketplaceRedistributionOfAcquiringOperation` → `direction=IN`.
- Учитывается автоматически через знак `price`.

---

## Неизвестные типы операций

Для `accrual/by-day` неизвестный `type_id` не блокирует нормализацию:
- транзакция создаётся с fallback `TransactionType` текущего блока;
- `sourceData._ozon_category_group = "Неизвестные категории Ozon"`;
- `sourceData._ozon_category_known = false`;
- `app:ingestion:ozon-accrual:preview-normalization` показывает такие строки в
  `unknownOzonCategoryRows` и в секции `Ozon category summary`.

Если `operation_type` не найден в `OzonCostCategory` и нет подходящего `services[]`:
- Транзакция создаётся с `type=OTHER`.
- Создаётся `NormalizationIssue(kind=UNKNOWN_FIELD, details={operation_type})`.
- Нормализация **не прерывается** — обрабатываются остальные строки.

Для добавления нового legacy-типа: внести в `OzonCostCategory` в раздел
`operationTypes` соответствующей категории. Код legacy-маппера не менять.

---

## Листинги (привязка к товару)

При нормализации `SALE`-транзакции `OzonListingResolver` резолвит `listingId`:

1. Ищет `MarketplaceListing` по `(companyId, marketplace=OZON, supplierSku=offer_id)`.
2. Fallback: по `(companyId, marketplace=OZON, marketplaceSku=sku)`.
3. Если не найден: `listingId=null`, `enrichmentStatus=PENDING_LISTING`.

Cron `app:ingestion:resolve-missing-listings` повторяет попытку при появлении листинга в каталоге.
