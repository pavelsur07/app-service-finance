# Handoff — частичная переобработка WB перестаёт быть ошибкой

Ветка: `codex/wb-partial-reprocess-not-failure-288`
Draft PR: https://github.com/pavelsur07/app-service-finance/pull/2306
Base commit: `41e0aaf1` (задеплоен на PROD)

## Итог по Stage

| Stage | Результат | Risk | Отчёт |
|---|---|---|---|
| 1 | Частичная переобработка WB завершается успехом; `CONFLICT` возвращён своему смыслу | 🟡 MEDIUM | `stages/stage-1.md` |

Work items: 1.1 DTO + Action, 1.2 step handler, 1.3 day handler, 1.4 потребители
и удаление исключения, 1.5 тесты, 1.6 `ARCHITECTURE.md`.

## Миграции

Нет. Схема БД не менялась, деструктивных операций нет.

## Изменённые контракты

- `ProcessMarketplaceRawDocumentAction::__invoke()` возвращает `ProcessRawDocumentResult` вместо `int` (внутренний контракт, все три потребителя адаптированы).
- Сводка `ReprocessMarketplacePeriodAction`: ключ `conflicts` → `partial_steps`; `linked_rows_preserved` без изменений. Потребители — UI-flash и CLI-вывод.
- Debug-API `POST /api/debug/reprocess-period`: в ответ шага добавлено поле `preserved_linked_rows`.
- Статусная семантика WB-дня: частичная переобработка → `SUCCESS` (было `CONFLICT`); `LogicException`/`InvalidArgumentException` из шага → `FAILED_FINAL` (было `CONFLICT`).
- Удалён публичный класс `App\Marketplace\Exception\WbGeneratedRowsConflictException`.

## Проверки

- `make site-test` — 3141 тестов, 17223 утверждения, зелёный.
- `make site-cs-check` — baseline красный до задачи; те же 9 из 9 затронутых файлов были красными на `41e0aaf1`, новых нарушений нет.
- Статический анализ — N/A, PHPStan в проекте не установлен.
- Внешнее ревью: Codex CLI 0.146.0, 2 итерации, `REVIEW_GREEN`.

## Риски

- Дни, ранее уходившие в `conflict` из-за частичной переобработки, теперь `success`: выборки и дашборды по `conflict` увидят другое распределение.
- Строки закрытых документов по-прежнему неизменяемы; при расхождении с актуальным ответом WB закреплённая строка остаётся старой — это видно только в warning-логе и в счётчике `linked_rows_preserved`.

## Follow-ups (сознательно вне scope)

1. **Production Gate**: переобработать 2026-06-30 … 2026-07-30 для компании `b57d7682…` — данные верны, требуется починка статуса.
2. **Production Gate**: удалить 93 протухших сообщения failed-очереди (`ProcessRawDocumentStepMessage`, старый текст «Cannot force reprocess»).
3. Отдельная задача: 199 дней в `failed` за 2026-01-05 … 2026-04-30 (шторм `MarketplaceRateLimitException` 22–30 мая, `attempts` до 163, `next_retry_at` в прошлом) — автоматически они уже не перезапустятся.
4. `runRefreshTwiceThroughFullFlow` в `WbFinancialReportSyncIdempotencyTest` из-за лимитера с одним токеном фактически не выполняет второй refresh: второй `syncHandler` только перекладывает день в `queued`. Тесты, опирающиеся на этот helper, проверяют меньше, чем обещают.
