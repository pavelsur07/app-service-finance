# 429 в OzonAccrualClient → ConnectorRateLimitedException

## Context

Сейчас `OzonAccrualClient::classifyStatus()` (`site/src/Ingestion/Infrastructure/Api/Ozon/OzonAccrualClient.php:293-295`) на HTTP 429 бросает `ConnectorTransientException` — это уводит сообщение в общий messenger-retry и при исчерпании ретраев даёт алерт. Хендлер `RunSyncChunkHandler` (`site/src/Ingestion/MessageHandler/RunSyncChunkHandler.php:146-171`) уже имеет отдельный, более мягкий путь для `ConnectorRateLimitedException`: до `MAX_RATE_LIMIT_ATTEMPTS` отложенных продолжений с `retryAfterSeconds`, warning-логи, fail только при полном исчерпании. Ozon Performance и WB клиенты уже используют этот путь — приводим accrual-клиент к консистентности.

Проверено: исключение из клиента проходит через `OzonSellerReportConnector` без перехвата прямо в хендлер. `messenger.yaml` не трогаем.

## Изменения

### 1. `site/src/Ingestion/Infrastructure/Api/Ozon/OzonAccrualClient.php`

- В `requestJson()` после получения ответа взять заголовки: `$headers = $response->getHeaders(false);` и передать их в `classifyStatus()`.
- В `classifyStatus()` ветку 429 заменить:

```php
if (429 === $statusCode) {
    throw new ConnectorRateLimitedException(
        sprintf('Ozon accrual API rate limit for %s.', $endpoint),
        $this->retryAfterSeconds($headers),
    );
}
```

- Добавить приватный `retryAfterSeconds(array $headers): int`: читает `retry-after` (ключи из `getHeaders()` в lowercase), если значение `ctype_digit` → `max(1, (int) $value)`, иначе дефолт `self::DEFAULT_RETRY_AFTER_SECONDS = 120` (как у `OzonPerformanceReportClient.php:409`). Даты в Retry-After не парсим — Ozon шлёт секунды либо ничего (ponytail: дефолт покрывает).
- Импорт `App\Ingestion\Exception\ConnectorRateLimitedException` (существующий класс, конструктор `(string $message, int $retryAfterSeconds, ?\Throwable $previous)`).

### 2. Тесты — `site/tests/Unit/Ingestion/Infrastructure/Api/Ozon/OzonAccrualClientTest.php`

- Переписать `testRateLimitStatusBecomesTransientException` → `testRateLimitStatusBecomesRateLimitedException`: MockResponse 429 c заголовком `Retry-After: 30` → ожидать `ConnectorRateLimitedException`, `retryAfterSeconds() === 30` (регрессионный: красный на старом коде).
- Второй кейс: 429 без заголовка → `retryAfterSeconds() === 120`.

## Риск

🟡 MEDIUM — изменение классификации ошибки внутри одного Infrastructure-клиента модуля Ingestion, не legacy-зона, без миграций/API/messenger.yaml. Один этап.

Поведенческое следствие (обратить внимание на ревью): 429 перестаёт идти через messenger-retry-strategy и переходит на continuation-путь хендлера с лимитом `MAX_RATE_LIMIT_ATTEMPTS` (12); при исчерпании job → FAILED с reason `rate_limit_exhausted_after_N_attempts` без алерта в GlitchTip — это и есть желаемое поведение.

## Верификация

- `make test -- --filter OzonAccrualClientTest`
- `make stan`, `make cs`
- Self-review checklist + Stage Report в `docs/tasks/ozon-accrual-429/stages/stage-1.md`, план продублировать в `docs/tasks/ozon-accrual-429/plan.md`
