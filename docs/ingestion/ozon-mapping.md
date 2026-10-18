# Ozon → Канон: маппинг полей

## Источники данных

| Resource type | Endpoint Ozon API | Mapper |
|---|---|---|
| `ozon_finance_accrual_by_day` | `POST /v1/finance/accrual/by-day` | `OzonAccrualByDayMapper` |
| `ozon_finance_accrual_postings` | `POST /v1/finance/accrual/postings` | `OzonAccrualShadowMapper` |
| `ozon_finance_accrual_types` | `POST /v1/finance/accrual/types` | `OzonAccrualShadowMapper` |

Legacy-пайплайн Ozon (`app:marketplace:ozon-daily-sync`, `/v3/finance/transaction/list`) удалён 23.09.2026: Ozon снял эндпоинт 09.09.2026.
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
| `_ozon_category_known` | `false`, если код услуги пока не добавлен в справочник |

Статья выбирается **только по коду услуги** (`name` из by-day или, если его нет,
имя из сохранённого словаря `accrual/types` по `type_id`) и по маппингам в БД.
Номер `type_id` сам статью не выбирает: карта `typeIds` в каталоге расходилась
со словарём Ozon (29 — это `LastMileCourier`, а не `Logistic`) и удалена
(задача `docs/tasks/ozon-accrual-type-mapping-fix/`). Без имени услуга остаётся
в `ozon_unknown_<type_id>` и попадает в очередь на разбор.

Порядок разбора услуги: маппинг компании → маппинг по умолчанию в БД → каталог
`OzonAccrualCategory` → **справочник** `ingest_ozon_accrual_types` → `ozon_unknown_<type_id>`.
Статья из справочника (`OzonAccrualDictionaryCategoryFactory`): код `ozon_` +
snake_case(`name`) (`AnalyticsPlus` → `ozon_analytics_plus`, `Drop-Off Agent` →
`ozon_drop_off_agent`); если код занят каталогом — с суффиксом `_<type_id>`
(`SaleCommission` → `ozon_sale_commission_69`: коды каталога семантические, совпадение
не значит «та же услуга»). Название — `description` или `name`, группа «Требует
классификации». Всегда `known = false` и запасной тип транзакции: справочник не
делает тип известным и не меняет естественный ключ транзакции; тип остаётся в очереди
разбора. Имя по `type_id` берётся из сырья, как раньше.

Группа статьи из справочника — подсказка `OzonAccrualGroupSuggester` по правилам
`ingest_category_group_rules` (регулярное выражение по `name + description`, первое
совпадение по `priority`). Ставится только при `confidence = high`, иначе «Требует
классификации». Правила и список групп (`ingest_category_groups`) — данные: новое
правило добавляется строкой в БД, без деплоя. Стартовый набор выверен по группам
отчёта кабинета «Детализация начислений» (`docs/tasks/ozon-accrual-types-dictionary/stages/stage-0.md`).

Точная группа Ozon берётся из самого отчёта «Детализация начислений» (xlsx из
кабинета продавца): `app:ingestion:ozon-accrual:import-details-xlsx --company-id=<uuid>
--file=<path> [--dry-run]`. Отчёт должен быть из кабинета этой компании: в нём нет
`type_id`, а ID начисления не совпадает с `accrual_id` by-day, поэтому строки
связываются с by-day компании по дате, SKU и сумме. Подтверждение требует не меньше
двух однозначных совпадений; уже подтверждённую группу новый отчёт не меняет — другая
группа записывается спором (`disputed_group_code`) и ждёт решения админа. Группа на
уже загруженных транзакциях появится после ночного `daily-maintenance` (45 дней) или
ремонта `--from/--to`. Подтверждённая группа
(`ingest_ozon_accrual_type_groups`, `status = confirmed`) сильнее группы каталога,
маппинга по умолчанию и правил; ручной маппинг и маппинг компании не трогает; код,
название и тип транзакции не меняет. Порядок группы: ручной маппинг → группа Ozon из
отчёта → маппинг по умолчанию / каталог → правило (`high`) → «Требует классификации».


Словарь `accrual/types` отдаёт `{"id", "name", "description"}`; `description` —
русское название услуги от Ozon. Алиас-код в каталоге обязан вести в статью,
совпадающую с этим `description`; расхождение формулировок фиксируется явно в
`OzonAccrualCategoryTest::testAliasCodesMatchOzonDictionaryDescription`.

Для расширения справочника:

1. Загрузить справочник:
   `app:ingestion:ozon-accrual:load-types --company-id=<uuid> --connection-ref=<uuid> --execute-inline`.
2. Проверить таблицу `ingest_ozon_accrual_types`: нормализация версии раскладывает справочник туда сама
   (`SyncOzonAccrualTypesAction`); новые типы — с `first_seen_at` последней версии, пропавшие — с `removed_at`.
   Неизменная версия новой сырой записи не создаёт; заполнить таблицу из уже загруженных версий —
   `app:ingestion:ozon-accrual:sync-types [--company-id=<uuid>] [--dry-run]`.
   Ограничения: версия, где часть строк не разобралась, пропавших не помечает (WARNING);
   возврат Ozon к прежней версии (V1 → V2 → V1) таблица не увидит — дедупликация сырья по
   hash новой записи не создаст, а `sync-types` берёт самую позднюю запись (V2).
3. Сравнить `description` услуги с `OzonAccrualCategory::all()`: статья выбирается по смыслу описания, а не по похожему коду.
4. Добавить код услуги alias-ом в существующую строку; новую строку создавать только если в стандартном списке Ozon появилась новая статья. `type_id` в каталог не вносить.
5. Обновить фикстуру `tests/Fixtures/Marketplace/Ozon/accrual_types.json`, unit-тест справочника и mapper-а.
6. Маппинги в БД (`ingest_external_category_mappings`) имеют приоритет над каталогом и применяются, даже если сама категория `deprecated`: при переносе алиаса проверить и их.

Исчезнувшие у Ozon коды сразу не удаляются: старые нормализованные данные
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

При нормализации `ozon_finance_accrual_by_day` `OzonListingResolver` резолвит
`listingId` через Marketplace facade:

1. Ищет `MarketplaceListing` по `(companyId, marketplace=OZON, supplierSku=offer_id)`.
2. Fallback: по `(companyId, marketplace=OZON, marketplaceSku=sku)`.
3. Если `sku` найден в `sourceData`, но листинга ещё нет, Marketplace facade
   идемпотентно создаёт Ozon listing с `size=UNKNOWN`, `price=0.00`,
   `supplierSku=offer_id` и названием из raw-строки, если они доступны.
4. Если `sku` / `offer_id` неоднозначны из-за дублей листингов, транзакция
   остаётся без `listingId`, чтобы не привязать её к неверному товару.

Для уже нормализованных транзакций без `listingId` используется безопасная команда:

```bash
php bin/console app:ingestion:ozon-accrual:relink-listings \
  --from=2026-06-01 \
  --to=2026-06-28 \
  --dry-run
```

Фактическое обновление выполняется только с `--execute`.
