# WILDBERRIES FINANCE — ПРАВИЛА РАСЧЁТА И ПОЛЯ API

> **Статус: единственный источник истины для финансовой логики WB внутри модуля Marketplace.**
>
> Формулы, названия показателей и правила знаков для WB фиксируются сначала в
> этом файле. Если код, тесты или другие документы ему противоречат, верным
> считается этот файл, а противоречие должно быть устранено отдельным изменением.
> Изменение финансовой семантики требует явного решения Владельца.

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
- `retail_price_withdisc_rub` / `retailPriceWithDisc` — **цена продавца до вычета СПП**.
- `retail_amount` / `retailAmount` — сумма строки после вычета СПП; используется
  как вход для расчётного показателя «Продажа с СПП», но не является готовым
  агрегатом за период.
- `ppvz_for_pay` / `forPay` — **к перечислению продавцу** (продажа) / **к удержанию** (возврат).
- `acquiring_fee` / `acquiringFee` — **эквайринг**.
- `ppvz_vw` / `vw` — **вознаграждение WB без НДС**. ⚠ Finance API отдаёт ключ `vw`, не `ppvzVw`.
- `ppvz_vw_nds` / `vwNds` — **НДС вознаграждения WB**. ⚠ Finance API отдаёт ключ `vwNds`.
- `commission_percent` — **процент комиссии**, не сумма.

## КАНОНИЧЕСКИЕ ПРАВИЛА РАСЧЁТА

### 1. Продажа без СПП

Полная сумма продажи по цене, которую продавец установил в кабинете, до
вычета платформенной скидки WB:

```text
sale_without_spp_row = abs(retailPriceWithDisc) × abs(quantity)
```

Итог за период рассчитывается суммированием товарных строк. Возвраты вычитаются
из продаж.

### 2. Продажа с СПП

Сумма продажи после вычета СПП. Готового итогового показателя за период нет:
он рассчитывается по строкам детализации:

```text
sale_with_spp_row = abs(retailAmount)
```

Итог за период рассчитывается суммированием товарных строк. Возвраты вычитаются
из продаж.

Разница между `sale_without_spp_row` и `sale_with_spp_row` — внутренний
показатель механики скидки WB. Она не является отдельным доходом, расходом,
бонусом или компенсацией продавца.

### 3. Комиссия МП

Общая комиссия маркетплейса рассчитывается от продажи без СПП:

```text
commission = sale_without_spp_row − abs(forPay) − abs(acquiringFee)
```

Эквивалентная формула по полям WB:

```text
commission = abs(retailPriceWithDisc) × abs(quantity) − abs(forPay) − abs(acquiringFee)
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

### 4. Эквайринг

```text
acquiring = abs(acquiringFee)
```

Эквайринг учитывается отдельно от комиссии МП.

### Контрольное равенство товарной строки

Для продажи:

```text
sale_without_spp_row − commission − acquiring = abs(forPay)
```

`sale_with_spp_row` и разница СПП в контрольном равенстве не участвуют.

### Запрет отдельной «Компенсации СПП»

- Не создавать финансовую транзакцию, компонент или P&L-статью
  `spp_compensation`.
- Не маппить разницу СПП в `BONUS`, доход или уменьшение комиссии.
- Не прибавлять и не вычитать разницу СПП при расчёте общей комиссии МП.
- `retailAmount` использовать только для расчётного аналитического показателя
  «Продажа с СПП».

## ПРАВИЛА ЗНАКОВ ДЛЯ УЧЁТА
- Продажа: комиссия и эквайринг учитываются как расход (`CHARGE`).
- Возврат: комиссия и эквайринг учитываются как сторно расхода (`STORNO`).
- Логистика к клиенту и обратная логистика остаются расходами.
- `deduction > 0` — удержание с продавца, уменьшает перечисление.
- `deduction < 0` — выплата продавцу, увеличивает перечисление.
- `bonusTypeName` содержит только основание операции и не определяет её
  направление.

Для raw-отчёта:

```text
deduction_impact = −deduction
```

Транзакционные проекции не входят в изменение знаков raw-отчёта и должны
изменяться только отдельной задачей с собственной проверкой совместимости.

## МАППИНГ НА НАШИ СУЩНОСТИ (ДОКУМЕНТАЦИОННО)

### MarketplaceSale (Продажи)
```php
externalOrderId: rrd_id
saleDate: rr_dt
marketplaceSku: sa_name
quantity: abs(quantity)
totalRevenue: retailAmount                          // с СПП (что заплатил покупатель)
pricePerUnit: sale_without_spp_row / abs(quantity)  // = retailPriceWithDisc (цена продавца, без СПП)
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
- ❌ Разница `sale_without_spp_row − sale_with_spp_row` как отдельная
  «Компенсация СПП», `BONUS`, доход или расход.
- ❌ `commission_percent` как денежная комиссия.
- ❌ `abs(vw) + abs(vwNds)` как «Комиссия МП» — vw это вознаграждение за вычетом
  СПП-компенсаций WB (бывает отрицательным); актуальная формула — раздел
  «Комиссия МП» выше.
- ❌ `abs(deduction)` как универсальный расход — отрицательный `deduction`
  является выплатой продавцу.
- ❌ `refundAmount = abs(retail_amount)` как универсальная формула возврата.
- ❌ `realizationreport_id` как уникальный ID строки.

## АВТОРИЗАЦИЯ
**Основной токен для активного pipeline:** `Finance token`.

**Header:**
```http
Authorization: <finance-token>
```
