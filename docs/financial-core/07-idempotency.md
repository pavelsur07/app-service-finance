# 07. Idempotency strategy

**Решение Stage 0.** Требование: `process(e) × N ≡ process(e)`.

## 1. Принцип

Доставка — at-least-once (Redis Streams + Messenger + sweeper'ы), поэтому
exactly-once достигается **идемпотентностью каждого звена**, а не гарантиями
транспорта. Идемпотентность обеспечивается ключом + unique-констрейнтом в БД,
а не проверкой «if exists» в коде (она не защищает от гонки; приём уже
применён в `StoreRawBatchAction` и Balance).

## 2. Ключи и констрейнты по звеньям

| Звено | Ключ | Констрейнт | Поведение при повторе |
|---|---|---|---|
| Запись события | `(company_id, idempotency_key)` | `UNIQUE` | возвращает существующий `event_id`; hash payload ≠ ⇒ `EventPayloadConflictException` (образец `request_hash`, Balance) |
| Outbox | `event_id` | `UNIQUE(event_id)` | одна строка на событие |
| Публикация | `event_id` в сообщении | — | дубль сообщения допустим; consumer идемпотентен |
| Processing | `(event_id, consumer)` | `UNIQUE` | `INSERT … ON CONFLICT DO NOTHING`, затем `SELECT … FOR UPDATE`; `done` ⇒ ack no-op; `blocked` (период/Balance закрыт, ADR-007) ⇒ ack no-op до смены условия; `running` с живым heartbeat ⇒ retry later; `running` протухший ⇒ перехват |
| Группа проводок | `(event_id, rule_code, rule_version)` | `UNIQUE` | `ON CONFLICT DO NOTHING` + сверка hash строк |
| Строка проводки | `(group_id, line_no)` | `UNIQUE` | то же |
| Сторно | `reverses_posting_id` | partial `UNIQUE` | повторное сторно невозможно |
| Проекция | ключ агрегата `(company, ledger, account, day, dims)` | `UNIQUE` | пересчёт целевого ключа заменяет значение (`ON CONFLICT DO UPDATE SET amount = <пересчитано из проводок>`), **не** `amount += delta` |
| Balance intake | `request_key = fincore:{group_id}` | `UNIQUE(company_id, request_key)` (существует) | тот же ключ+payload ⇒ вернёт существующий документ; другой payload ⇒ 409 |
| Replay | `replay_run_id` + те же ключи выше | аудит `fincore_replay_runs` | без побочных эффектов, кроме явного сторно при смене версии правила; соблюдает политику периода (`09 §3`) |
| Adjustment | `(company_id, idempotency_key)` события `fincore.adjustment.posted` | `UNIQUE` | повторное нажатие/доставка не создаёт вторую корректировку |

## 3. Какие consumers обязаны быть идемпотентными

Все: `posting`, `projection_pnl`, `projection_cash`, `projection_ar_ap`,
`balance_intake`, `reconcile`. Недопустимы в consumer'ах: `amount += x`,
`INSERT` без ключа, побочные эффекты наружу до записи processing=done без
своего ключа, чтение «текущего состояния» источника вместо payload.

## 4. Порядок и гонки

- Транспорт не гарантирует порядка. Гарантии ядра строятся на
  `source_revision` и `causation_id`:
  - событие ревизии N+1 при непроведённой ревизии N ⇒
    `RecoverableMessageHandlingException` (бэкоф), после лимита — DLQ;
  - событие ревизии ≤ уже проведённой для того же `source_id` ⇒
    статус `superseded`, без проводок.
- Одновременная обработка одного события двумя воркерами (потеря Redis-lock,
  R-10) безопасна: serialization через `FOR UPDATE` на processing-строке, а не
  через Redis.
- Одновременный пересчёт одной проекции — `pg_advisory_xact_lock(hash(company, ledger, day))`
  (в отличие от нынешнего `PLRegisterUpdater`, R-05).
- **`blocked` не равно `failed`.** Событие в закрытом периоде не ретраится и не идёт в DLQ: после перехода периода в `OPEN`, пере-датирования (новая ревизия) или оформления adjustment оно повторно ставится в очередь (`PostEventMessage` по тому же `event_id`), а идемпотентность гарантирует единственность результата.

## 5. Как это закрывает найденные риски

| Риск | Закрытие |
|---|---|
| R-13 дубль Document при повторе | событие с `idempotency_key` на источник; повторный клик не создаёт второго факта |
| R-12 unique только в миграциях | ключи ядра объявляются и в ORM, и в миграции; тест схемы сверяет |
| R-09 read-modify-write `succeeded_steps` | processing-строки на шаг + `FOR UPDATE` вместо JSON-поля (Stage 4) |
| дубль доставки Cash auto-rules | не затрагивается (не финансовый факт), остаётся естественная идемпотентность |
| R-14 запись в закрытый период | состояние периода проверяется оркестратором до проводок; повтор заблокированного события не создаёт проводок, пока условие не изменилось |

## 6. Тестовые требования (каждый Stage ядра)

1. `process(e)` ×3 подряд ⇒ число и содержимое строк проводок и проекций равны
   результату ×1.
2. Параллельный `process(e)` (два соединения) ⇒ одна группа.
3. Падение между «проводки записаны» и «processing=done» невозможно (одна
   транзакция) — тест через принудительное исключение перед commit.
4. Повтор после смены `rule_version` ⇒ сторно + новая группа, суммы по
   периоду корректны.
5. Дубль события с другим payload ⇒ `EventPayloadConflictException`.
