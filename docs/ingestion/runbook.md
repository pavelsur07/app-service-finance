# Ingestion Runbook — инструкция для саппорта

> Перед ручными операциями прочитайте
> [`docs/maintenance/production-logging.md`](../maintenance/production-logging.md).
> Относительные пути вывода и сохранение логов, backup или audit-файлов в `/root` запрещены.

## Доступ

Все команды выполняются на production сервере:

```bash
ssh root@vf-app
cd /srv/2bstock-app/current

# Алиас для psql
alias psql='docker exec -i symfony-postgres psql -U app -d app'
```

---

## 1. Диагностика — быстрый осмотр

### Статус загрузок по компании

```sql
SELECT status, count(*), max(updated_at)
FROM ingest_sync_jobs
WHERE company_id = 'UUID'
GROUP BY status
ORDER BY status;
```

### Статус нормализации raw записей

```sql
SELECT normalization_status, count(*)
FROM ingest_raw_records
WHERE company_id = 'UUID'
GROUP BY normalization_status;
```

### Канон — что загружено

```sql
SELECT type, count(*), sum(amount_minor)/100 as total_rub
FROM ingest_financial_transactions
WHERE company_id = 'UUID'
GROUP BY type
ORDER BY count(*) DESC;
```

### Открытые проблемы нормализации

```sql
SELECT kind, count(*), max(created_at)
FROM ingest_normalization_issues
WHERE company_id = 'UUID'
  AND resolved_at IS NULL
GROUP BY kind;
```

### Грязные периоды P&L

```sql
SELECT period_year, period_month, status, reason, marked_at
FROM pnl_dirty_periods
WHERE company_id = 'UUID'
ORDER BY period_year DESC, period_month DESC;
```

---

## 2. Запуск первичной загрузки (backfill)

Используется при первом подключении компании к новому пайплайну.

### Шаг 1 — получить UUID подключения

```sql
SELECT id, client_id, marketplace, is_active
FROM marketplace_connections
WHERE company_id = 'UUID'
  AND marketplace = 'ozon'
  AND is_active = true;
```

### Шаг 2 — запустить backfill

```bash
docker exec -it scheduler php /app/bin/console app:ingestion:start-backfill \
  --env=prod \
  --company-id=UUID_КОМПАНИИ \
  --connection-ref=UUID_ПОДКЛЮЧЕНИЯ \
  --source=ozon \
  --days-back=30 \
  --no-interaction \
  -vv
```

**Параметры:**
- `--days-back=30` — начать с 30 дней (рекомендуется для первого запуска).
- `--resource-type=ozon_finance_accrual_by_day` — начисления Ozon по дням (опционально).
- `--dry-run` — показать что будет создано без реального запуска.

### Шаг 3 — мониторинг

```bash
# Fetch воркер (HTTP к Ozon)
docker logs -f --tail=200 site-messenger-worker-sync

# Pipeline воркер (нормализация)
docker logs -f --tail=200 site-messenger-worker-pipeline
```

```sql
-- Прогресс чанков
SELECT status, count(*), max(updated_at)
FROM ingest_sync_jobs
WHERE company_id = 'UUID'
GROUP BY status;
```

---

## 3. Запуск регулярного инкремента вручную

Используется если автоматический cron не отработал.

```bash
docker exec -it scheduler php /app/bin/console app:ingestion:run-incremental \
  --env=prod \
  --source=wildberries \
  --company-id=UUID \
  --limit=1 \
  --no-interaction \
  -vv
```

`--source` можно опустить, чтобы запустить все поддержанные источники, или указать
`ozon` / `wildberries` для точечной проверки. Ozon cursor seed-ится из legacy cursor
или первого дня месяца. WB finance cursor seed-ится от последнего raw report date + 1 день,
а если истории нет — с первого дня текущего месяца.

---

## 4. Проблемы нормализации

### Найти что попало в OTHER

```sql
SELECT
    source_data->>'operation_type' as operation_type,
    source_data->>'operation_type_name' as operation_type_name,
    count(*),
    sum(amount_minor)/100 as total_rub
FROM ingest_financial_transactions
WHERE company_id = 'UUID'
  AND type = 'other'
GROUP BY
    source_data->>'operation_type',
    source_data->>'operation_type_name'
ORDER BY count(*) DESC;
```

