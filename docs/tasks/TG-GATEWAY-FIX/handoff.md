# TG-GATEWAY-FIX — Handoff

## Summary

Исправлены три проблемы tg-gateway (диагностика через curl + анализ конфига):

### Stage 1 — Deploy script fix
Добавлена инициализация acme.json: если файл существует но сертификатов нет — удаляется для чистого старта Traefik. Let's Encrypt сертификат будет запрошен автоматически при следующем деплое. Добавлены post-deploy проверки: nginx /health, connectivity до upstream, Traefik ACME логи.

### Stage 2 — Nginx config fix
Добавлена обработка ошибок upstream: `proxy_intercept_errors on` + `error_page 502/503/504` → JSON-ответ вместо зависания клиента. Таймаут соединения снижен с 10s до 5s. Явно указан `proxy_ssl_verify off`.

### Stage 3 — Docker compose Traefik healthcheck
Добавлены Traefik LB healthcheck labels — Traefik теперь проверяет `/health` nginx-а перед роутингом трафика.

## Изменённые файлы

| Файл | Изменение |
|---|---|
| `.github/workflows/deploy-tg-gateway.yml` | init-certs step, post-deploy checks, label fix |
| `tg-gateway/nginx/tg.conf` | error_page, timeout, proxy_ssl_verify |
| `tg-gateway/docker-compose.yml` | Traefik healthcheck labels |

## Миграции БД
Нет.

## Изменения публичных контрактов
Нет. URL `/telegram/webhook` не изменился. Добавлен 502 JSON-ответ для случая недоступного upstream (ранее было зависание).

## Что НЕ исправлено (out of scope)

**Критично для работы сервиса:**
1. **Connectivity казахстанского сервера до `app.vashfindir.ru`** — deploy job покажет "⚠️ upstream недоступен" если проблема есть. Требует открытия порта 443 на испанском сервере для IP `217.179.51.182` (казахстанский сервер).

## Follow-up шаги после деплоя

1. **Запустить CI/CD** (push в master или `workflow_dispatch`) — deploy job автоматически сбросит acme.json и получит новый Let's Encrypt сертификат.

2. **Проверить лог deploy job** — смотреть на:
   - `✅ acme.json содержит 1 сертификат(а)` — сертификат получен
   - `✅ upstream app.vashfindir.ru доступен` — прокси работает
   - Если `⚠️ upstream недоступен` → нужно открыть firewall

3. **После получения сертификата** — зарегистрировать webhook в Telegram:
   ```
   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://tg.vashfindir.ru/telegram/webhook
   ```

4. **Проверить curl:**
   ```bash
   curl -X POST https://tg.vashfindir.ru/telegram/webhook \
     -H "Content-Type: application/json" \
     -d '{"update_id":1}'
   # Ожидается: {"status":"ok"}
   ```
