## Stage 1: частичная переобработка WB завершается успехом — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP — Release Gate: PR в Draft, ремонт PROD-состояния требует отдельного Production Gate

### Scope Stage
- Stage base commit: `41e0aaf1`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`, `1.6`

### Что сделано

Диагностика на PROD (read-only): 38 WB `sales_report` raw-документов в `failed`,
31 день в `conflict` (2026-06-30 … 2026-07-30), 494 записи
`WbGeneratedRowsConflictException` за один прогон июля, 93 сообщения в
failed-очереди. Дублей и потерь данных нет (0 дублей по `external_order_id` и
`external_id` за июль) — красным был только статус.

Причина: после успешной частичной переобработки (открытые строки пересозданы,
привязанные к финансовому документу сохранены — поведение PR #2305) результат
сигнализировался исключением. Любой потребитель трактует исключение как провал,
поэтому день становился недостижимо красным: строки закрытого документа
неизменяемы by design, повторный прогон даёт тот же результат.

Изменения:
- `ProcessMarketplaceRawDocumentAction` возвращает `ProcessRawDocumentResult{processedRows, preservedLinkedRows}` вместо `int` и не бросает исключение; при `preservedLinkedRows > 0` пишет warning.
- `ProcessRawDocumentStepMessageHandler`: шаг помечается `succeeded`, warning с числом сохранённых строк; ветка обработки конфликта удалена.
- `ProcessDayReportHandler`: удалён мёртвый `catch` вокруг `cleanupForRawDocument` (сервис не бросает с `ad1b6303`) и осиротевшие зависимости/методы (−98 строк).
- `WbGeneratedRowsConflictException` удалён.
- `WbFinancialReportSyncStatusUpdater`: `LogicException`/`InvalidArgumentException` больше не превращаются в `CONFLICT` — это `FAILED_FINAL`. `CONFLICT` остался за in-flight raw и legacy reconcile.
- Видимость сохранена: счётчики сводки переобработки переименованы `conflicts` → `partial_steps`, `linked_rows_preserved` оставлен; flash в UI и warning в CLI работают как раньше.

### Затронутые файлы
- `site/src/Marketplace/Application/DTO/ProcessRawDocumentResult.php` — new
- `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php` — modified
- `site/src/Marketplace/Application/ReprocessMarketplacePeriodAction.php` — modified
- `site/src/Marketplace/Application/Service/WbFinancialReportSyncStatusUpdater.php` — modified
- `site/src/Marketplace/Command/ReprocessMarketplaceCommand.php` — modified
- `site/src/Marketplace/Controller/MarketplaceController.php` — modified
- `site/src/Marketplace/Exception/WbGeneratedRowsConflictException.php` — deleted
- `site/src/Marketplace/MessageHandler/ProcessDayReportHandler.php` — modified
- `site/src/Marketplace/MessageHandler/ProcessRawDocumentStepMessageHandler.php` — modified
- `site/src/MarketplaceAnalytics/Controller/Api/DebugReprocessPeriodController.php` — modified
- 7 тестовых файлов — modified
- `ARCHITECTURE.md` — modified (контракт refresh / safe replace, смысл `CONFLICT`)

Миграций нет. Публичный HTTP-контракт не изменён; в debug-эндпоинте
`DebugReprocessPeriodController` добавлено поле `preserved_linked_rows`.

### Регрессия доказана красной
`WbFinancialReportSyncIdempotencyTest::testForceRefreshWithLinkedRowsKeepsDayGreenAndPreservesLinkedRows`
прогнан на `41e0aaf1` (src из base commit, тестовая обвязка адаптирована под
старую сигнатуру конструктора) и упал ровно с симптомом прода:

```
Failed asserting that two strings are identical.
-'success'
+'conflict'
```

На новом коде тест зелёный, linked row сохраняет старую цену (100), open row
пересоздан с новой (222), три шага `succeeded`, записей об ошибках у дня нет.

### Self-review
- [x] Scope compliance — только целевой дефект и его прямые следствия
- [x] Patterns / naming — DTO `final readonly class`, `declare(strict_types=1)`
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR) — проверки tenant в Action и обоих handler'ах не тронуты
- [x] Тесты — `make site-test` зелёный: 3141 тестов, 17223 утверждения
- [x] CS-Fixer — baseline красный: те же 9 файлов из 9 были красными на `41e0aaf1` до правки; новых нарушений нет, новый файл DTO чистый
- [x] PHPStan — N/A, в проекте не установлен
- [x] ARCHITECTURE.md обновлён

### External review
- Reviewer: Codex CLI 0.146.0 (`codex exec -s read-only --ephemeral`)
- Iterations: 2
- Result: REVIEW_GREEN
- Confirmed findings fixed:
  - IMPORTANT — `LogicException` → `CONFLICT` в `WbFinancialReportSyncStatusUpdater` закреплял конфликтный статус за программной ошибкой. Исправлено на `FAILED_FINAL` + отдельный тест.
  - IMPORTANT — не было теста полного PROD-маршрута (sync → safe cleanup → три step handler → статус дня). Добавлен интеграционный тест, доказан красным на старом коде.
- Rejected findings with reason:
  - BLOCKER — «`WbGeneratedRowsSafeReplaceService` всё ещё бросает `WbGeneratedRowsConflictException`». Отклонён: throw удалён коммитом `ad1b6303`, входящим в `stage_base_commit 41e0aaf1`, поэтому файл и не появляется в диффе. Во второй итерации содержимое сервиса передано ревьюеру целиком.
- Ограничения ревьюера: без доступа к шеллу, Git-истории, БД и PROD; дифф, факты с прода и содержимое неизменённых файлов переданы в промпте. Вердикт второй итерации — только строка `REVIEW_GREEN` без MINOR/FOLLOW-UP, то есть подтверждает отсутствие BLOCKER/IMPORTANT, но не является развёрнутым разбором.

### Команды для проверки
- `make site-test-unit`
- `make site-test`
- `make site-cs-check`

### Риски / на что обратить внимание ревьюеру
- Изменение статусной семантики: дни, которые раньше становились `conflict` из-за частичной переобработки, теперь `success`. Это и есть цель, но дашборды/выборки, фильтрующие `conflict`, увидят другое распределение.
- `LogicException` из шага теперь `FAILED_FINAL`, а не `CONFLICT`. Оба статуса терминальные и не перепланируются, retry-поведение не меняется.
- Строки с `document_id IS NOT NULL` по-прежнему не удаляются и не перезаписываются: условие `document IS NULL` во всех DELETE не тронуто.

### Открытые вопросы
- Ремонт PROD-состояния (31 день `conflict`, 93 сообщения failed-очереди) и судьба 199 дней `failed` за январь–апрель — Production Gate, отдельное разрешение Владельца.