Если появились новые `operation_type` — передать разработчику для добавления в `OzonCostCategory`.

### Перезапустить нормализацию для конкретных записей

```sql
-- Сбросить статус raw записей с OTHER транзакциями
UPDATE ingest_raw_records
SET normalization_status = 'pending'
WHERE company_id = 'UUID'
  AND id IN (
    SELECT DISTINCT raw_record_id
    FROM ingest_financial_transactions
    WHERE company_id = 'UUID'
      AND type = 'other'
  );
```

Далее cron `app:ingestion:normalize-pending` подберёт их автоматически (каждые 10 минут),
или запустить вручную:

```bash
docker exec -it scheduler php /app/bin/console app:ingestion:normalize-pending \
  --env=prod --limit=100 --no-interaction
```

### PENDING записи зависли (нормализация не идёт)

```sql
SELECT count(*), min(fetched_at), max(fetched_at)
FROM ingest_raw_records
WHERE normalization_status = 'pending'
  AND fetched_at < NOW() - INTERVAL '15 minutes';
```

Если есть — проверить воркер `site-messenger-worker-pipeline`:

```bash
docker logs --tail=50 site-messenger-worker-pipeline
```

Если воркер упал — перезапустить:

```bash
docker restart site-messenger-worker-pipeline
```

---

## 5. Проблемы с авторизацией (ConnectorAuthException)

### Симптом

```sql
SELECT id, last_error, status, updated_at
FROM ingest_sync_jobs
WHERE company_id = 'UUID'
  AND status = 'failed'
  AND last_error LIKE '%auth%'
ORDER BY updated_at DESC
LIMIT 5;
```

### Диагностика

```sql
-- Проверить credentials
SELECT connection_ref, key_version, expires_at, created_at
FROM ingestion_credentials
WHERE company_id = 'UUID';
```

```sql
-- Проверить legacy подключение
SELECT id, api_key IS NOT NULL as has_key, client_id, is_active, updated_at
FROM marketplace_connections
WHERE company_id = 'UUID'
  AND marketplace = 'ozon';
```

### Решение

1. Попросить клиента обновить API-ключ в личном кабинете Ozon.
2. Обновить в legacy `marketplace_connections` через интерфейс «Маркетплейсы → Интеграции».
3. Перезапустить backfill для компании.

---

## 6. Закрытый период получил новые данные

### Симптом

```sql
SELECT period_year, period_month, status, last_error, marked_at
FROM pnl_dirty_periods
WHERE company_id = 'UUID'
  AND status = 'blocked_by_close';
```

### Варианты решения

**Вариант А: Провести корректировку в текущем периоде**

Данные за закрытый период не пересчитывать. Разница отражается корректировкой в текущем месяце.
Решение принимает бухгалтер/владелец компании.

**Вариант Б: Переоткрыть период и пересчитать**

Только с согласия клиента и если период не сдан в налоговую.

```sql
-- Переоткрыть dirty period
UPDATE pnl_dirty_periods
SET status = 'pending', last_error = NULL, updated_at = NOW()
WHERE company_id = 'UUID'
  AND period_year = YYYY
  AND period_month = MM
  AND status = 'blocked_by_close';
```

Далее нужно переоткрыть `MarketplaceMonthClose` или обнулить `financeLockBefore`
через интерфейс «Закрытие месяца». Пересчёт запустится автоматически.

Каждое такое действие логировать в AuditLog с причиной.

---

## 7. Ручной пересчёт P&L периода

```bash
# Пометить период грязным (сбросить в PENDING)
docker exec -it symfony-postgres psql -U app -d app -c \
  "UPDATE pnl_dirty_periods
   SET status = 'pending', updated_at = NOW()
   WHERE company_id = 'UUID'
     AND period_year = 2026
     AND period_month = 6;"
```

Пересчёт запустится автоматически через воркер.
Проверить результат:

```sql
SELECT status, rebuilt_at, last_error
FROM pnl_dirty_periods
WHERE company_id = 'UUID'
  AND period_year = 2026
  AND period_month = 6;
```

---

## 8. Просмотр raw payload операции

Используется для диагностики проблем маппинга.

