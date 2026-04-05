# Marketplace daily raw pipeline — baseline и target контракт

## 1) Baseline (текущее состояние)

- Источник данных — `MarketplaceRawDocument` (`marketplace_raw_documents`) с сырым JSON из API.
- Пошаговая обработка `sales_report` выполняется через `ProcessMarketplaceRawDocumentAction` по видам:
  `sales`, `returns`, `costs`.
- Обработка `realization` (Ozon) выполняется отдельно через `ProcessOzonRealizationAction`.
- Ручные маршруты в `MarketplaceController` (`process-sales`, `process-returns`, `process-costs`, `process-realization`) активны и используются как admin/fallback flow.
- Переобработка периода (`ReprocessMarketplacePeriodAction`) поддерживает оба сценария: `sales_report` и `realization`.

## 2) Target-контракт daily raw pipeline

### Термины

- **raw document** — источник загрузки (снимок API-ответа, который повторно обрабатывается без изменения исходного payload).
- **processing run** — одна попытка полного проведения одного raw document в daily pipeline.
- **step run** — один шаг в рамках processing run.

### Scope

- Daily pipeline покрывает **только** шаги:
  - `sales`
  - `returns`
  - `costs`
- `realization` **не входит** в daily pipeline и остаётся в отдельном monthly/realization flow.

### Инварианты

1. Документ считается полностью проведённым только если **все обязательные step run** (`sales`, `returns`, `costs`) завершились успешно.
2. Любой `failed step` означает, что документ **не проведён полностью**.
3. `retry` step/run не должен ломать текущий процесс и не должен изменять контракт существующих processors.

## 3) Совместимость и ограничения

- Текущий ручной flow (кнопки sales / returns / costs) сохраняется как fallback/admin flow.
- Существующие raw processors не меняются.
- Monthly/realization flow не меняется.
- Документ фиксирует контракт, без изменения runtime-поведения.

## 4) Review triage (по итогам self-review)

- **MUST:**
  - Явно зафиксировать границы daily pipeline (только sales/returns/costs).
  - Явно зафиксировать, что realization вне daily pipeline.
  - Явно зафиксировать сохранение ручного fallback flow.
  - Явно зафиксировать инварианты полноты проведения и failed-step.
- **SAFE:**
  - Добавить enum-контракт (`PipelineStatus`, `PipelineStep`, `PipelineTrigger`) без встраивания в runtime-логику.
  - Уточнить комментарии в существующих Action/Controller/Entity без изменения поведения.
- **DEFER:**
  - Перевод ручных кнопок в полностью автоматический orchestration pipeline.
  - Введение сущностей/таблиц для хранения run/step-run статусов.
  - Миграция realization в отдельный унифицированный пайплайн-контур.
