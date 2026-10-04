# 09. Failure model, recovery, reconciliation (целевые)

**Решение Stage 0.** Текущие сбои — `03`. Здесь — ожидаемое поведение ядра.

## 1. Failure model

| Сбой | Ожидаемое поведение | Что гарантирует |
|---|---|---|
| DB недоступна при записи операции | транзакция не коммитится: ни данных, ни события | атомарность INV-01 |
| DB недоступна в consumer'е | `Recoverable…` → ретраи с бэкофом; processing не меняется | нет частичных проводок (INV-04) |
| Брокер недоступен при публикации | событие и outbox-строка уже в БД; publisher ретраит, sweeper подберёт | потеря исключена |
| Брокер потерял сообщения (flush Redis, AOF-окно) | sweeper: `outbox.pending` и «события без processing старше SLA» перепубликуются | восстановление без участия человека |
| Падение воркера посреди события | транзакция откатилась; сообщение вернётся по `redeliver_timeout`; processing `running` перехватывается по heartbeat | повтор безопасен |
| Перезапуск процесса | то же | то же |
| Дубль события (двойная запись) | `UNIQUE(company, idempotency_key)` → возврат существующего | один факт |
| Дубль доставки | `UNIQUE(event, consumer)` + `done` ⇒ no-op | `process×N ≡ process×1` |
| Задержанное событие/нарушение порядка | `source_revision` и `causation_id`: «рано» ⇒ retry/бэкоф, «поздно» ⇒ `superseded` | порядок не требуется от транспорта |
| Невалидный payload / неподдержанная версия | `Unrecoverable` → processing `failed(invalid_payload)` → `fincore_failed`, ERROR (один агрегированный) | без ретраев вхолостую |
| Правило не найдено | `Unrecoverable`, `failed(no_rule)`; событие остаётся в журнале | попадает под replay после добавления правила |
| Закрытый период | processing `failed(period_locked)` либо `late_correction` по политике (Q4) | не пишем молча в закрытое |
| Downstream недоступен (Balance, проекция) | отдельный consumer/транспорт ретраится независимо; проводки уже зафиксированы | сбой периферии не откатывает факт |
| Timeout | `Recoverable`; транзакции короткие, внешние вызовы внутри транзакции запрещены | нет долгих блокировок |
| Частичная обработка (часть consumer'ов) | processing по `(event, consumer)` — видно, кто не сделал; повтор только его | гранулярное восстановление |

## 2. Recovery model

```text
retry  →  backoff  →  DLQ  →  correction  →  replay  →  reconciliation
```

1. **Retry/backoff** — стратегии `08 §2`. Различаем `Recoverable` (ретраим,
   `warning`) и `Unrecoverable` (сразу DLQ, `error`) — как в CLAUDE.md.
2. **DLQ** — `fincore_failed` (Doctrine). Каждая запись сопровождается
   processing-строкой со `status=failed`, `error_code`, `attempts`. Оператор
   видит причину в БД, не разбирая сериализованное сообщение. Команды
   (`fincore:dlq:list|show|retry|discard`) — `retry` и `discard` мутирующие,
   на проде только по §3.3 AGENTS.md.
3. **Correction** — исправляем причину: правило (`rule_version+1`), данные
   (новая ревизия события), справочник. Прямое редактирование проводок/событий
   невозможно.
4. **Replay** — повторная обработка событий (§3).
5. **Reconciliation** — независимая проверка результата (§4); расхождения
   записываются в `fincore_reconcile_findings`, повторяющиеся — алерт.

## 3. Replay

`app:fincore:replay --consumer=<c> (--event=<id> | --company=<id> --from=<date> --to=<date>) [--rule-version=N] [--execute]`

- **По умолчанию dry-run:** печатает, какие группы будут созданы/сторнированы и
  дельту по проекциям; `--execute` обязателен для записи (в духе
  `raw:prune --dry-run`).
- Безопасность: события неизменяемы, consumer'ы идемпотентны (`07`), проводки
  создаются через unique-ключи ⇒ повтор над тем же `rule_version` — no-op.
  Над новой `rule_version` — сторно прежней группы и новая группа в **одной
  транзакции на событие**.
- **Область replay применяется к записи, к сторнированию и к проекциям
  одинаково.** (Урок инцидента с реплеем, стёршим 19 дней: ограничение набора
  только на стороне записи, а гашение/удаление по полному набору — разрушительно.)
  Реализация: единый `ReplayScope` вычисляет множество событий один раз и
  передаётся во все фазы; тест — «сторно не выходит за scope».
- Replay пишет `fincore_replay_runs` (кто, scope, режим, счётчики до/после) и
  `metadata.origin=replay` в порождаемых outbox-строках.
- Запрещено: replay с удалением проводок; replay без `company_id`-ограничения;
  replay закрытого периода без политики Q4.
- **Что можно replay:** любое событие любого типа (факт неизменяем, правило
  детерминированно). **Что нельзя:** внешние побочные эффекты (письма,
  вызовы API) — они не живут в consumer'ах ядра; Balance intake безопасен
  благодаря `request_key`.

Снимки «до» (счётчики, суммы денежных полей) снимаются до любого
`--execute` (CLAUDE.md «Практики»); после — сверка инвариантов.

## 4. Reconciliation — каталог проверок

Правило гейтов: **область проверки = область repair**; свежесть, а не объём
(`docs/workflow/health-gates.md`). Реализуются инкрементально по Stage.

| Код | Проверка | Repair | Stage |
|---|---|---|---|
| E-01 | `outbox.pending` старше 5 мин | republish | 2 |
| E-02 | события `published` без processing старше 15 мин | republish | 2 |
| E-03 | processing `running` старше N мин без heartbeat | перехват | 3 |
| E-04 | `fincore_failed`/`failed` processing не пуст | DLQ-runbook | 3 |
| P-01 | проекция P&L = SUM(RECOGNITION по ключу) | пересчёт ключа | 5 |
| S-01 | shadow: `fincore_pnl_daily` vs `pl_daily_totals` за открытые периоды | расследование → правило/legacy-баг | 4–5 |
| C-01 | Σ CASH-проводок по счёту/дню = движение `money_account_daily_balance` | пересчёт остатков/replay | 6 |
| C-02 | каждая `cash_transaction` (не удалённая) имеет событие и CASH-проводку | republish/backfill | 6 |
| M-01 | закрытый месяц Marketplace: нет строк `marketplace_*` с `document_id IS NULL` по периоду; все дни `success` (закрывает R-04) | догрузка/rebuild | 1, 4 |
| A-01…A-03 | AR/AP: выручка/расход ↔ SETTLEMENT; аллокации ≤ платежа; остаток = документ − аллокации | replay/корректирующее событие | 7 |
| B-01 | на каждую `balance_mapped` группу — ровно одна операция Balance, суммы совпадают | повторный intake | 8 |
| X-01 | `financeLockBefore`: нет проводок в закрытом периоде без `late_correction` | расследование | 5 |

Результат — строки `fincore_reconcile_findings (check, company, window, severity,
detected_at, resolved_at)`; ERROR в Sentry — только по агрегату за прогон, не
по записи.

## 5. Runbook-контур (создаётся Stage 3)

`docs/financial-core/runbook.md`: как смотреть outbox/processing/DLQ,
какие команды read-only, какие мутирующие (и под каким согласием), решение
«retry vs correction vs replay», шаблон отчёта после реплея. Production-доступ —
только через разрешённые обёртки (`docs/maintenance/prod-access.md`).
