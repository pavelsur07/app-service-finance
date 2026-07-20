# WILDBERRIES FINANCE API — SALES REPORTS DETAILED (АКТУАЛЬНЫЙ PIPELINE)

## АКТИВНЫЙ ENDPOINT (FINANCE)
```http
POST /api/finance/v1/sales-reports/detailed
```

## ПАГИНАЦИЯ (CURSOR)
- Cursor/body field: `rrdId`.
- Стартовое значение: `rrdId = 0`.
- Следующий запрос: передаём `rrdId` последней строки из предыдущего ответа.
- Завершение чтения: HTTP `204 No Content`.

## КРИТИЧНЫЕ ИДЕНТИФИКАТОРЫ (НЕ ПУТАТЬ)
- `rrd_id` / `rrdId` — **уникальный ID строки отчёта реализации** (ключ строки + курсор пагинации).
- `srid` — **идентификатор товарной операции/заказа**.
- `realizationreport_id` — **ID документа отчёта реализации**, не уникальный ID строки.

> Для дедупликации строк и идемпотентной загрузки используем `rrd_id`/`rrdId`, а не `realizationreport_id`.

## ОСНОВНЫЕ ПОЛЯ FINANCE ОТЧЁТА

### SKU и атрибуты
- `gi_id` — ID поставки
- `subject_name` — категория
- `nm_id` — артикул WB
- `brand_name` — бренд
- `sa_name` — артикул поставщика (наш SKU)
- `ts_name` — размер
- `barcode` — штрихкод

### Тип операции и даты
- `rr_dt` — дата отчёта/операции
- `doc_type_name` — тип документа (`Продажа`, `Возврат`, `Корректировка продаж`, `Сторно продаж`)

### Денежные поля и их точная семантика
- `retail_price_withdisc_rub` / `retailPriceWithDisc` — **сумма SKU без СПП**.
- `retail_amount` / `retailAmount` — **сумма, оплаченная покупателем с учётом СПП**.
- `ppvz_for_pay` / `forPay` — **к перечислению продавцу** (продажа) / **к удержанию** (возврат).
- `acquiring_fee` / `acquiringFee` — **эквайринг**.
- `ppvz_vw` / `vw` — **вознаграждение WB без НДС**. ⚠ Finance API отдаёт ключ `vw`, не `ppvzVw`.
- `ppvz_vw_nds` / `vwNds` — **НДС вознаграждения WB**. ⚠ Finance API отдаёт ключ `vwNds`.
- `commission_percent` — **процент комиссии**, не сумма.

### Комиссия МП (утверждена Владельцем 2026-07-06)
```text
commission = retailPriceWithDisc × abs(quantity) − abs(forPay) − abs(acquiringFee)
```
Основана на официальной формуле WB: «К перечислению» = «Цена с согласованной
скидкой» − кВВ% − эквайринг. Проверено на PROD: тождество выполняется на 100%
строк (июнь 2026: 2423/2423). Комиссия = «Размер кВВ, %» × цена.

⚠ СПП-скидка и вознаграждение ПВЗ (ppvzReward) уже внутри этой суммы —
не добавлять их к комиссии отдельно (двойной счёт).

⚠ Поля `vw`/`vwNds` для комиссии НЕ использовать: это вознаграждение за вычетом
СПП-компенсаций WB, при большом СПП оно отрицательное (WB доплачивает продавцу).
`abs(vw) + abs(vwNds)` — старая неверная формула (занижала комиссию вдвое и
переворачивала знак компенсации).

### Выручка без СПП
```text
gross_without_spp = retailPriceWithDisc × abs(quantity)
```

⚠ Реконструкция `abs(forPay) + full_commission + abs(acquiringFee)` **неверна**:
в finance API `forPay` считается с учётом СПП-компенсаций WB и сумма не сходится
с ценой продавца (проверено на PROD: 0 совпадений из 2423 строк за июнь 2026).

