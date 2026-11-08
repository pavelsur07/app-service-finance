# ai-task — CHANGELOG

## v1.2.0 (2026-11-08)

`categorize_payload.categories[].description` — необязательное описание статьи
(«что входит и почему», до 500 символов). Изменение аддитивное: payload без поля
валиден, producer без поля работает как раньше.

## v1.1.0 (2026-10-30)

Добавлены `categorize_payload` / `categorize_output` — задача
`categorize_transactions` (подсказка статьи ДДС, этап 2). Конверты не менялись:
изменение аддитивное.

## v1.0.0 (2026-10-30)

Первая версия: конверты API задач ai-service — постановка с `Idempotency-Key`,
статус, результат, страница результатов с курсором, ack. Диагностическая
задача `echo`. Producer результатов — `services/ai-service`; consumer —
шлюз Symfony (`ServiceGateway`). Этап 1 ТЗ `docs/tasks/ai/`.
