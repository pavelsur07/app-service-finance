## Stage 2: Конфигурируемый Telegram API base URL в app — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously → Stage 3 (прод-конфиг + cutover, 🔴 HIGH, STOP).

### Что сделано
- Новая env `TELEGRAM_API_BASE_URL` (`.env` = `https://api.telegram.org`, `.env.test` = `https://tg-proxy.example.test`).
- Параметр `telegram.api_base_url` + bind `string $telegramApiBaseUrl` в `services.yaml`.
- Убран хардкод `https://api.telegram.org`:
  - `TelegramWebhookController`: `sendMessage`, `editMessageText`, `getFile`, скачивание файла (`/file/bot.../...`).
  - `TelegramBotController`: `setWebhook`, `getWebhookInfo`.
  - URL строятся как `{base}/bot{token}/{method}` и `{base}/file/bot{token}/{path}`.
- Тест: admin `setWebhook` идёт на настроенную базу (`assertStringStartsWith` по `tg-proxy.example.test`).
- `ARCHITECTURE.md` — добавлена секция про `telegram.api_base_url` и схему вход/выход.

### Затронутые файлы
- `site/.env`, `site/.env.test` — modified
- `site/config/services.yaml` — modified (параметр + bind)
- `site/src/Telegram/Controller/TelegramWebhookController.php` — modified (база + 4 вызова)
- `site/src/Telegram/Controller/Admin/TelegramBotController.php` — modified (база + 2 вызова)
- `site/tests/Telegram/Functional/Admin/TelegramBotWebhookSetTest.php` — modified (assert базы)
- `ARCHITECTURE.md` — modified

### Self-review
- [x] Scope compliance — только конфигурируемая база, без cutover (прод-compose не трогал — это Stage 3)
- [x] Patterns / naming — параметр + bind как для webhook_url/secret
- [x] Forbidden actions — хардкод URL убран; dump/dd нет
- [x] Security — без изменений в repo/companyId
- [x] CS-Fixer — чисто на изменённых файлах
- [x] PHPStan — N/A
- [x] Тесты зелёные — `tests/Telegram/Functional` (7 тестов, 27 ассертов)
- [x] ARCHITECTURE.md обновлён

### Команды проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Telegram/Functional`

### Риски / ревьюеру
- Stage 2 безопасен для деплоя: прод-дефолт остаётся `https://api.telegram.org` (из baked `.env`), поведение не меняется до Stage 3.
- Base URL предполагается без завершающего слэша (так в `.env`/проде). Завершающий слэш дал бы `//bot` — конфиг контролируем.

### Открытые вопросы
- нет.
