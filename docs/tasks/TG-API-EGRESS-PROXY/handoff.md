# Handoff — TG-API-EGRESS-PROXY

> Ветка: `feature/TG-API-EGRESS-PROXY` (от `master`). Merge только через PR.

## Проблема
Бот принимал апдейты (через `tg.vashfindir.ru/telegram/webhook`), но не отвечал: app-сервер (РФ, `217.198.13.171`) не имеет прямого доступа к `api.telegram.org` (`Connection timed out`). Значит все исходящие вызовы Telegram (sendMessage, setWebhook, getFile…) не проходили.

## Решение
Исходящие вызовы Telegram пускаем через тот же шлюз `tg-gateway` (KZ-VPS), что и вход. Базовый URL Telegram API в app сделан конфигурируемым.

## Этапы
- **Stage 1 (🔴):** `tg-gateway/nginx/tg.conf` — `location /bot-api/` → `https://api.telegram.org/`; `tg-gateway/docker-compose.yml` — Traefik-роутер `tg-bot-api` (`PathPrefix(/bot-api/)`) + `ipAllowList` на `217.198.13.171/32`. **Задеплоен и проверен** (`getMe` → `ok:true`).
- **Stage 2 (🟡):** env `TELEGRAM_API_BASE_URL` + параметр/bind; убран хардкод `https://api.telegram.org` в `TelegramWebhookController` (sendMessage/editMessage/getFile/file) и `TelegramBotController` (setWebhook/getWebhookInfo). Дефолт прежний.
- **Stage 3 (🔴):** прод-значение `TELEGRAM_API_BASE_URL=https://tg.vashfindir.ru/bot-api` в `docker-compose.prod.yml` (`x-php-env` + `scheduler`); дозакрыт хардкод в `SendTelegramReportsCommand`.

## Изменённые контракты / конфигурация
- Новая env **`TELEGRAM_API_BASE_URL`** (`.env`=api.telegram.org, `.env.test`=tg-proxy.example.test, прод=шлюз). Параметр `telegram.api_base_url` + bind `string $telegramApiBaseUrl`.
- Инфра шлюза: новый публичный путь `/bot-api/` (ограничен по IP app-сервера на Traefik).

## Миграции
- Нет.

## Тесты
- `tests/Telegram/Functional` — 7/7 (27 ассертов). CS чисто. PHPStan — N/A.
- Прод-smoke: `curl https://tg.vashfindir.ru/bot-api/bot$TOKEN/getMe` → `{"ok":true,...}`.

## Cutover (Владелец, по порядку)
1. Шлюз (Stage 1) — уже задеплоен ✓.
2. Смержить ветку → деплой app.
3. Админка → «Установить webhook» (setWebhook теперь идёт через шлюз).
4. «Проверить webhook» → `url` шлюзовой, `last_error_message` пусто.
5. Написать боту → ответ; в логах app `Telegram update получен`.

## Риски
- Весь Telegram-трафик (вход+выход) через `tg.vashfindir.ru`: падение шлюза = бот молчит в обе стороны.
- IP app-сервера зашит в `tg-gateway` (`217.198.13.171/32`). Смена IP → обновить label шлюза.

## Follow-ups
- Вынести IP app-сервера в переменную деплоя шлюза (сейчас захардкожен).
- (Опц.) усилить `/bot-api/` секретным заголовком в дополнение к IP.
- Предсуществующие сломанные тесты вне scope: `CreateDocumentFromTransactionActionTest`, `AccountBalanceServiceTest`, `BotLink*Test`.