### Каноническая декомпозиция Ingestion

Для товарной строки Ingestion сохраняет отдельно сумму покупателя, компенсацию
СПП, комиссию и эквайринг:

```text
gross_without_spp = abs(retailPriceWithDisc) × abs(quantity)
spp_compensation = gross_without_spp − abs(retailAmount)
commission = gross_without_spp − abs(forPay) − abs(acquiringFee)

Продажа: retailAmount + spp_compensation − commission − acquiringFee = forPay
Возврат: −retailAmount − spp_compensation + commission + acquiringFee = −forPay
```

- `retailAmount` → `SALE`/`REFUND`;
- `spp_compensation` → отдельный компонент `BONUS`;
- `commission` → `COMMISSION`;
- `acquiringFee` → `ACQUIRING`;
- `vw`/`vwNds` сохраняются только для аудита в `sourceData`;
- `ppvzReward` → `LOGISTICS/pvz_processing` только на строках операции
  «Возмещение за выдачу и возврат товаров на ПВЗ» и не входит в товарное
  тождество `forPay`.

## ПРАВИЛА ЗНАКОВ ДЛЯ УЧЁТА
- Продажа: комиссия и эквайринг учитываются как расход (`CHARGE`).
- Возврат: комиссия и эквайринг учитываются как сторно расхода (`STORNO`).
- Логистика к клиенту и обратная логистика остаются расходами.

## МАППИНГ НА НАШИ СУЩНОСТИ (ДОКУМЕНТАЦИОННО)

### MarketplaceSale (Продажи)
```php
externalOrderId: rrd_id
saleDate: rr_dt
marketplaceSku: sa_name
quantity: abs(quantity)
totalRevenue: retailAmount                          // с СПП (что заплатил покупатель)
pricePerUnit: gross_without_spp / abs(quantity)     // = retailPriceWithDisc (цена продавца, без СПП)
```

**Фильтр:** `doc_type_name === "Продажа"`

### MarketplaceCost (Затраты)
```php
wb_commission_percent: commission_percent
wb_commission_amount: retailPriceWithDisc × abs(quantity) − abs(forPay) − abs(acquiringFee)
wb_acquiring: acquiring_fee
wb_logistics: delivery_rub
wb_return_logistics: return_amount
wb_storage: storage_fee
wb_acceptance: acceptance
wb_deduction: deduction
wb_penalty: penalty
wb_additional_payment: additional_payment
```

### MarketplaceReturn (Возвраты)
```php
externalReturnId: rrd_id
returnDate: rr_dt
marketplaceSku: sa_name
quantity: abs(quantity)
refundAmount: retailPriceWithDisc
returnReason: supplier_oper_name
returnLogisticsCost: return_amount
```

## УСТАРЕВШИЕ/ЗАПРЕЩЁННЫЕ ИНТЕРПРЕТАЦИИ
- ❌ `retail_amount = quantity × retail_price`.
- ❌ `commission_percent` как денежная комиссия.
- ❌ `abs(vw) + abs(vwNds)` как «Комиссия МП» — vw это вознаграждение за вычетом
  СПП-компенсаций WB (бывает отрицательным); актуальная формула — раздел
  «Комиссия МП» выше.
- ❌ `refundAmount = abs(retail_amount)` как универсальная формула возврата.
- ❌ `realizationreport_id` как уникальный ID строки.

## АВТОРИЗАЦИЯ
**Основной токен для активного pipeline:** `Finance token`.

**Header:**
```http
Authorization: <finance-token>
```

## LEGACY (ТОЛЬКО ДЛЯ ИСТОРИЧЕСКОЙ СПРАВКИ)
Старый endpoint (не использовать в активном pipeline):
```http
GET /api/v5/supplier/reportDetailByPeriod
```

Параметры legacy-запроса:
- `dateFrom`
- `dateTo`
- `limit`
- `rrdid`
