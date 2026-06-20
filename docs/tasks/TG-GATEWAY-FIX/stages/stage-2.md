## Stage 2: Nginx config fix — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously

### Что сделано
- Добавлен `proxy_ssl_verify off` (явно, ранее было имплицитно)
- Уменьшен `proxy_connect_timeout` с 10s до 5s — быстрее обнаруживается недоступность upstream
- Добавлены `proxy_intercept_errors on` + `error_page 502 503 504 = @upstream_error`
- Добавлен location `@upstream_error` с JSON-ответом в формате стандарта проекта
- `error_log` переведён на уровень `warn` (убирает info-шум)

### Затронутые файлы
- `tg-gateway/nginx/tg.conf` — modified

### Self-review
- [x] Scope compliance
- [x] JSON формат ошибки соответствует стандарту (`{"error":{"code":"...","message":"..."}}`)
- [x] `proxy_intercept_errors on` совместим с `proxy_buffering off`
- [x] Nginx синтаксис корректен

### Команды для проверки
- На сервере: `docker exec tg-gateway-nginx nginx -t`

### Риски / на что обратить внимание
- `proxy_intercept_errors on` перехватывает только 4xx/5xx от upstream, не от nginx
- Если upstream недоступен и соединение зависает (не TCP refused) — клиент всё равно ждёт `proxy_connect_timeout` (5s)

### Открытые вопросы
- нет
