## Stage 3: Прод-конфиг + cutover (выход через шлюз) — DONE (код), cutover — за Владельцем

**Риск:** 🔴 HIGH
**Следующее действие:** 🛑 STOP — деплой/cutover выполняет Владелец.

### Что сделано (код/конфиг)
- `docker-compose.prod.yml`:
  - в якорь `x-php-env` добавлен `TELEGRAM_API_BASE_URL: ${TELEGRAM_API_BASE_URL:-https://tg.vashfindir.ru/bot-api}` (покрывает php-fpm, cli, messenger-воркеры);
  - в env-блок `scheduler` добавлена та же переменная (для `app:telegram:send-reports`).
- Дозакрыт пропущенный хардкод: `SendTelegramReportsCommand` (`app:telegram:send-reports`) тоже использовал `https://api.telegram.org` напрямую → переведён на `$telegramApiBaseUrl` (в cron сейчас не стоит, но исправлено во избежание латентного бага).

### Подтверждено на проде (до cutover)
Шлюз Stage 1 уже задеплоен и работает:
```
curl "https://tg.vashfindir.ru/bot-api/bot$TOKEN/getMe"
→ {"ok":true,"result":{"id":8250137233,"username":"your_cfo_bot",...}}
```

### Затронутые файлы
- `docker-compose.prod.yml` — modified (x-php-env + scheduler)
- `site/src/Telegram/Command/SendTelegramReportsCommand.php` — modified (база API)

### Self-review
- [x] Scope compliance — прод-конфиг cutover + закрытие пропущенного хардкода
- [x] CS-Fixer — чисто; `docker compose -f docker-compose.prod.yml config` — валидно (переменная резолвится во все PHP-сервисы)
- [x] Тесты — `tests/Telegram/Functional` 7/7 (контейнер компилируется с новым bind команды)
- [x] PHPStan — N/A

### Cutover (выполняет Владелец, по порядку)
1. Смержить ветку → деплой app (`deploy.yml`).
2. (Опц.) задать `TELEGRAM_WEBHOOK_SECRET` в host-env.
3. Админка → «Установить webhook» (теперь setWebhook идёт ЧЕРЕЗ шлюз и реально дойдёт).
4. «Проверить webhook» → `url=https://tg.vashfindir.ru/telegram/webhook`, `last_error_message` пусто.
5. Написать боту → должен ответить; в логах app — `Telegram update получен`.

### Риски / ревьюеру
- 🔴 После деплоя весь Telegram-трафик (вход и выход) идёт через `tg.vashfindir.ru`. Если шлюз ляжет — бот замолчит в обе стороны.
- `scheduler` теперь тоже знает базу — `send-reports` при включении в cron пойдёт через шлюз.

### Открытые вопросы
- нет.