```sql
-- Найти raw_record_id для транзакции
SELECT raw_record_id, type, source_data->>'operation_type', amount_minor/100
FROM ingest_financial_transactions
WHERE company_id = 'UUID'
  AND external_id LIKE 'ozon:operation:OPERATION_ID%'
LIMIT 5;
```

```sql
-- Посмотреть метаданные raw записи
SELECT id, storage_path, normalization_status, fetched_at, byte_size
FROM ingest_raw_records
WHERE id = 'RAW_RECORD_UUID';
```

Файл лежит по пути `storage_path` в `var/storage` (или S3 если включён).

**Каждый просмотр raw payload логировать** (компания, что искали, причина).

---

## 9. Сброс cursor (перезагрузить с начала)

Осторожно: следующий инкремент начнётся заново от нуля.

```sql
-- Посмотреть текущий cursor
SELECT cursor_value, last_fetched_at, updated_at
FROM ingest_cursors
WHERE company_id = 'UUID'
  AND connection_ref = 'UUID_ПОДКЛЮЧЕНИЯ'
  AND resource_type = 'ozon_finance_accrual_by_day';
```

```sql
-- Сбросить cursor
UPDATE ingest_cursors
SET cursor_value = '', updated_at = NOW()
WHERE company_id = 'UUID'
  AND connection_ref = 'UUID_ПОДКЛЮЧЕНИЯ'
  AND resource_type = 'ozon_finance_accrual_by_day';
```

После сброса запустить backfill.

---

## 10. Ozon accrual: canonical расходится с latest raw (rolling refresh / prune)

### Симптом

- `verify-rolling-refresh` завершился с FAIL: `amountMismatches` / `countMismatches` / `unknownCategoryRows` / `failedTargets` > 0;
- в UI / P&L суммы по Ozon завышены относительно кабинета Ozon.

### Причина

Ozon меняет финансовые начисления задним числом. Rolling refresh каждую ночь
пере-fetch'ит последние 45 дней и сохраняет новые raw snapshots. Строки нового
snapshot upsert-ятся, а строки, исчезнувшие из нового snapshot, остаются
в `ingest_financial_transactions` от старых overlapping raw — их убирает prune.

**Автоматика:** при каждой успешной нормализации нового accrual-by-day raw
prune выполняется автоматически (`NormalizeRawRecordAction` →
`OzonAccrualStaleProjectionPruner`). Ручной prune нужен только для repair:
исторические расхождения или случаи, когда нормализация нового raw не прошла.

### Ночная цепочка (cron, `docker/cron/app.cron`)

| Время MSK | Команда |
|---|---|
| 02:30 | `ozon-accrual:rolling-refresh --days-back=45 --execute` (fetch, нормализация async → auto-prune) |
| 05:15 | `ozon-accrual:daily-maintenance --days-back=45 --execute` |
| 05:45 / 06:30 | `ozon-accrual:reconcile-financial-projection --days-back=30 --execute` |
| 06:45 | `ozon-accrual:verify-rolling-refresh --days-back=45` (read-only контроль) |

FAIL verify виден в логах scheduler: `docker logs scheduler 2>&1 | grep verify-rolling-refresh`.

### Процедура repair (безопасный порядок)

**Шаг 1 — verify (read-only), локализовать shop:**

```bash
php -d memory_limit=1G bin/console app:ingestion:ozon-accrual:verify-rolling-refresh \
  --days-back=45 --company-id=UUID
```

В таблице результата найти строки со статусом `fail` — запомнить `shopRef`.

**Шаг 2 — dry-run prune (ничего не удаляет):**

```bash
php -d memory_limit=1G bin/console app:ingestion:ozon-accrual:prune-stale-projection \
  --days-back=45 --company-id=UUID --shop-ref=SHOP_REF --dry-run
```

Печатает stale строки, сгруппированные по дате / типу / направлению, с суммами.
Сверить глазами: удаляться должны только строки из **старых** raw (`staleRaw`,
`staleFetchedAt` старше latest snapshot).

**Шаг 3 — execute:**

```bash
php -d memory_limit=1G bin/console app:ingestion:ozon-accrual:prune-stale-projection \
  --days-back=45 --company-id=UUID --shop-ref=SHOP_REF --execute
```

