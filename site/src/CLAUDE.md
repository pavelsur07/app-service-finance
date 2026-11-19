# CLAUDE.md — site/src (backend PHP)

> Продолжение корневого `CLAUDE.md`: разделы, нужные только при работе с
> backend-кодом. IDOR, транзакции, идемпотентность, глобальные запреты и гейты —
> в корневом файле.

## Транспорты Messenger

| Транспорт | Когда |
|---|---|
| `async_sync` | Внешние HTTP: маркетплейсы, банки, e-mail, MoySklad |
| `async_pipeline` | Локальная DB/CPU-обработка уже загруженных данных |
| `async_ads` | Ozon Performance polling, сообщение может работать до 10 мин |
| `async_wb_finance` | Только `SyncWbFinancialReportDayMessage`: ретраи до 20 раз под лимиты WB |
| `ingest_fetch` | Ingestion: загрузка из внешнего источника (DSN общий с `async_sync`) |
| `ingest_normalize` | Ingestion: нормализация сырых записей (DSN общий с `async_pipeline`) |

## Новый модуль

`src/{Module}` требует правок в `config/routes.yaml`,
`config/packages/doctrine.yaml`, `config/packages/twig.yaml` и при async
Messages — `messenger.yaml`. Готовые блоки — `docs/workflow/new-module.md`.

## Обработка ошибок

- Доменные исключения в `src/{Module}/Exception/`: `final class`, extends
  `\RuntimeException` или `AppException`, имя по причине
  (`DocumentNotFoundException`).
- Поток: Domain/Action бросает → ExceptionListener маппит в HTTP-статус и JSON
  → в JSON-контроллере нет `try/catch`, кроме технических кейсов (stream,
  lock, временный файл).
- HTML-контроллер с Twig-формой может ловить **конкретное** доменное
  исключение, чтобы показать flash или ошибку формы и сделать redirect: иначе
  пользователь получит 500 вместо сообщения. `catch (\Throwable)` /
  `catch (\Exception)` ради flash — нельзя; если такой catch нужен
  технически — `logger->error` с `companyId` до flash.
- Формат ответа для нового кода:

```json
{ "error": { "code": "document_not_found", "message": "Документ не найден" } }
```

`code` — стабильный snake_case-идентификатор, `message` — человекочитаемый,
HTTP-статус несёт семантику: 404 / 422 / 403 / 500.

```
try/catch с пустым телом          — глотать исключения нельзя
throw new \Exception('ошибка')    — только конкретные доменные классы
return null вместо исключения     — обязанная существовать сущность бросает
```

Паттерн — `PATTERNS.md` §13.

## Логирование

Sentry (GlitchTip) получает только `ERROR`+. Monolog — структурированные
INFO/DEBUG.

| Уровень | Когда |
|---|---|
| `ERROR` | Инцидент: неустранимый сбой, баг, повреждение данных — нужно будить человека |
| `WARNING` | Ожидаемо и обрабатывается само: retry, 429, таймаут, невалидный вход + skip |
| `INFO` | Бизнес-события: старт/финиш async-задачи, отправка документа, импорт завершён |
| `DEBUG` | Только локально |

- Messenger: `warning` + `RecoverableMessageHandlingException` пока ретраим;
  `error` + `UnrecoverableMessageHandlingException` только на неустранимом.
- Обязательно: старт и финиш каждого MessageHandler с ID и `companyId`;
  внешние HTTP — метод, URL, статус, время, без тела. В циклах — один
  агрегированный `error` со счётчиком, не по записи.
- Запрещено: пароли, токены, ключи даже в DEBUG; ФИО и ИНН без явного
  требования; тело ответа внешних API.
- Детали и примеры — `PATTERNS.md` §23; мониторы —
  `docs/workflow/health-gates.md`.

## Производительность

- N+1 чинить в текущем Work item; в Stage self-review проверяется явно.
- Списки только через Pagerfanta: `?page=1&limit=50`, максимум 200, сверх →
  422; `OutOfRangeCurrentPageException` → 422 в ExceptionListener.
- Query-класс: список для UI — отдаёт `QueryBuilder` под Pagerfanta; агрегат
  или отчёт (SUM, GROUP BY, проекции) — readonly-DTO или `list<DTO>`.
  Нетипизированный `array` из нового Query — нельзя.
- Новое FK-поле в Entity → индекс в миграции; составной `(company_id, field)`,
  если фильтр всегда по компании.
- Batch больше 100 записей — `flush()` каждые N итераций в вызывающем
  Action/Handler/Command (см. «Транзакции»). Query без `companyId` запрещён:
  IDOR и полный скан.
