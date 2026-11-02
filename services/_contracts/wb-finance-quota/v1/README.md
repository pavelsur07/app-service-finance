# wb-finance-quota v1

Общая квота WB finance `sales-reports/detailed` между `services/wb-fetcher` и импортом
Symfony. Лимит WB — 1 запрос в минуту на продавца; Symfony держит его лимитером
`limiter.wb_finance` (fixed window 1 / 70 с, Redis) и общим cooldown после 429, бакет
`connection:<id подключения>`. Сервис берёт слот в том же бакете перед каждым запросом к
WB и сообщает о каждом 429 — импорт Symfony и сервис не превышают лимит вместе.

Producer — Symfony (`Marketplace\Controller\Internal\WbFinanceSalesReports*Controller`),
consumer — wb-fetcher (`internal/quota`). Доступ — firewall `internal_api`
(`Authorization: Bearer <INTERNAL_AUTH_TOKEN>` + IP-allowlist). Ответы `no-store`.

## `POST /internal/wb-finance/sales-reports/slot`

Запрос: `{"connection_ref": "<UUID подключения WB>"}`.

| Ответ 200 | Смысл |
|---|---|
| `{"granted": true}` | слот взят, запрос к WB можно отправлять |
| `{"granted": false, "wait_seconds": 42, "shared_cooldown": false}` | слот занят (`shared_cooldown: true` — действует cooldown после 429); повторить через `wait_seconds` ≥ 1 |

## `POST /internal/wb-finance/sales-reports/rate-limited`

Запрос: `{"connection_ref": "<UUID>", "retry_after_seconds": 30}`; `retry_after_seconds` —
целое 1…86400 или `null` (пауза Symfony по умолчанию: 70 + 15 с).
Ответ 200: `{"cooldown_seconds": 30}`.

## Ошибки

422 `{"error": {"code": "invalid_request", "message": "…"}}` — не JSON, `connection_ref` не
UUID, `retry_after_seconds` вне диапазона. 401/403 — firewall.

Потребитель без ответа квоты (сеть, 5xx, 422) запрос к WB **не отправляет** и повторяет
задачу через минуту: без общей квоты неизвестно, свободен ли лимит продавца.
