# TG-GATEWAY-FIX — Исправление сервиса tg-gateway (404 / self-signed cert)

## Диагностика

| Проверка | Результат |
|---|---|
| DNS `tg.vashfindir.ru` | `217.179.51.182` — Kazakhstan, OK |
| HTTP :80 | 301 → HTTPS — Traefik жив |
| HTTPS `/` | 404 от Traefik (no router — ожидаемо) |
| HTTPS `/telegram/webhook` | Timeout 10s — nginx не отвечает |
| TLS-сертификат | `CN=TRAEFIK DEFAULT CERT` — Let's Encrypt не выдал |
| `app.vashfindir.ru` | Let's Encrypt, `217.198.13.171`, отвечает |

## Корневые проблемы

**A — Self-signed cert**: ACME HTTP-01 challenge не завершился. Telegram не примет webhook.

**B — Timeout `/telegram/webhook`**: nginx не может дотянуться до `app.vashfindir.ru`
или контейнер unhealthy.

## Карта изменений

- `tg-gateway/nginx/tg.conf` — таймауты + 502/504 обработка
- `tg-gateway/docker-compose.yml` — Traefik healthcheck labels
- `.github/workflows/deploy-tg-gateway.yml` — init-certs + post-deploy check

## Этапы

| # | Название | Риск |
|---|---|---|
| 1 | Deploy script fix | 🟡 MEDIUM |
| 2 | Nginx config fix | 🟡 MEDIUM |
| 3 | Docker compose labels | 🟢 LOW |

## Не в scope

- Открытие порта 443 на `app.vashfindir.ru` для казахстанского IP — firewall на испанском сервере
- Ручная очистка `acme.json` при необходимости — операционный шаг
