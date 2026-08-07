# Частичная переобработка WB перестаёт быть ошибкой

## Контекст

PROD-разведка 2026-08-06 (`codex-psql-ro`, read-only):

- 38 WB `sales_report` raw-документов в `processing_status = failed`;
- 31 день в `marketplace_financial_report_sync_statuses.status = 'conflict'`
  (2026-06-30 … 2026-07-30, компания `b57d7682…`);
- 494 записи `WbGeneratedRowsConflictException` + 177 `PipelineFailedException`
  за один прогон переобработки июля;
- 93 сообщения `ProcessRawDocumentStepMessage` в failed-очереди (со старым
  текстом «Cannot force reprocess», т.е. до деплоя `41e0aaf1`);
- дублей и потерь данных нет: 0 дублей по `external_order_id` (sales) и
  `external_id` (costs) за июль.

Причина: после успешной частичной переобработки (открытые строки пересозданы,
привязанные к документу сохранены — поведение из PR #2305)
`ProcessMarketplaceRawDocumentAction::throwWbPartialReprocessConflict()`
сигнализирует результат исключением. Любой потребитель трактует исключение как
провал: шаг пишется в `failed_steps`, документ становится `failed`, день —
`conflict`. Пока строки привязаны к документу ОПиУ, повторный прогон даёт тот
же результат, поэтому день недостижимо красный (нарушение раздела
«Health-гейты» в `CLAUDE.md`).

## Stage 1: частичная переобработка завершается успехом

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: `41e0aaf1`

Definition of Done:
- частичная переобработка WB-дня завершается `SUCCESS` для шага, документа и
  sync-статуса;
- количество сохранённых linked rows остаётся видимым: warning-лог + счётчики
  в сводке переобработки (flash в UI, вывод CLI);
- `WbGeneratedRowsConflictException` и все мёртвые ветки его обработки удалены;
- `CONFLICT` сохраняется только за своим настоящим смыслом — in-flight raw
  (`WbRawDocumentRefreshConflictException`) и legacy reconcile;
- регрессионные тесты падают на старом коде.

Work items:
- 1.1 — DTO `ProcessRawDocumentResult` (processedRows, preservedLinkedRows);
  `ProcessMarketplaceRawDocumentAction` возвращает его вместо `int` и больше не
  бросает исключение.
- 1.2 — `ProcessRawDocumentStepMessageHandler`: удалить ветку обработки
  конфликта, логировать частичную переобработку как warning, шаг → succeeded.
- 1.3 — `ProcessDayReportHandler`: удалить мёртвый `catch` вокруг
  `cleanupForRawDocument` (сервис не бросает с `ad1b6303`) и осиротевшие
  зависимости.
- 1.4 — `ReprocessMarketplacePeriodAction` и `DebugReprocessPeriodController`:
  считать счётчики из DTO; удалить класс исключения.
- 1.5 — тесты: обновить 6 существующих файлов, регрессия на «частичная
  переобработка = success».
- 1.6 — `ARCHITECTURE.md`: переписать контракт refresh / safe replace.

Stage checks:
- `make site-test-unit` (таргетированно по Marketplace),
- `make site-cs-check` точечно по изменённым файлам (baseline красный),
- внутренний review полного diff от `41e0aaf1`,
- внешний read-only review (`codex exec`) до `REVIEW_GREEN`.

Reviewer focus:
- не потеряна ли видимость сохранённых linked rows;
- `CONFLICT` больше не выставляется частичной переобработкой, но остаётся для
  in-flight raw;
- переобработка по-прежнему не трогает строки с `document_id IS NOT NULL`.

## Вне scope

- Ремонт 31 «conflict»-дня и 93 сообщений failed-очереди на PROD — Production
  Gate, отдельное разрешение Владельца.
- 199 дней в `failed` за январь–апрель (шторм `MarketplaceRateLimitException`
  22–30 мая) — отдельная задача.
