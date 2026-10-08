# cash-p0-import-restore — handoff

**Branch:** `fix/cash-p0-import-restore` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2590 · **CI:** _см. PR_

## Summary of stages
- **Stage 1** — файловый импорт завершается и не обрезается на 200 строках.
  - Причина: в Doctrine ORM 3 `clear(X::class)` очищает весь UnitOfWork,
    поэтому после пачки и после финального flush отсоединялись компания,
    счёт и журнал.
  - Последствия на проде с 2026-02-08: каждый импорт с новыми строками
    зависал в `processing` (66 задач), файлы длиннее 200 строк обрезались
    (23 задачи).
  - Фикс: `detach()` только сущностей пачки.
- **Stage 2** — повторный номер документа больше не валит импорт: номер не
  пишется в `external_id`. Упавший импорт доходит до `failed` даже при
  закрытом EntityManager (`resetManager()`), пишется один лог без текста
  исключения.
- **Stage 3** — `app:cash:soft-delete-company-transactions --restore`
  восстанавливает только строки, удалённые самой командой. Ручные удаления
  остаются удалёнными.
- **Финальное ревью** — после падения импорта пересчитываются остатки по уже
  зафиксированным пачкам. Ошибки входных данных логируются как `warning`,
  пропуск повторной доставки виден в логе.
- PHPStan ловил дефект (`arguments.count`), но ошибки лежали в baseline.
  5 записей удалены: baseline только сократился.

## Files changed
- `site/src/Cash/Service/Import/File/CashFileImportService.php`
- `site/src/Cash/MessageHandler/Import/CashFileImportHandler.php`
- `site/src/Cash/Service/Import/ImportLogger.php`
- `site/src/Cash/Service/Import/File/CashFileImportFacade.php`
- `site/src/Cash/Repository/Transaction/CashTransactionRepository.php` — новый метод с `companyId`
- `site/src/Cash/MessageHandler/ApplyAutoRulesForTransactionHandler.php`,
  `EnqueueAutoRulesForRangeHandler.php` — `clear(X)` → `clear()`, поведение идентично
- `site/src/Cash/Command/SoftDeleteCompanyTransactionsCommand.php`
- `site/phpstan-baseline.neon` — −5 записей
- тесты:
  - новые: `CashFileImportTestCase`, `CashFileImportBatchBoundaryTest`,
    `CashFileImportHandlerFlowTest`;
  - дополнены: `CashFileImportHandlerTest`,
    `SoftDeleteCompanyTransactionsCommandTest`.

## Migrations
- none.

## Public API / contract changes
- none. Внутреннее изменение: у новых строк файлового импорта `external_id = NULL`,
  номер документа в `doc_number`. Диагностический отчёт
  `ReportCashflowOpsCheckController` ищет дубли таких строк своей запасной
  эвристикой.
- Поведение `--restore` сужено (см. Stage 3).

## Checks
- Регрессия «красный → зелёный» по каждому дефекту (подробности в `stages/stage-N.md`):
  - Stage 1: 6/6 red;
  - Stage 2: 3/3 red;
  - Stage 3: 3 red;
  - финальное ревью: 1 red (пересчёт) + мутационная проверка guard'а.
- `phpstan analyse` (конфиг гейта, 2 процесса) — No errors.
- `make site-cs-check` — 0 of 2834; `make site-cs-strict-types` — 0 of 2834.
- `make site-test` — OK (5695 tests, 32672 assertions).

## Reviews
- internal: по Stage — 3 итерации; финальное в свежем контексте — 1
  итерация, BLOCKER 0, IMPORTANT 2 (исправлены), MINOR 2 исправлены, 3
  оставлены с причиной.
- external: Codex, 1 раунд, `REVIEW_GREEN` (No BLOCKER or IMPORTANT findings), отклонённых находок нет.
- metrics: internal BLOCKER 0 / IMPORTANT 2; external BLOCKER 0 / IMPORTANT 0.

## Risks, limitations, follow-ups
- **Прод, отдельное одобрение §3.3 (SQL write), после деплоя:**
  - 43 задачи `processing` со всеми записанными строками → `done`, проставить
    `import_log.finished_at`;
  - 23 частичные задачи (ровно 200 строк, одна компания) → `failed` с
    сообщением; пересчитать остатки по затронутым счетам и попросить
    пользователя перезагрузить файлы (дубли пропустятся, догрузится остаток).
  - Точные `UPDATE` с числом строк — в отдельном запросе.
- Убитый воркер (OOM, SIGKILL, деплой посреди импорта) по-прежнему оставляет
  задачу в `processing`: нужен reaper. Теперь это хотя бы видно в логе как
  warning при повторной доставке.
- `errorMessage` задачи содержит текст DBAL-исключения с данными строки, это
  видно в UI той же компании (P1-2 аудита).
- Дубли внутри одной пачки файла не ловятся (было и до фикса).
- `AuditLog` из `postPersist` в ORM 3 не пишется — общий дефект приложения,
  проверить отдельно.
- 15 задач `queued` с февраля 2026; GlitchTip issue 414
  (`uniq_cts_tx_category`); один день без строки остатка у счёта `10d8d659…`.
- Мёртвые файлы `*.php_` в `Service/Import/File`.

## Owner decision
Ready: PR #2590 "fix(cash): P0 — file import stuck in processing, mass-restore scope" — merge into master with automatic production deploy?
Reply: "merge and deploy #2590"
