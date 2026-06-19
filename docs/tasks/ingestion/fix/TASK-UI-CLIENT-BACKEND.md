# TASK-UI-CLIENT-BACKEND — Клиентский UI Ingestion (Backend часть)

## 0. Сводка

- **Бизнес-цель.** Подготовить REST API для 4 экранов клиентского UI Ingestion: Покрытие, Сверка, Проблемы, Финансовая сводка. Backend полностью самодостаточен — frontend разрабатывается отдельной задачей, опираясь на готовые эндпоинты и `schema.d.ts`.
- **Модуль.** `App\Ingestion` (существующий).
- **Тип.** feature (backend).
- **Ветка.** `feature/ingestion-ui-client-backend`.
- **Подзадачи.** B1 4 Query · B2 DTO + Facade · B3 4 Controller · B4 OpenAPI · B5 ExceptionListener · B6 Tenant-leak тесты · B7 schema.d.ts.
- **Затрагивает другие модули.** Читает через Facade: `IngestionFacade` (блок 5), `PnlFacade` (блок 7). Читает legacy `OzonTransactionTotalsCheck` через `OzonTransactionTotalsCheckRepository`.
- **Требует миграции БД.** Нет.
- **Меняет публичный API.** Да (4 новых эндпоинта).

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- Канон `FinancialTransaction` наполняется (блоки 5-6).
- P&L через `rebuildPeriod`, `PLDailyTotal`/`PLMonthlySnapshot` имеют `rebuiltAt` (блок 7).
- Существует legacy `OzonTransactionTotalsCheck` — контрольная сумма от Ozon.
- `IngestionFacade` и `PnlFacade` существуют.
- `make api-types` экспортирует OpenAPI в `schema.d.ts` для frontend.

### 1.2 Желаемое состояние

- 4 REST-эндпоинта под `/api/ingestion/verification/*` с OpenAPI.
- `schema.d.ts` обновлён и закоммичен.
- Все эндпоинты под изоляцией `CompanyFilter`, принимают `companyId` из `ActiveCompanyService`.
- Единый формат ошибок через `IngestionExceptionListener`.
- Multi-shop поддерживается на уровне API (фильтр `shop_ref`).
- Reconciliation использует существующий `OzonTransactionTotalsCheck` (без независимого пересчёта).

### 1.3 In scope

- 4 Query (DBAL): coverage, reconciliation, issues, financial-summary.
- 4 Controller (`__invoke`).
- DTO View для каждого ответа.
- OpenAPI-аннотации.
- `IssueDescriptionFormatter` для человекочитаемых описаний.
- Расширение `IngestionFacade` 4 методами.
- `IngestionExceptionListener`.
- Functional-тесты на каждый эндпоинт (включая tenant-leak).

### 1.4 Out of scope

- Frontend (React, Twig, entry-scripts, PageController) — отдельная задача `TASK-UI-CLIENT-FRONTEND`.
- Экспорт XLSX — отложен.
- Независимый пересчёт reconciliation из raw — отдельная задача.
- Admin-функции — отдельная задача.
- Грязные периоды P&L клиенту — только admin.
- Управление подключениями — раздел «Интеграции», отдельная задача.

### 1.5 Допущения

- Допущение: контрольная сумма Ozon берётся из `OzonTransactionTotalsCheck` (заполняется legacy-пайплайном). Если для периода нет записи — `ozon_control_total_minor = null`.
- Допущение: список shop'ов — `IngestRawRecord.shopRef DISTINCT` за последние 90 дней.
- Допущение: пагинация Issues — Pagerfanta, max 200 на страницу.

---

## 2. Доменная модель

### 2.1 Сущности

Новых нет.

### 2.2 Связи

N/A.

### 2.3 Enum

Новых нет.

### 2.4 Матрица переходов

N/A.

---

## 3. Слой доступа к данным

### 3.1 Repository

Без новых методов (используются существующие).

### 3.2 Query

Все — `final class`, DBAL. **SELECT * запрещён**. **companyId обязателен**.

#### `App\Ingestion\Infrastructure\Query\CoverageQuery`

Файл: `src/Ingestion/Infrastructure/Query/CoverageQuery.php`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `heatmap(string $companyId, ?string $shopRef, DateTimeImmutable $from, DateTimeImmutable $to): list<CoverageCellView>` | Группировка по `(date, shop_ref, resource_type)`: rawCount, txCount, issueCount, lastFetchedAt. Источники: `ingest_raw_records`, `ingest_financial_transactions`, `ingest_normalization_issues`. | да | `list<CoverageCellView>` |
| `shops(string $companyId): list<ShopOptionView>` | Список уникальных shopRef за последние 90 дней. | да | `list<ShopOptionView>` |

