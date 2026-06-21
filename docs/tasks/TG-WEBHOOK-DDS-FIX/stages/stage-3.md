## Stage 3: Аутентификация вебхука по secret_token — DONE

**Риск:** 🔴 HIGH
**Следующее действие:** 🛑 STOP, ждать Владельца (аутентификация публичного endpoint + новая env + редеплой с обязательной переустановкой вебхука).

### Что сделано
- Новая env `TELEGRAM_WEBHOOK_SECRET` (параметр `telegram.webhook_secret`, bind `string $telegramWebhookSecret`).
- `TelegramBotController::webhookSet()` передаёт `secret_token` в Telegram (если секрет задан).
- `TelegramWebhookController` сверяет заголовок `X-Telegram-Bot-Api-Secret-Token` через `hash_equals`; несовпадение → HTTP 403, апдейт не обрабатывается, пишется warning.
- Пустой секрет = проверка выключена (безопасный rollout: приём не ломается до установки секрета).
- Прод-env (`docker-compose.prod.yml`) и `.env`/`.env.test` обновлены.
- Тесты: отклонение без/с неверным секретом (403), проход с верным; в admin-тесте — проверка передачи `secret_token`.
- `ARCHITECTURE.md` — зафиксирована политика secret_token.

### Затронутые файлы
- `site/src/Telegram/Controller/TelegramWebhookController.php` — modified (инъекция секрета, метод `isValidSecretToken`, проверка в `webhook()`)
- `site/src/Telegram/Controller/Admin/TelegramBotController.php` — modified (`secret_token` в setWebhook)
- `site/config/services.yaml` — modified (параметр + bind)
- `site/.env`, `site/.env.test` — modified
- `docker-compose.prod.yml` — modified (`TELEGRAM_WEBHOOK_SECRET` в `x-php-env`)
- `site/tests/Telegram/Functional/TelegramWebhookSecretTokenTest.php` — new
- `site/tests/Telegram/Functional/TelegramWebhookCashTransactionTest.php` — modified (шлёт заголовок секрета)
- `site/tests/Telegram/Functional/Admin/TelegramBotWebhookSetTest.php` — modified (assert secret_token)
- `ARCHITECTURE.md` — modified

### Self-review
- [x] Scope compliance — только аутентификация вебхука
- [x] Patterns / naming — параметр + bind как в Stage 1; `hash_equals` (constant-time)
- [x] Forbidden actions — секрет не логируется (логируется только факт несовпадения)
- [x] Security — публичный endpoint теперь проверяет подлинность; пустой секрет — осознанный rollback-safe режим
- [x] CS-Fixer — чисто на изменённых файлах
- [x] PHPStan — N/A (не настроен)
- [x] Тесты зелёные — `tests/Telegram/Functional` (7 тестов, 26 ассертов)
- [x] ARCHITECTURE.md обновлён

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Telegram/Functional`

### Риски / на что обратить внимание ревьюеру
- 🔴 ПОРЯДОК ДЕПЛОЯ ВАЖЕН: после установки `TELEGRAM_WEBHOOK_SECRET` в проде нужно ОБЯЗАТЕЛЬНО переустановить вебхук (`POST /admin/telegram/bots/webhook-set`), иначе Telegram продолжит слать апдейты без заголовка → все запросы будут получать 403. Пока секрет пустой — проверка выключена, приём работает как раньше.
- Секрет должен соответствовать формату Telegram: 1–256 символов из `A-Z a-z 0-9 _ -`.

### Открытые вопросы
- нет.
