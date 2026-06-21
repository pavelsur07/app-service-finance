# Phase 0 — Plan: TG-WEBHOOK-DDS-FIX

> Статус: **черновик плана, ожидает одобрения Владельца (🛑 STOP).**
> Источник задачи: бриф в чате — «бот не принимает/не отправляет команды; создание операций ДДС перестало работать».
> До одобрения этого плана код не пишется.

## Контекст / проблема

Модуль Telegram (`site/src/Telegram`) перестал работать по двум симптомам:

1. **Бот не принимает/не отправляет команды.** Приём зависит от доставки вебхука. URL вебхука **захардкожен** в админ-контроллере:
   `src/Telegram/Controller/Admin/TelegramBotController.php:20`
   ```php
   private const TARGET_WEBHOOK_URL = 'https://app.vashfindir.ru/telegram/webhook';
   ```
   18–20 июня 2026 введён новый шлюз `tg-gateway` (домен `tg.vashfindir.ru`, traefik+nginx, проксирует только `/telegram/webhook` → `app.vashfindir.ru`) — см. коммиты `3f1aed7b`, `e18e5644`. Шлюз задумывался публичной точкой приёма Telegram, но `setWebhook` по-прежнему прописывает прямой `app.vashfindir.ru`. URL нельзя менять без правки кода и редеплоя — это и есть рассинхрон.

2. **ДДС-операции «молча» не создаются.** Весь `TelegramWebhookController::webhook()` обёрнут в `catch (\Throwable)` (`:106-111`), который пишет только в `error_log` и возвращает `{status:ok}`. Любая ошибка в цепочке `handleTextMessage → CreateTelegramCashTransactionAction → CashFacade::createTransaction → CashTransactionService::add()` невидима пользователю: он не получает ни операции, ни сообщения об ошибке.

Цель задачи: (а) сделать URL вебхука конфигурируемым через `.env` и согласованным с архитектурой шлюза; (б) перестать глушить ошибки ДДС-потока — логировать корректно (ERROR→Sentry) и отвечать пользователю внятным сообщением, **сохраняя HTTP 200 для Telegram** (иначе Telegram ретраит и копит очередь).

## Важное проектное ограничение

Вебхук Telegram **обязан получать HTTP 200** почти всегда. Поэтому глобальный `ExceptionListener` здесь **не применяем** — endpoint должен сам ловить ошибки и всегда отдавать 200. Меняем не «отдавать 4xx/5xx», а «корректно логировать + отвечать пользователю в чат».

## Этапы

### Stage 1 — Конфигурируемый webhook URL (направление «а»)
**Риск:** 🔴 HIGH (затрагивает публичный endpoint приёма Telegram + добавление env-переменной + редеплой).
**Цель:** убрать хардкод, сделать URL вебхука параметром из `.env`, по умолчанию — адрес шлюза.

Карта изменений:
- `.env` — добавить `TELEGRAM_WEBHOOK_URL=https://tg.vashfindir.ru/telegram/webhook` (значение по умолчанию подтверждает Владелец: шлюз `tg.vashfindir.ru` ИЛИ прямой `app.vashfindir.ru`).
- `.env.dev`, `.env.test` — нейтральное dev-значение.
- `config/services.yaml` — параметр `parameters: telegram.webhook_url: '%env(TELEGRAM_WEBHOOK_URL)%'` (паттерн уже используется, напр. `app.encryption.key_file`).
- `src/Telegram/Controller/Admin/TelegramBotController.php` — убрать `const TARGET_WEBHOOK_URL`, внедрить значение через конструктор (`bind` или явный аргумент сервиса), использовать в `webhookSet()` и `buildWebhookStatus()`.
- Заодно привести контроллер к правилам проекта: `declare(strict_types=1)`, `final class` (если не ломает Symfony DI — проверить).

**STOP-причины этапа:** изменение поведения публичного endpoint, новая env-переменная, выбор дефолтного URL. 🛑 Обязательное ревью SQL/конфига нет (миграции нет), но изменение публичного контракта вебхука → STOP перед редеплоем.

Тесты:
- Функциональный/юнит на `webhookSet()`: URL берётся из параметра, а не из константы (мок `HttpClientInterface`, проверка тела запроса `setWebhook`).

### Stage 2 — Видимая обработка ошибок ДДС-потока (направление «б»)
**Риск:** 🟡 MEDIUM (логика внутри Telegram-модуля, без изменения публичного контракта и БД).
**Цель:** ошибки создания операции не глушить молча — логировать как ERROR (уйдёт в Sentry) и отвечать пользователю в чат, при этом Telegram получает 200.

Карта изменений:
- `src/Telegram/Controller/TelegramWebhookController.php`:
  - Локальный `try/catch` вокруг вызова `createTelegramCashTransactionAction` в `handleTextMessage()` (`:827`): на доменные ошибки (напр. `\DomainException` «период закрыт», `CurrencyMismatchException`) — отдать пользователю понятный текст; на прочие `\Throwable` — `logger->error()` + сообщение «Не удалось сохранить операцию, попробуйте позже».
  - Глобальный `catch (\Throwable)` (`:106`) — заменить `error_log` на `LoggerInterface::error` (Sentry), сохранив возврат 200.
  - Привести файл к правилам: `declare(strict_types=1)`, `final class`.
- Рассмотреть пробрасывание доменных исключений из `CreateTelegramCashTransactionAction` как есть (Action их не ловит — уже ок), маппинг текста — в контроллере (HTTP in/out слой).
- (Опц., если подтвердит диагностика) `parseAmountFromText` возвращает `0.00` на нулевой ввод → добавить явный отказ с подсказкой формата вместо создания нулевой операции.

**Не входит в scope:** изменение `CashTransactionService::add()` и общего Cash-контракта (это разделяемый код веб-UI) — только Telegram-слой.

Тесты:
- Юнит/функциональный на `handleTextMessage`: при выбросе `\DomainException` из Action пользователь получает текст ошибки, ответ HTTP 200, факт `logger->error`.
- Регрессионный: «закрытый период» (`assertNotLockedForCompany`) → пользователь видит сообщение, а не тишину.

## Что НЕ трогаем
- `CashTransactionService` / `CashFacade` / общий Cash-контракт.
- Маршруты `messenger.yaml`, security firewalls (вебхук уже `PUBLIC_ACCESS`).
- Сам `tg-gateway` (отдельная инфраструктура, отдельная задача при необходимости).

## Обновления ARCHITECTURE.md
- Зафиксировать параметр `telegram.webhook_url` и факт, что URL вебхука конфигурируется через `.env`.
- Зафиксировать политику обработки ошибок Telegram-вебхука (всегда 200 + логирование ERROR).

## Открытые вопросы Владельцу (нужны до старта)
1. **Дефолтный URL вебхука:** шлюз `https://tg.vashfindir.ru/telegram/webhook` (ради чего вводили gateway) или прямой `https://app.vashfindir.ru/telegram/webhook`?
2. **Подтверждена ли рантайм-причина «тишины»?** Желательно вывод `getWebhookInfo` и грепа по логам — чтобы понимать, добавляет ли Stage 1 фактическое исправление приёма или только устраняет рассинхрон конфигурации.
3. Нужно ли в Stage 2 добавлять отказ на нулевую сумму (`0.00`)?

## Порядок
Stage 1 (🔴 HIGH) → 🛑 STOP, ревью Владельца перед редеплоем → Stage 2 (🟡 MEDIUM) → Phase Final handoff (🛑 STOP).