#### `App\Ingestion\Infrastructure\Query\ReconciliationQuery`

Файл: `src/Ingestion/Infrastructure/Query/ReconciliationQuery.php`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `summary(string $companyId, string $shopRef, int $year, int $month): ReconciliationSummaryView` | Канон vs `OzonTransactionTotalsCheck`. Null если контрольной суммы нет. | да | `ReconciliationSummaryView` |
| `breakdownByType(string $companyId, string $shopRef, int $year, int $month): list<ReconciliationByTypeView>` | По TransactionType: сумма канона, кол-во транзакций. | да | `list<ReconciliationByTypeView>` |

#### `App\Ingestion\Infrastructure\Query\IssuesQuery`

Файл: `src/Ingestion/Infrastructure/Query/IssuesQuery.php`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `build(string $companyId, ?string $shopRef, ?int $year, ?int $month): QueryBuilder` | DBAL QueryBuilder для Pagerfanta. Только открытые. | да | `QueryBuilder` |
| `count(string $companyId, ?string $shopRef, ?int $year, ?int $month): int` | Счётчик. | да | `int` |

Колонки QueryBuilder: `id, raw_record_id, operation_group_id, kind, details, created_at`.

#### `App\Ingestion\Infrastructure\Query\FinancialSummaryQuery`

Файл: `src/Ingestion/Infrastructure/Query/FinancialSummaryQuery.php`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `byMonth(string $companyId, ?string $shopRef, int $yearFrom, int $monthFrom, int $yearTo, int $monthTo): list<FinancialSummaryMonthView>` | Из `pl_monthly_snapshots` где `rebuilt_at IS NOT NULL`. | да | `list<FinancialSummaryMonthView>` |
| `byCategory(string $companyId, ?string $shopRef, int $year, int $month): list<FinancialSummaryCategoryView>` | По PLCategory за месяц. | да | `list<FinancialSummaryCategoryView>` |

### 3.3 Индексы

Новых не требуется.

---

## 4. Слой приложения

### 4.1 Action

В этом блоке Action не вводятся.

### 4.2 Domain Service

#### `App\Ingestion\Application\Service\IssueDescriptionFormatter`

Файл: `src/Ingestion/Application/Service/IssueDescriptionFormatter.php`. `final class`. Чистая функция.

Методы:
- `humanize(NormalizationIssueKind $kind, array $details): string` — человекочитаемое описание на русском без технических деталей.

Маппинг:
- `SUM_MISMATCH` → «Сумма операций не сходится с контрольной суммой источника»
- `MAPPER_FAILURE` → «Не удалось распознать операцию»
- `UNKNOWN_FIELD` → «В отчёте появилось неизвестное поле»
- `CURRENCY_MISMATCH` → «В операции смешаны разные валюты»

### 4.3 DTO

Все View — `App\Ingestion\Application\DTO\*`, `final readonly class`.

#### `CoverageCellView`

| Поле | Тип | Описание |
|---|---|---|
| `date` | string | ISO 8601 (YYYY-MM-DD) |
| `shopRef` | string | |
| `resourceType` | string | ozon_seller_daily_report / ozon_seller_realization |
| `rawCount` | int | |
| `txCount` | int | |
| `issueCount` | int | |
| `lastFetchedAt` | ?string | ISO 8601 |

#### `ShopOptionView`

| Поле | Тип |
|---|---|
| `shopRef` | string |
| `label` | string |

#### `ReconciliationSummaryView`

| Поле | Тип | Описание |
|---|---|---|
| `period` | string | YYYY-MM |
| `canonTotalMinor` | int | минорные единицы |
| `ozonControlTotalMinor` | ?int | null если нет данных |
| `currency` | string | ISO 4217 |
| `canonVsOzonDeltaMinor` | ?int | null если нет контрольной |
| `thresholdMinor` | int | 100 копеек по умолчанию |
| `recomputedAt` | string | ISO 8601 |

#### `ReconciliationByTypeView`

| Поле | Тип | Описание |
|---|---|---|
| `type` | string | enum value |
| `typeLabel` | string | русская метка |
| `canonAmountMinor` | int | |
| `txCount` | int | |

#### `IssueListItemView`

| Поле | Тип | Описание |
|---|---|---|
| `id` | string | UUID |
| `kind` | string | enum value |
| `humanDescription` | string | через formatter |
| `createdAt` | string | ISO 8601 |

#### `FinancialSummaryMonthView`

| Поле | Тип |
|---|---|
| `year` | int |
| `month` | int |
| `incomeMinor` | int |
| `expenseMinor` | int |
| `netMinor` | int |
| `currency` | string |

