# Stage 1: 429 → ConnectorRateLimitedException в OzonAccrualClient — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** Phase Final — 🛑 STOP, ждать Владельца (единственный этап)

## Что сделано
- `classifyStatus()` на HTTP 429 бросает `ConnectorRateLimitedException` вместо `ConnectorTransientException` — задействует существующий continuation-путь `RunSyncChunkHandler` (до 12 отложенных продолжений, без алерта в GlitchTip).
- `retryAfterSeconds` берётся из заголовка `Retry-After` (целые секунды), дефолт 120 сек — как у `OzonPerformanceReportClient`. HTTP-даты в Retry-After не парсим (Ozon шлёт секунды либо ничего).
- Регрессионные тесты: 429 с `Retry-After: 30` → 30 сек; 429 без заголовка → 120 сек. Оба красные на старом коде.

## Затронутые файлы
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonAccrualClient.php` — modified
- `site/tests/Unit/Ingestion/Infrastructure/Api/Ozon/OzonAccrualClientTest.php` — modified

## Self-review
- [x] Scope compliance — только ветка 429 + парсинг заголовка + тесты
- [x] Patterns / naming — по образцу WB/Performance клиентов
- [x] Forbidden actions — none (messenger.yaml не тронут, зависимостей нет)
- [x] Security (companyId, IDOR) — N/A, сигнатуры не менялись
- [x] CS-Fixer / tests — green (PHPStan в проекте не установлен — нет ни бинаря, ни composer-скрипта)
- [x] ARCHITECTURE.md — N/A (нет новых Facade/Enum/Entity)

## Команды для проверки
- `docker compose run --rm site-php-cli php bin/phpunit --filter OzonAccrualClientTest`
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --config .php-cs-fixer.dist.php src/Ingestion/Infrastructure/Api/Ozon/OzonAccrualClient.php`

## Риски / на что обратить внимание ревьюеру
- 429 больше не идёт через messenger retry-strategy: continuation-путь хендлера, лимит `MAX_RATE_LIMIT_ATTEMPTS` (12), при исчерпании job → FAILED c reason `rate_limit_exhausted_after_N_attempts` **без** алерта — это целевое поведение.
- Дефолт 120 сек — выбран по аналогии с Performance-клиентом; если Ozon начнёт слать HTTP-дату в `Retry-After`, применится дефолт (осознанное упрощение).

## Открытые вопросы
- нет
