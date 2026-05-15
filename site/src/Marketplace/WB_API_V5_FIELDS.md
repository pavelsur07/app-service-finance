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
- `commission_percent` — **процент комиссии**, не сумма.

### Полная денежная комиссия
```text
full_commission = retailPriceWithDisc - forPay - acquiringFee
```

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
totalRevenue: retailPriceWithDisc
pricePerUnit: retailPriceWithDisc / abs(quantity)
```

**Фильтр:** `doc_type_name === "Продажа"`

### MarketplaceCost (Затраты)
```php
wb_commission_percent: commission_percent
wb_commission_amount: retail_price_withdisc_rub - ppvz_for_pay - acquiring_fee
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
