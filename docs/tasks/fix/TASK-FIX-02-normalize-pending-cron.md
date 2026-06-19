# TASK-FIX-02 — Cron-страховка для зависших PENDING нормализаций

## 0. Сводка

- **Бизнес-цель.** Если воркер `ingest_normalize` упал или Messenger потерял сообщение, `IngestRawRecord` остаётся со статусом `PENDING` навсегда. Cron каждые 10 минут подбирает зависшие записи и диспатчит `NormalizeRawRecordMessage` заново. Это делает нормализацию надёжной независимо от состояния очереди.
- **Модуль.** `App\Ingestion` (существующий).
- **Тип.** feature.
- **Ветка.** `feature/ingestion-normalize-pending-cron`.
- **Подзадачи.** B1 Repository-метод · B2 CLI-команда · B3 Cron-запись · B4 Тесты.
- **Затрагивает другие модули.** Нет.
- **Требует миграции БД.** Нет.
- **Меняет публичный API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- `App\Ingestion\Entity\IngestRawRecord` имеет поле `normalizationStatus: RawNormalizationStatus` (PENDING/DONE/FAILED).
- `App\Ingestion\Message\NormalizeRawRecordMessage` и `NormalizeRawRecordHandler` существуют (блок 5).
- `NormalizeRawRecordHandler` использует `IdempotentHandlerTrait` — повторная отправка того же `rawRecordId` безопасна.
- `App\Ingestion\Repository\IngestRawRecordRepository` существует, метода выборки зависших PENDING нет.
- Транспорт `ingest_normalize` уже настроен в `messenger.yaml`.

### 1.2 Желаемое состояние

