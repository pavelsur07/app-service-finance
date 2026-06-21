## Stage 1: Reverse-proxy на шлюзе (app → Telegram API) — DONE

**Риск:** 🔴 HIGH
**Следующее действие:** 🛑 STOP, ждать Владельца (правка инфраструктуры шлюза + деплой на KZ-VPS).

### Что сделано
- `tg-gateway/nginx/tg.conf`: добавлен `location /bot-api/`, проксирующий на `https://api.telegram.org/` (через variable + `rewrite` + существующий `resolver`, чтобы покрыть и `/bot-api/file/bot.../...`).
- `tg-gateway/docker-compose.yml`: добавлен Traefik-роутер `tg-bot-api` (`Host(tg.vashfindir.ru) && PathPrefix(/bot-api/)`, TLS, переиспользует сервис `tg-webhook`) + middleware `ipallowlist.sourcerange=217.198.13.171/32`.

### Уточнение по реализации (важно)
IP-ограничение сделано **на уровне Traefik**, а не в nginx: перед `tg-nginx` стоит Traefik, поэтому nginx видит IP traefik, а не реальный клиентский. `ipAllowList` в Traefik фильтрует по настоящему source IP. В nginx `allow/deny` НЕ добавлял (был бы бесполезен/вреден за прокси).

### Затронутые файлы
- `tg-gateway/nginx/tg.conf` — modified (location /bot-api/)
- `tg-gateway/docker-compose.yml` — modified (router tg-bot-api + ipAllowList)

### Self-review
- [x] Scope compliance — только исходящий прокси на шлюзе
- [x] Доступ ограничен (`217.198.13.171/32`) — не открытый релей
- [x] Путь `/bot-api/` корректно стрипается (`rewrite ^/bot-api/(.*)$ /$1`), покрывает методы и `/file/...`
- [x] `docker compose -f tg-gateway/docker-compose.yml config` — валидно
- [x] `nginx -t` на изменённом tg.conf — синтаксис OK
- [x] Вход (`/telegram/webhook`) не затронут

### Команды проверки (после деплоя шлюза)
- С app-сервера (217.198.13.171): `curl -s "https://tg.vashfindir.ru/bot-api/bot<TOKEN>/getMe"` → `{"ok":true,...}`
- С другого IP: тот же запрос → `403` (Traefik ipAllowList)

### Риски / ревьюеру
- Деплой шлюза — отдельный пайплайн `deploy-tg-gateway.yml` (KZ-VPS). Нужно задеплоить ДО переключения `TELEGRAM_API_BASE_URL` в app (Stage 3).
- IP app-сервера зашит в compose (`217.198.13.171/32`). При смене IP выход сломается → обновлять label (follow-up: вынести в переменную деплоя).

### Открытые вопросы
- нет.
