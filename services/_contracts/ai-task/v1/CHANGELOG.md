# ai-task — CHANGELOG

## v1.0.0 (2026-10-30)

Первая версия: конверты API задач ai-service — постановка с `Idempotency-Key`,
статус, результат, страница результатов с курсором, ack. Диагностическая
задача `echo`. Producer результатов — `services/ai-service`; consumer —
шлюз Symfony (`ServiceGateway`). Этап 1 ТЗ `docs/tasks/ai/`.
