### Stage 3: диагностика кабинета — DONE

Risk: HIGH-LOCAL. Stage base: `58c4f2c65`. Work items:3.1,3.2.

Реализованы durable run/rows, shared evaluator preview/diagnosis, source keyset и input fingerprint через MarketplaceFacade, async_pipeline handler с транзакциями<=100. Cursor/results/counters фиксируются вместе; replay возвращает completed, failed retry продолжает cursor. Последний completed сохраняется при partial/failure/stale. SQL только хеширует данные и фильтрует сохранённые причины, не повторяет алгоритм маппинга.

DoD: packet limit/retry/failure/last-completed/in-flight drift/staleness/scoping/filters подтверждены интеграционными тестами. 205 строк в3 реальных транзакциях (test trigger ограничивает100). Сбой второго пакета сохраняет100 и не заменяет предыдущий completed, retry завершается без дублей. Изменение source/catalog/settings/assignments/reviews помечает report stale. Предыдущий cached rule preview считается expired (RED503→GREEN).
Checks: module461 tests/1803 assertions OK; focused PHPStan MoySklad+Facade+tests OK; style исправлен project fixer; migration Version20261116122000 успешно применена test DB.
Internal:1 проход, BLOCKER0/IMPORTANT1 (old-preview version503, исправлено regression RED→GREEN); остальные ошибки — typing tests, исправлены. Проверены company scope/FK, bounded storage, locks, failure status после rollback и отсутствие write разрешения от report.
External: Claude launch failed HTTP529 Overloaded; автоматический fallback отдельная read-only ephemeral сессия Codex по docs/workflow/external-review.md, результат REVIEW_GREEN,1 завершённый раунд, BLOCKER0/IMPORTANT0; отдельный неуспешный launch Claude529.
