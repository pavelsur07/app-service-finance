# Stage 2 Report: загрузчик различает исходы, handler ведёт статус

Сделано: `OzonRealizationFetcher` — HTTP-статусы → типизированные исключения (429 + Retry-After, 401/403, 5xx, прочие 4xx = «ещё не готово»), контракт `fetch()` прежний (исключения наследуют `\RuntimeException`);
`SyncOzonRealizationHandler` — статус пары ведётся в `marketplace_financial_report_sync_statuses`: пустой ответ/4xx → `empty` + повтор через час, 429 → `failed` с `nextRetryAt` из Retry-After (минимум минута), 5xx/сеть → `failed` через 30 минут, 401/403 → `auth_failed` (терминально, не инцидент),
строки → документ сохраняется/перезаписывается, `raw_loaded` + хеш строк. Пустой ответ не затирает уже загруженный документ. Повторы ведёт опрос, а не Messenger (исход не пробрасывается: два механизма повторов дали бы дубли запросов); неожиданные исключения логируются `error`, статус `failed` с повтором.
Попутно: `$existing instanceof MarketplaceRawDocument` вместо `object` — из `phpstan-baseline.neon` убраны 4 записи (24 строки) для этого файла (baseline сократился).
Проверки: integration handler 9 OK (строки/перезапись/хеш, пустой ответ, не затирает документ, 404, 429 с Retry-After, 503 и сетевой сбой, 403, форма запроса, неактивное подключение); модуль Marketplace/Ozon unit+integration 941 OK; PHPStan `src/Marketplace` — 0; cs — 0; `lint:container` — OK.
Internal review: BLOCKER/IMPORTANT нет.
