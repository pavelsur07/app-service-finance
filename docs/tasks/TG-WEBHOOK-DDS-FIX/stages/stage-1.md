## Stage 1: Конфигурируемый webhook URL — DONE

**Риск:** 🔴 HIGH
**Следующее действие:** 🛑 STOP, ждать Владельца (изменён публичный контракт вебхука + новая env-переменная → ревью перед редеплоем).

### Что сделано
- Убран хардкод `TARGET_WEBHOOK_URL` из `TelegramBotController`; URL вебхука теперь берётся из конфигурации.
- Добавлена env-переменная `TELEGRAM_WEBHOOK_URL` (прод-дефолт — шлюз `https://tg.vashfindir.ru/telegram/webhook`).
- Параметр `telegram.webhook_url` + глобальный bind `string $telegramWebhookUrl` в `services.yaml`.
- `TelegramBotController` приведён к правилам: `declare(strict_types=1)`, `final class`, инъекция URL через конструктор.
- Функциональный тест: `setWebhook` уходит на URL из конфигурации, а не на захардкоженный адрес.
- `ARCHITECTURE.md` — зафиксирован параметр и прод-дефолт.

### Затронутые файлы
- `site/src/Telegram/Controller/Admin/TelegramBotController.php` — modified (убрана const, конструктор с DI, strict_types, final)
- `site/config/services.yaml` — modified (параметр + bind)
- `site/.env` — modified (TELEGRAM_WEBHOOK_URL = tg.vashfindir.ru)
- `site/.env.test` — modified (детерминированное тестовое значение tg.example.test)
- `site/tests/Telegram/Functional/Admin/TelegramBotWebhookSetTest.php` — new
- `ARCHITECTURE.md` — modified

### Self-review
- [x] Scope compliance — только направление «а» из плана
- [x] Patterns / naming — параметр и bind как в проекте (ср. `app.encryption.*`); контроллер `final`, strict_types
- [x] Forbidden actions — нет хардкода URL (устранён), нет dump/dd, нет new Service
- [x] Security — изменения только в админ-зоне (`ROLE_SUPER_ADMIN`), публичный приём вебхука не ослаблен
- [x] CS-Fixer — чисто на изменённых файлах (`--path-mode=intersection`)
- [x] PHPStan — N/A: phpstan не настроен в репозитории (нет в vendor/bin, Makefile, composer scripts)
- [x] Тест зелёный — `TelegramBotWebhookSetTest` (7 ассертов)
- [x] ARCHITECTURE.md обновлён

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --filter TelegramBotWebhookSetTest`
- `docker compose run --rm -T site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php --path-mode=intersection src/Telegram/Controller/Admin/TelegramBotController.php tests/Telegram/Functional/Admin/TelegramBotWebhookSetTest.php`

### Риски / на что обратить внимание ревьюеру
- 🔴 Это меняет ТОЛЬКО код установки вебхука. Чтобы фактически переключить приём на `tg.vashfindir.ru`, после деплоя нужно зайти в админку и вызвать `setWebhook` (POST `/admin/telegram/bots/webhook-set`), затем проверить `getWebhookInfo`.
- На проде нужно прописать `TELEGRAM_WEBHOOK_URL` (через `compose dump-env prod` / реальное окружение) — `.env` даёт только дефолт.
- Требуется подтверждение, что шлюз `tg-gateway` реально проксирует на работающий апстрим (отдельная инфраструктура).

### Предсуществующие проблемы (вне scope, НЕ вводились в этом этапе)
- `tests/Telegram/Unit/BotLinkTest.php` и `BotLinkServiceTest.php` падают (16 ошибок): конструктор `BotLink` стал `(string $id, Company $company, ...)` в коммите `11d300d2` (25.01.2026 «Move Company entity to Company module»), тесты передают Company-mock первым аргументом. Файлы мной не менялись. → отдельная задача.

### Открытые вопросы
- нет (дефолтный URL подтверждён Владельцем — `tg.vashfindir.ru`).
