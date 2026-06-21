# Phase 0 — Plan: TG-API-EGRESS-PROXY

> Статус: **черновик плана, ожидает одобрения Владельца (🛑 STOP).** До одобрения код не пишется.
> Источник: бриф в чате — бот принимает, но не отправляет; диагностика показала, что app-сервер (РФ) не достукивается до `api.telegram.org`.

## Контекст / проблема

Диагностика на проде:
- **Приём** (Telegram → `tg.vashfindir.ru` → app) — работает (`POST /telegram/webhook` → `{"status":"ignored"}`).
- **Отправка** (app → `api.telegram.org`) — **заблокирована**: `curl https://api.telegram.org` с app-сервера → `Connection timed out`.

Значит исходящие вызовы Telegram API (`sendMessage`, `editMessageText`, `getFile`, скачивание файла, а также `setWebhook`/`getWebhookInfo` из админки) с сервера в РФ не проходят. Поэтому бот не отвечает, а кнопка «Установить webhook» фактически уходила в таймаут.

Шлюз `tg-gateway` (KZ-VPS, `tg.vashfindir.ru`) имеет доступ к Telegram и уже проксирует **входящий** трафик. Нужно пустить через него и **исходящий**.

Цель: исходящие запросы к Telegram идут через reverse-proxy на существующем nginx шлюза; базовый URL Telegram API в приложении — конфигурируемый.

## Решение (подтверждено с Владельцем)
- **Шлюз:** существующий nginx (`tg-gateway/nginx/tg.conf`), **новый сервис не создаём** — добавляем один `location /bot-api/` → `https://api.telegram.org/`, ограниченный по IP app-сервера.
- **App:** новая env `TELEGRAM_API_BASE_URL` (дефолт `https://api.telegram.org`, прод → `https://tg.vashfindir.ru/bot-api`); заменить хардкод `https://api.telegram.org` в двух контроллерах.
- **IP app-сервера для allowlist:** `217.198.13.171`.

## Этапы

### Stage 1 — Reverse-proxy на шлюзе (направление «выход»)
**Риск:** 🔴 HIGH (правка инфраструктуры шлюза, публичный прокси на Telegram API, отдельный деплой `deploy-tg-gateway.yml` на KZ-VPS).
**Цель:** `https://tg.vashfindir.ru/bot-api/...` проксирует на `https://api.telegram.org/...`, доступ — только с app-сервера.

Карта изменений:
- `tg-gateway/nginx/tg.conf` — добавить:
  ```nginx
  location /bot-api/ {
      proxy_pass https://api.telegram.org/;
      proxy_ssl_server_name on;
      proxy_set_header Host api.telegram.org;
      proxy_connect_timeout 5s;
      proxy_read_timeout 30s;
      allow 217.198.13.171;   # app-сервер
      deny all;
  }
  ```
  (`resolver ... valid=30s;` уже есть в конфиге.)

Проверка/STOP: 🛑 ревью конфига шлюза + деплой на KZ-VPS. После деплоя — smoke:
`curl -sS -m 10 -o /dev/null -w "%{http_code}\n" "https://tg.vashfindir.ru/bot-api/bot<TOKEN>/getMe"` (с app-сервера → ожидаем `200`; с чужого IP → `403`).

### Stage 2 — Конфигурируемый базовый URL Telegram API в app
**Риск:** 🟡 MEDIUM (правка внутри Telegram-модуля, без миграций и смены публичного контракта).
**Цель:** убрать хардкод `https://api.telegram.org`, брать базу из конфигурации.

Карта изменений:
- `.env` — `TELEGRAM_API_BASE_URL=https://api.telegram.org` (нейтральный дефолт); `.env.test` — `https://api.telegram.org`.
- `config/services.yaml` — параметр `telegram.api_base_url: '%env(string:TELEGRAM_API_BASE_URL)%'` + bind `string $telegramApiBaseUrl` (как уже сделано для `telegram.webhook_url`).
- `src/Telegram/Controller/TelegramWebhookController.php` — заменить хардкод в: `sendMessage` (`respondWithMessage`), `editMessageText`, `getFile`, скачивание файла (`/file/bot%s/%s`). Базу инжектить в конструктор.
- `src/Telegram/Controller/Admin/TelegramBotController.php` — заменить хардкод в `setWebhook`, `getWebhookInfo`.
- URL'ы строить как `{base}/bot{token}/{method}` и `{base}/file/bot{token}/{path}`.

Тесты:
- Обновить/добавить: в существующих функциональных тестах база = `https://api.telegram.org` (через MockHttpClient проверяем, что вызываемый URL начинается с настроенной базы).
- Юнит/функционал на admin `setWebhook`: URL запроса использует `telegram.api_base_url`.

### Stage 3 — Прод-конфиг и cutover
**Риск:** 🔴 HIGH (прод-инфра + переключение реального трафика).
**Цель:** в проде app ходит в Telegram через шлюз; вебхук переустановлен.

Карта изменений:
- `docker-compose.prod.yml` — в якорь `x-php-env`: `TELEGRAM_API_BASE_URL: ${TELEGRAM_API_BASE_URL:-https://tg.vashfindir.ru/bot-api}`.

Порядок cutover (после деплоя app):
1. Перезапуск контейнеров с новой env.
2. Из админки `POST /admin/telegram/bots/webhook-set` (теперь setWebhook пойдёт через шлюз и реально дойдёт).
3. `getWebhookInfo` / «Проверить webhook» → `url`, `last_error_message` пусто.
4. Написать боту → должен ответить (проверка sendMessage через шлюз).

🛑 STOP перед cutover — ревью Владельцем.

## Порядок этапов
Stage 1 (шлюз, 🔴) → 🛑 STOP/деплой шлюза → Stage 2 (app, 🟡) → Stage 3 (прод-конфиг+cutover, 🔴) → 🛑 STOP. 
Важно: шлюзовой `/bot-api/` должен быть задеплоен ДО переключения `TELEGRAM_API_BASE_URL` в проде, иначе исходящие сломаются (хотя они и так не работают сейчас).

## Что НЕ трогаем
- Логику `/telegram/webhook` (приём) — она работает.
- Cash-модуль и прочее.

## Обновления ARCHITECTURE.md
- Зафиксировать параметр `telegram.api_base_url` и схему: исходящие Telegram-вызовы идут через `tg-gateway` (`/bot-api/`), вход — через `/telegram/webhook`.

## Безопасность
- `/bot-api/` ограничен `allow 217.198.13.171; deny all;` — не открытый релей. Если IP app-сервера сменится — обновить конфиг шлюза (follow-up: вынести в переменную деплоя). Альтернатива/усиление — секретный заголовок, проверяемый nginx (можно добавить позже).

## Открытые вопросы
1. IP app-сервера статичный? (`217.198.13.171`) — при смене сломается выход; нужен ли запасной механизм (секретный заголовок)?
2. Имя пути на шлюзе — `/bot-api/` ок, или предпочитаешь другое?

## Verification (e2e, после Stage 3)
- С app-сервера: `curl "https://tg.vashfindir.ru/bot-api/bot$TOKEN/getMe"` → `{"ok":true,...}`.
- Админка → «Установить webhook» → без ошибки; «Проверить webhook» → `url=https://tg.vashfindir.ru/telegram/webhook`, `last_error_message` пусто.
- Сообщение боту → приходит ответ; в логах app — `Telegram update получен`.
