# Почта — мониторинг живости транспорта

Наблюдаемость исходящей почты. Принцип тот же, что у S3 (`docs/tasks/s3-migration/monitoring.md`):
**liveness ≠ readiness** — мёртвый SMTP не должен валить `/​_health` и выкидывать ноду из ротации
traefik. Поэтому мониторинг почты — отдельный канал.

## Что уже работает без нас

Реальные письма идут через Messenger: `Symfony\Component\Mailer\Messenger\SendEmailMessage` →
транспорт `async_sync` (`site/config/packages/messenger.yaml`), 3 ретрая.

- Ретрай → `warning` (`SendFailedMessageForRetryListener:69`). В GlitchTip **не идёт** — это
  ожидаемое и обрабатываемое само состояние, будить человека не за чем.
- Ретраи исчерпаны → `critical` (`SendFailedMessageForRetryListener:79`) → monolog-хендлер
  `sentry` (level `error`, все каналы) → **GlitchTip**. Сообщение уходит в `failed`-очередь.

То есть «письмо не ушло» алертится немедленно и само. Отдельный монитор `failed`-очереди не
нужен — это был бы второй алерт на то же событие.

Дыра, которую закрывает проба ниже: если писем никто не шлёт, мёртвый транспорт обнаружит
первый пользователь, а не мониторинг.

## #1 — Синтетическая проверка SMTP (проактивно)

Команда `app:mailer:healthcheck` (`site/src/Shared/Command/MailerHealthCheckCommand.php`):
`connect` → `EHLO` → `STARTTLS` → `AUTH` → `QUIT` через `SmtpTransport::start()/stop()`
с замером латентности.

**Письмо не отправляется.** Handshake ловит ровно тот отказ, который был на проде (протухший
пароль приложения mail.ru), а также закрытый порт, протухший TLS и блокировку провайдером —
без спама, репутационных рисков и ящика-приёмника.

- Крон (supercronic, `docker/cron/app.cron`): `7 * * * *` — раз в час. 24 AUTH в сутки,
  ни один провайдер это не считает подозрительным.
- Сбой → `logger->error('Mailer healthcheck FAILED')` → **GlitchTip** + exit 1.
  Успех печатает `mailer healthcheck OK (N ms)` в docker logs.
- Не-SMTP DSN (`null://`, `sendmail`) → `SKIPPED` + exit 0: проверять нечем, алертить не за что.
- Плата за часовой интервал — окно обнаружения до часа. Реальные неотправленные письма
  по-прежнему алертят немедленно (см. выше).

**Секреты:** в контекст лога идут только `duration_ms` и `exception`. DSN и пароль не логируем;
сообщение SMTP-ошибки содержит код ответа сервера (`535 Authentication failed`), не креды.
Сверху ещё скрабит `App\Shared\Infrastructure\Sentry\SentryBeforeSend`.

## #2 — Heartbeat scheduler (dead man's switch)

`*/30 * * * *` пингует heartbeat-монитор GlitchTip. Молчание → GlitchTip **сам** заводит issue.

Проба из #1 не видит один класс отказов: если умер supercronic или весь контейнер `scheduler`,
никакая команда просто не запустится и никто ничего не залогирует. Heartbeat закрывает это —
и не только для почты, а для всех кронов сразу.

- URL в `GLITCHTIP_SCHEDULER_HEARTBEAT_URL` (блок `scheduler` в `docker-compose.prod.yml`).
  Пусто = выключено.
- **Заводится как GitHub secret, не как host-env.** Деплой (`.github/workflows/deploy.yml`)
  прокидывает переменные в ssh-сессию через `export` из `secrets.*`; неинтерактивный shell
  профиль хоста не читает, поэтому переменная, положенная только в host-env, до compose не
  доедет и heartbeat молча останется выключенным.
- `wget`, а не `curl`: busybox гарантирован в alpine-образе, наличие `curl` в runtime-стадии
  `site/docker/production/php-cli/Dockerfile` — нет.

## Настройка GlitchTip

1. **`EMAIL_URL` самого GlitchTip** не должен быть тем же аккаунтом, что `SITE_MAILER_DSN`.
   Иначе алерт о сломанной почте отправляется сломанной почтой — та петля, из-за которой
   прошлую поломку не заметили.
2. **Alert rule:** условие «an event's level equals error», порог **1 событие**, действие —
   email на внешний ящик. Порог 2 при часовом интервале не сработает никогда.
3. **Heartbeat monitor:** тип Heartbeat, interval **2700 c (45 мин)** при кроне `*/30` —
   запас на джиттер, иначе одно опоздание даёт ложный алерт. Полученный URL завести
   GitHub secret'ом `GLITCHTIP_SCHEDULER_HEARTBEAT_URL` и передеплоить.

## Что делать при `Mailer healthcheck FAILED`

1. Посмотреть код ответа в issue: `535` — креды, `421`/`450` — временный лимит провайдера,
   `Connection refused`/timeout — сеть или порт.
2. Для `535` на mail.ru: перевыпустить **пароль приложения** в личном кабинете почты.
3. Обновить **GitHub secret** `SITE_MAILER_DSN`, а не host-env прода. Деплой прокидывает
   переменную в ssh-сессию `export`-ом из `secrets.*` (`.github/workflows/deploy.yml:282`), и
   она перебивает файл `.env` рядом с compose. Пароль, положенный только в host-env, до
   работающих контейнеров не доедет.
4. Передеплоить. Годится и re-run последнего деплоя: секреты резолвятся заново, на текущие
   значения. `docker compose up -d` пересоздаёт контейнер при смене env — а вот `docker restart`
   нет, env запечён в момент создания контейнера.
5. Проверить руками **внутри работающего контейнера-потребителя**:

   ```
   docker compose -f docker-compose.prod.yml exec -T scheduler php bin/console app:mailer:healthcheck --no-interaction
   ```

   `sudo /usr/local/bin/codex-console app:mailer:healthcheck` для этой проверки не годится:
   wrapper поднимает свежий контейнер через `compose run --rm`, тот читает `.env` с хоста и
   остаётся зелёным даже когда у работающих контейнеров запечён протухший пароль.
6. Разобрать `failed`-очередь: письма, упавшие за время поломки, лежат там и не переотправятся
   сами (`messenger:failed:show` → `messenger:failed:retry`, только с явного разрешения владельца).

## Что НЕ делали (осознанно)

- **Монитор `failed`-очереди** — дубль `critical` от Worker.
- **Отправка пробного письма** — handshake ловит тот же отказ без спама и ящика-приёмника.
- **Почта в `/_health`** (`src/Shared/Controller/HealthController.php`) — endpoint читает
  балансировщик; мёртвый SMTP не повод выкидывать ноду из ротации.
- **Ретраи/бэкофф внутри команды** — сглаживание флапа делает порог алерта в GlitchTip.
- **Telegram-переходник для алертов GlitchTip** — +контроллер +роут +тест, и при лежащем
  приложении алерт всё равно не уйдёт. Вернуться, если email-канал окажется ненадёжным.

## Проверка локально

```
docker compose run --rm site-php-cli php bin/console app:mailer:healthcheck --no-interaction
```
→ `mailer healthcheck OK (N ms)`, exit 0 (dev DSN `smtp://site-mailer:1025`, нужен поднятый
`site-mailer`).

```
docker compose run --rm -e MAILER_DSN=smtp://127.0.0.1:1 site-php-cli php bin/console app:mailer:healthcheck --no-interaction
```
→ `mailer healthcheck FAILED: Connection refused`, exit 1.