#### `FinancialSummaryCategoryView`

| Поле | Тип |
|---|---|
| `categoryId` | string |
| `categoryName` | string |
| `flow` | string |
| `amountMinor` | int |

#### `PaginationMeta`

| Поле | Тип |
|---|---|
| `page` | int |
| `limit` | int |
| `total` | int |
| `totalPages` | int |

### 4.4 Facade

#### `App\Ingestion\Facade\IngestionFacade` (расширение)

Добавить методы:
- `getCoverage(string $companyId, ?string $shopRef, DateTimeImmutable $from, DateTimeImmutable $to): array{cells: list<CoverageCellView>, shops: list<ShopOptionView>}`.
- `getReconciliation(string $companyId, string $shopRef, int $year, int $month): array{summary: ReconciliationSummaryView, byType: list<ReconciliationByTypeView>}`.
- `listIssues(string $companyId, ?string $shopRef, ?int $year, ?int $month, int $page, int $limit): array{items: list<IssueListItemView>, meta: PaginationMeta}`.
- `getFinancialSummary(string $companyId, ?string $shopRef, int $yearFrom, int $monthFrom, int $yearTo, int $monthTo): array{byMonth: list<FinancialSummaryMonthView>, byCategory: list<FinancialSummaryCategoryView>}`.

---

## 5. Асинхронность (Messenger)

N/A.

---

## 6. Обработка ошибок

| Класс | Когда | HTTP-статус | error.code | error.message |
|---|---|---|---|---|
| `InvalidPeriodRangeException` | from > to или yearFrom/monthFrom > yearTo/monthTo | 422 | `invalid_period_range` | «Некорректный диапазон периода» |
| `InvalidPeriodException` | year < 2020 или > 2100, month < 1 или > 12 | 422 | `invalid_period` | «Некорректный период» |

Namespace: `App\Ingestion\Exception\*`, `final class`.

#### `App\Ingestion\Infrastructure\Http\IngestionExceptionListener`

Файл: `src/Ingestion/Infrastructure/Http/IngestionExceptionListener.php`. `final class`. Подписан на `kernel.exception` с приоритетом 0. Срабатывает только для запросов `/api/ingestion/*`.

Формат ответа:
```json
{ "error": { "code": "invalid_period_range", "message": "Некорректный диапазон периода" } }
```

---

## 7. HTTP API (Controller)

Все: `final class`, `__invoke`, namespace `App\Ingestion\Controller\Api\Verification\`.

Авторизация: зарегистрированный пользователь, активная компания через `ActiveCompanyService`. `companyId` берётся из неё, не из path.

### 7.1 `GET /api/ingestion/verification/coverage`

Controller: `GetCoverageController`.

Query params:
- `shop_ref` (optional, string)
- `from` (required, date YYYY-MM-DD)
- `to` (required, date YYYY-MM-DD)

Response 200:
```json
{
  "cells": [
    {
      "date": "2026-06-01",
      "shop_ref": "ozon-shop-123",
      "resource_type": "ozon_seller_daily_report",
      "raw_count": 1,
      "tx_count": 287,
      "issue_count": 0,
      "last_fetched_at": "2026-06-02T03:14:00Z"
    }
  ],
  "shops": [
    {"shop_ref": "ozon-shop-123", "label": "Ozon магазин 123"}
  ]
}
```

Ошибки: 422 (`invalid_period_range`).

OpenAPI: `#[OA\Tag('Ingestion verification')]`.

### 7.2 `GET /api/ingestion/verification/reconciliation`

Controller: `GetReconciliationController`.

Query params:
- `shop_ref` (required, string)
- `year` (required, int)
- `month` (required, int 1..12)

Response 200:
```json
{
  "summary": {
    "period": "2026-05",
    "canon_total_minor": 1234567800,
    "ozon_control_total_minor": 1234566800,
    "currency": "RUB",
    "canon_vs_ozon_delta_minor": 1000,
    "threshold_minor": 100,
    "recomputed_at": "2026-06-15T10:00:00Z"
  },
  "by_type": [
    {"type": "sale", "type_label": "Продажа", "canon_amount_minor": 1500000000, "tx_count": 240}
  ]
}
```

Ошибки: 422 (`invalid_period`).

### 7.3 `GET /api/ingestion/verification/issues`

Controller: `GetIssuesController`. Пагинация `?page=1&limit=50`, max 200.

Query params:
- `shop_ref` (optional)
- `year` (optional)
- `month` (optional)
- `page` (optional, default 1)
- `limit` (optional, default 50, max 200)

