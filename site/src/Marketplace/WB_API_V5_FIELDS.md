# WILDBERRIES API v5 - СПРАВОЧНИК ПОЛЕЙ

## ENDPOINT
```
GET https://statistics-api.wildberries.ru/api/v5/supplier/reportDetailByPeriod
```

## ПАРАМЕТРЫ ЗАПРОСА
- `dateFrom` — дата начала (YYYY-MM-DD)
- `dateTo` — дата окончания (YYYY-MM-DD)
- `limit` — лимит записей (максимум 100000)
- `rrdid` — ID для пагинации (0 для первой страницы)

## ОСНОВНЫЕ ПОЛЯ ОТВЕТА

### **Идентификаторы:**
- `realizationreport_id` — **Уникальный ID строки отчёта** (используем как externalOrderId)
- `gi_id` — ID поставки
- `subject_name` — Предмет (категория товара)
- `nm_id` — Артикул WB
- `brand_name` — Бренд
- `sa_name` — **Артикул поставщика** (ваш SKU)
- `ts_name` — Размер
- `barcode` — Штрихкод

### **Даты и типы:**
- `rr_dt` — **Дата отчёта** (используем как saleDate)
- `doc_type_name` — **Тип документа:**
    - `"Продажа"` — продажа товара
    - `"Возврат"` — возврат товара
    - `"Корректировка продаж"` — корректировка
    - `"Сторно продаж"` — отмена продажи

### **Количество и цены:**
- `quantity` — Количество (может быть отрицательным при возврате)
- `retail_price` — Цена розничная
- `retail_amount` — **Сумма продажи** (quantity × retail_price)
- `sale_percent` — Скидка (%)
- `commission_percent` — **Комиссия WB** (процент)

### **Затраты и удержания:**
- `delivery_rub` — **Логистика** (доставка до клиента)
- `return_amount` — **Обратная логистика** (возврат товара)
- `storage_fee` — **Хранение** на складе WB
- `acceptance` — **Платная приёмка**
- `deduction` — **Прочие удержания**
- `penalty` — **Штрафы**
- `additional_payment` — **Доплаты**

### **Итоги:**
- `supplier_reward` — Вознаграждение поставщика (к выплате)
- `acquiring_fee` — Эквайринг
- `acquiring_bank` — Банк эквайринга

### **Операции:**
- `supplier_oper_name` — Наименование операции (например, причина возврата)
- `office_name` — Склад
- `supplier_inn` — ИНН поставщика
- `declaration_number` — Номер декларации

---

## МАППИНГ НА НАШИ СУЩНОСТИ

### **MarketplaceSale (Продажи):**
```php
externalOrderId: realizationreport_id
saleDate: rr_dt
marketplaceSku: sa_name
quantity: abs(quantity)
pricePerUnit: retail_price
totalRevenue: abs(retail_amount)
```

**Фильтр:** `doc_type_name === "Продажа"` AND `retail_amount > 0`

### **MarketplaceCost (Затраты):**
```php
wb_commission: commission_percent
wb_logistics: delivery_rub
wb_return_logistics: return_amount
wb_storage: storage_fee
wb_acceptance: acceptance
wb_deduction: deduction
wb_penalty: penalty
wb_additional_payment: additional_payment
```

**Фильтр:** Все поля где `abs(значение) > 0`

### **MarketplaceReturn (Возвраты):**
```php
externalReturnId: realizationreport_id
returnDate: rr_dt
marketplaceSku: sa_name
quantity: abs(quantity)
refundAmount: abs(retail_amount)
returnReason: supplier_oper_name
returnLogisticsCost: return_amount
```

**Фильтр:** `doc_type_name === "Возврат"`

---

## ПРИМЕРЫ ЗАПИСЕЙ

### Продажа:
```json
{
  "realizationreport_id": 12345678,
  "rr_dt": "2025-02-01T00:00:00",
  "doc_type_name": "Продажа",
  "sa_name": "JACKET-XL-001",
  "quantity": 1,
  "retail_price": 5000.00,
  "retail_amount": 5000.00,
  "commission_percent": 750.00,
  "delivery_rub": 200.00,
  "storage_fee": 50.00,
  "supplier_reward": 4000.00
}
```

### Возврат:
```json
{
  "realizationreport_id": 12345679,
  "rr_dt": "2025-02-02T00:00:00",
  "doc_type_name": "Возврат",
  "sa_name": "JACKET-XL-001",
  "quantity": -1,
  "retail_amount": -5000.00,
  "return_amount": 200.00,
  "supplier_oper_name": "Брак"
}
```

---

## ВАЖНЫЕ ЗАМЕЧАНИЯ

1. **Quantity может быть отрицательным** — всегда используем `abs()`
2. **doc_type_name критичен** — именно по нему отличаем продажи от возвратов
3. **realizationreport_id уникален** — используем для дедупликации
4. **Затраты всегда в рублях** — не проценты
5. **Один запрос = все типы документов** — фильтруем на стороне PHP

---

## RATE LIMITS

- **60 запросов в минуту** (документировано)
- **Рекомендация:** делать паузу 1 секунду между запросами
- **Retry strategy:** 3 попытки с exponential backoff

---

## АВТОРИЗАЦИЯ

**Header:**
```
Authorization: <your-api-key>
```

**Где взять:**
Личный кабинет WB → Настройки → Доступ к API → Статистика → Создать токен

**Права:** `Статистика` (только чтение)