Удаляет только строки, отсутствующие в latest mapped raw. Перед DELETE повторно
проверяет, что строка всё ещё принадлежит старому raw (защита от гонки с
параллельной нормализацией). После удаления диспатчит `NormalizationCompletedEvent`
→ затронутые периоды P&L помечаются грязными и пересчитываются. Команда
идемпотентна — повторный запуск безопасен.

**Шаг 4 — verify повторно (как шаг 1):** все метрики должны стать 0, статус `ok`.

### Fallback: OOM / большие shops

Полный прогон упирался в память из-за больших raw payload. Порядок действий:

1. Всегда `php -d memory_limit=1G` (при необходимости `2G`).
2. Сузить scope: `--company-id` + `--shop-ref` (по одному shop за раз).
3. Маленькие окна вместо `--days-back=45` — идти по 7 дней:

```bash
php -d memory_limit=1G bin/console app:ingestion:ozon-accrual:prune-stale-projection \
  --from=2026-06-01 --to=2026-06-07 --company-id=UUID --shop-ref=SHOP_REF --execute
# затем --from=2026-06-08 --to=2026-06-14 и т.д.
```

4. У verify есть `--raw-limit` (raw записей на shop) и `--raw-row-limit`
   (строк на raw payload) — снизить при OOM на verify.

### Контрольная SQL-проверка (опционально)

```sql
-- stale транзакции: canonical строки, чей raw перекрыт более свежим snapshot
SELECT DATE(ft.occurred_at) AS date, ft.type, ft.direction,
       count(*), sum(ft.amount_minor)/100 AS total_rub,
       old_raw.external_id AS stale_raw, old_raw.fetched_at
FROM ingest_financial_transactions ft
JOIN ingest_raw_records old_raw ON old_raw.id = ft.raw_record_id
WHERE ft.company_id = 'UUID'
  AND ft.shop_ref = 'SHOP_REF'
  AND ft.source = 'ozon'
  AND old_raw.resource_type = 'ozon_finance_accrual_by_day'
GROUP BY 1, 2, 3, 6, 7
ORDER BY 1, 7;
```

---

## 11. Мониторинг воркеров

```bash
# Статус всех воркеров
docker ps | grep worker

# Логи конкретного воркера
docker logs -f --tail=100 site-messenger-worker-sync
docker logs -f --tail=100 site-messenger-worker-pipeline

# Перезапустить воркер
docker restart site-messenger-worker-pipeline
```

### Failed сообщения

```sql
-- Сообщения в failed транспорте (если Doctrine transport)
SELECT id, queue_name, created_at, available_at, delivered_at,
       LEFT(body, 300) AS body,
       LEFT(headers, 500) AS headers
FROM messenger_messages
WHERE queue_name = 'failed'
ORDER BY created_at DESC
LIMIT 20;
```

---

## 12. Часто встречающиеся ошибки

| Ошибка | Причина | Решение |
|---|---|---|
| `skippedWithoutCursor: N` | Backfill не запускался | Запустить `start-backfill` |
| `ConnectorAuthException` | Протух API-ключ | Обновить в `marketplace_connections` |
| `SUM_MISMATCH` в issues | Расхождение контрольных сумм | Проверить маппинг, передать разработчику |
| `MAPPER_FAILURE` в issues | Неизвестный тип операции | SQL на `OTHER`, передать разработчику |
| `BLOCKED_BY_CLOSE` | Данные за закрытый период | Согласовать с клиентом вариант А или Б |
| `PENDING_LISTING` в enrichmentStatus | Листинг не найден в каталоге | Дождаться синхронизации каталога |
| `PENDING_COGS` в enrichmentStatus | Нет закупочной цены | Попросить клиента внести себестоимость |
| `verify-rolling-refresh` FAIL (`amountMismatches` > 0) | Ozon изменил начисления задним числом, stale строки от старых raw | п.10: dry-run → execute prune → verify |

---

## 13. Контакты

- Баг в маппинге (новый `operation_type`) → задача разработчику с SQL-результатом.
- Проблема с AWS/S3 → DevOps.
- Закрытый период → согласование с клиентом, затем п.6.
- Критичная потеря данных → эскалировать немедленно.