Response 200:
```json
{
  "items": [
    {
      "id": "...",
      "kind": "sum_mismatch",
      "human_description": "Сумма операций не сходится с контрольной суммой источника",
      "created_at": "2026-06-15T10:00:00Z"
    }
  ],
  "meta": {"page": 1, "limit": 50, "total": 0, "total_pages": 0}
}
```

### 7.4 `GET /api/ingestion/verification/financial-summary`

Controller: `GetFinancialSummaryController`.

Query params:
- `shop_ref` (optional)
- `year_from` (required)
- `month_from` (required, 1..12)
- `year_to` (required)
- `month_to` (required, 1..12)

Response 200:
```json
{
  "by_month": [
    {"year": 2026, "month": 5, "income_minor": 1500000000, "expense_minor": 800000000, "net_minor": 700000000, "currency": "RUB"}
  ],
  "by_category": [
    {"category_id": "...", "category_name": "Продажи", "flow": "income", "amount_minor": 1500000000}
  ]
}
```

Параметр `month` для `by_category` — последний месяц диапазона.

Ошибки: 422 (`invalid_period_range`).

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | 4 Query + View DTO + `PaginationMeta` | блоки 5, 7 | 🟡 | integration на каждую Query + tenant-leak |
| B2 | `IssueDescriptionFormatter` + методы Facade | B1 | 🟢 | unit на каждый kind |
| B3 | 4 Controller + OpenAPI-аннотации | B1, B2 | 🟡 | functional на каждый эндпоинт |
| B4 | `IngestionExceptionListener` | B3 | 🟢 | unit на формат ответа |
| B5 | `make api-types` → `schema.d.ts` обновлён и закоммичен | B3 | 🟢 | `api-types-check` зелёный |
| B6 | Tenant-leak functional тесты на каждый эндпоинт | B3 | 🔴 | компания A не видит данные B |
| B7 | `ARCHITECTURE.md` + раздел про новые эндпоинты | все | 🟢 | — |

---

## 9. Ограничения и запреты

- Не добавлять новые поля в Entity.
- Не зависеть от Marketplace модуля напрямую — `OzonTransactionTotalsCheckRepository` инжектируется явно.
- Не считать reconciliation от raw (отдельная задача).
- Не возвращать клиенту технические поля (`operationGroupId`, `raw_record_id`, `details JSONB`) — только человекочитаемое.
- Performance: `getCoverage` и `getReconciliation` — индексирующие запросы; `getFinancialSummary` за > 12 месяцев — пагинированный кейс (вне MVP).
- Безопасность: каждый эндпоинт через `ActiveCompanyService`, `CompanyFilter` включён.
- Логирование: каждое обращение к эндпоинту логируется с companyId и временем.

---

## 10. Критерии приёмки

Функциональные:
- [ ] `GET /coverage` возвращает хитмап и список shop'ов за период.
- [ ] `GET /reconciliation` отдаёт канон vs Ozon control, null если контрольной суммы нет.
- [ ] `GET /issues` отдаёт открытые проблемы с человекочитаемым описанием.
- [ ] `GET /financial-summary` отдаёт P&L по месяцам и категориям из нового канона.
- [ ] Технические детали (UUID, JSONB) не попадают в ответы API.
- [ ] Multi-shop: фильтр `shop_ref` работает, отсутствие = все магазины.
- [ ] Все 422 ошибки в формате `{"error": {"code": ..., "message": ...}}`.

Технические:
- [ ] `make site-cs-check` + PHPStan зелёные.
- [ ] `make api-types-check` зелёный.
- [ ] `schema.d.ts` закоммичен.
- [ ] `make site-test-unit` + `make site-test-integration` зелёные.
- [ ] Tenant-leak functional тест на каждый из 4 эндпоинтов.
- [ ] `make api-doc-lint` зелёный.
- [ ] `ARCHITECTURE.md` обновлён.

---

## 11. План отката

- Удалить routes `/api/ingestion/verification/*` из `config/routes.yaml`.
- DROP не требуется (миграций нет).
- Frontend (отдельная задача) либо ещё не разработан — нечего ломать; либо разработан и обнаружит отсутствие эндпоинтов.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого Controller/Query/Service.
- [x] HTTP-контракт каждого эндпоинта (метод, путь, params, response JSON).
- [x] Все Query принимают `companyId`.
- [x] OpenAPI Tag указан.
- [x] Пагинация фиксирована для issues.
- [x] Human-readable issue descriptions через сервис, не технические поля.
- [x] Out of scope зафиксирован (XLSX, reconciliation от raw, admin, dirty periods, frontend).
- [x] План отката без потери данных.
- [x] Tenant-leak обязателен в DoD.
- [x] Запрет на технические поля в ответах API.
- [x] schema.d.ts обновляется и коммитится.
- [x] Frontend явно out of scope.