- Cron каждые 10 минут находит `IngestRawRecord` со статусом `PENDING` и `fetchedAt < now - 15 минут` — диспатчит `NormalizeRawRecordMessage` для каждого.
- Лимит за один тик: 50 записей.
- Идемпотентность: повторный dispatch не создаёт дубликатов (гарантируется `IdempotentHandlerTrait` в handler'е).
- Логирование: количество диспатченных сообщений, companyId каждой записи.

### 1.3 In scope

- Новый Repository-метод `findStuckPending`.
- CLI-команда `app:ingestion:normalize-pending`.
- Запись в `docker/cron/app.cron`.

### 1.4 Out of scope

- Изменение `NormalizeRawRecordHandler` — не трогаем.
- Изменение `NormalizeRawRecordMessage` — не трогаем.
- Обработка статуса `FAILED` — отдельная задача.
- HTTP-эндпоинт для ручного запуска — не в этой задаче.

### 1.5 Допущения

- Допущение: порог «зависшей» записи = 15 минут (`fetchedAt < now - 15 min`). Параметр через `services.yaml` (`app.ingestion.pending_threshold_minutes: 15`).
- Допущение: лимит 50 — параметр опции `--limit` с default 50, max 200.

---

## 2. Доменная модель

### 2.1 Сущности

Без изменений. `IngestRawRecord` не меняется.

### 2.2 Связи

N/A.

### 2.3 Enum

N/A.

### 2.4 Матрица переходов

N/A.

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Ingestion\Repository\IngestRawRecordRepository` (правка)

Добавить один метод:

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findStuckPending(DateTimeImmutable $olderThan, int $limit): list<IngestRawRecord>` | Находит записи с `normalizationStatus=PENDING` и `fetched_at < $olderThan`. ORDER BY `fetched_at ASC`. Системный метод (все компании). | нет* | `list<IngestRawRecord>` |

*Системный метод воркера — осознанно по всем тенантам. `CompanyFilter` при вызове из CLI-контекста выключен (нет активной компании). Дополнительная защита: метод возвращает только поля, необходимые для dispatch (`id`, `companyId`).

### 3.2 Query

N/A.

### 3.3 Индексы

Добавить индекс в существующую таблицу `ingest_raw_records` через миграцию:

- `idx_ingest_raw_normalization_status_fetched` на `(normalization_status, fetched_at)` — для быстрой выборки зависших PENDING.

Миграция: `Version20260619100000.php` — только `CREATE INDEX`, zero-downtime.

---

## 4. Слой приложения

### 4.1 Action

N/A — команда диспатчит напрямую через `MessageBusInterface`.

### 4.2 Domain Service

N/A.

### 4.3 DTO

N/A.

### 4.4 CLI-команда

#### `App\Ingestion\Command\NormalizePendingRawRecordsCommand`

Файл: `src/Ingestion/Command/NormalizePendingRawRecordsCommand.php`. `final class`.

Имя команды: `app:ingestion:normalize-pending`.

Опции:
- `--limit=50` (int, default 50, max 200).
- `--threshold-minutes=15` (int, default 15).

Шаги:
1. Вычислить `$olderThan = now - $thresholdMinutes`.
2. `IngestRawRecordRepository::findStuckPending($olderThan, $limit)`.
3. Для каждой записи: dispatch `NormalizeRawRecordMessage($record->getId(), $record->getCompanyId())` в шину Messenger.
4. Лог INFO: `«Dispatched {N} normalize messages for stuck PENDING records»` + список `rawRecordId`.
5. Если `$count === 0` — лог DEBUG `«No stuck PENDING records found»`, exit 0.

Транзакционности нет (только dispatch). CLI-контекст без Session/Security/Request.

---

## 5. Асинхронность (Messenger)

Команда диспатчит существующее сообщение `App\Ingestion\Message\NormalizeRawRecordMessage`.

| Параметр | Значение |
|---|---|
| Message | `NormalizeRawRecordMessage` (существующий) |
| Transport | `ingest_normalize` (существующий) |
| Идемпотентность | гарантирована `IdempotentHandlerTrait` в `NormalizeRawRecordHandler` |
| Retry | настроен в transport (3 попытки, delay 5s) |

`messenger.yaml` — **не меняется**.

---

## 6. Обработка ошибок

| Класс | Когда | HTTP-статус | error.code | error.message |
|---|---|---|---|---|
| N/A (CLI) | Если `findStuckPending` выбросил исключение — команда логирует ERROR и завершается с exit code 1 | — | — | — |

Исключение при dispatch одного сообщения — логируется WARNING с `rawRecordId`, команда продолжает следующую запись (не прерывает весь batch).

---

## 7. HTTP API

N/A.

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | Метод `findStuckPending` + миграция индекса | — | 🔴 | integration: возвращает только PENDING старше порога |
| B2 | CLI-команда `NormalizePendingRawRecordsCommand` | B1 | 🟡 | integration: dispatch N сообщений, лог |
| B3 | Запись в `docker/cron/app.cron` | B2 | 🟢 | — |
| B4 | Тест идемпотентности | B2 | 🟢 | повторный запуск не дублирует нормализацию |

**B1 — детализация:**
- Создаёт: миграцию `site/migrations/Version20260619100000.php`.
- Меняет: `src/Ingestion/Repository/IngestRawRecordRepository.php` (добавить метод).
- DoD: `doctrine:schema:validate` зелёный, метод возвращает только записи старше порога.

**B2 — детализация:**
- Создаёт: `src/Ingestion/Command/NormalizePendingRawRecordsCommand.php`.
- DoD: `bin/console app:ingestion:normalize-pending --limit=10` диспатчит сообщения в in-memory transport (test env).

**B3 — детализация:**
- Меняет: `docker/cron/app.cron`.
- Добавить строку:
```
*/10 * * * * /usr/local/bin/php /app/bin/console app:ingestion:normalize-pending --limit=50 --no-interaction --quiet
```

---

## 9. Ограничения и запреты

- Не изменять `NormalizeRawRecordHandler` и `NormalizeRawRecordMessage`.
- Не изменять `messenger.yaml`.
- Не трогать записи со статусом `DONE` или `FAILED`.
- Миграция только `CREATE INDEX` — zero-downtime, не блокирует таблицу в PostgreSQL (CONCURRENTLY если нужно).
- Performance: лимит 50 за тик, ORDER BY `fetched_at ASC` — честная очередь FIFO.
- Безопасность: команда не принимает companyId извне, работает по всем тенантам системно.

---

## 10. Критерии приёмки

Функциональные:
- [ ] Команда находит `IngestRawRecord` с `PENDING` старше 15 минут и диспатчит сообщения.
- [ ] Записи со статусом `DONE`/`FAILED` не затрагиваются.
- [ ] Записи моложе 15 минут не затрагиваются.
- [ ] Повторный запуск команды не создаёт дублей нормализации.
- [ ] При `count=0` — exit 0, лог DEBUG.
- [ ] При ошибке dispatch одной записи — команда продолжает остальные.

Технические:
- [ ] `make site-test-unit` — зелёный.
- [ ] `doctrine:schema:validate --skip-sync --env=test` — зелёный.
- [ ] `lint:container --env=test` — зелёный.
- [ ] `php-cs-fixer` — зелёный.
- [ ] Cron-запись присутствует в `docker/cron/app.cron`.
- [ ] Лог содержит количество и rawRecordId (без payload).

---

## 11. План отката

- Удалить строку из `app.cron` — команда перестаёт запускаться.
- Удалить `NormalizePendingRawRecordsCommand` — команда недоступна.
- DROP INDEX `idx_ingest_raw_normalization_status_fetched` — отдельной миграцией.
- Данные не теряются — `IngestRawRecord` остаётся как есть.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь команды.
- [x] Repository-метод с сигнатурой и обоснованием отсутствия companyId.
- [x] Индекс с именем и обоснованием.
- [x] Cron-строка указана дословно.
- [x] Параметры команды с defaults.
- [x] Идемпотентность — гарантируется существующим handler'ом.
- [x] HTTP — N/A.
- [x] Out of scope: FAILED записи, HTTP-эндпоинт.
- [x] Миграция zero-downtime.
- [x] Plan отката без потери данных.
- [x] Обработка ошибок в batch (не прерывать весь тик).
- [x] messenger.yaml не меняется.
