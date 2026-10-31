# gateway — шлюз на 109.73.204.179

Системный nginx на хосте `109.73.204.179` (ssh `sv_bots_tw_root`). Рядом живут
чужие сайты — `mybots.vashfindir.ru` и Ubuntu `default`; их не трогаем.
Источник правды — этот каталог: ручные правки на хосте перезапишет деплой.

```
Telegram ──▶ tg.vashfindir.ru/telegram/webhook ──▶ app.vashfindir.ru   (вход, публичный)
app ──▶ tg.vashfindir.ru/bot-api/ ──▶ api.telegram.org                 (выход, только IP app)
```

План развития (домен `ai.` для провайдеров ИИ) — `docs/tasks/ai/gateway/plan.md`.

## Файлы

| Репозиторий | Хост |
|---|---|
| `nginx/conf.d/gateway-log-format.conf` | `/etc/nginx/conf.d/gateway-log-format.conf` |
| `nginx/snippets/*.conf` | `/etc/nginx/snippets/gateway/` |
| `nginx/sites/tg.vashfindir.ru.conf` | `/etc/nginx/sites-available/tg-gateway` + symlink в `sites-enabled` |

- `app-only.conf` — IP app-сервера; при его смене правится только здесь.
- `upstream-tls.conf` — проверка TLS upstream; `proxy_ssl_name` задаёт location.
- `log_format gateway_json` — в `conf.d`, чтобы быть объявленным раньше сайтов.
  Путь без query, без заголовков и тел. Location с секретом в пути
  (`/bot-api/` — токен бота) — `access_log off`.
- Логи: `/var/log/nginx/tg-gateway.{access,error}.log`, ротирует штатный
  logrotate nginx (daily, 14).

## Деплой

`.github/workflows/deploy-gateway.yml`:

- PR с изменениями `gateway/**` — `shellcheck` и `scripts/nginx-test.sh`
  (`nginx -t` в `nginx:1.28` с раскладкой Ubuntu и сертификатами-заглушками);
- push в master и ручной запуск — то же, затем `scripts/install.sh` на хосте:
  бэкап всех управляемых файлов → установка → `nginx -t` → reload → проверки
  (`/health` 200 по 80 и 443, `/bot-api/` с самого хоста 403, неизвестный путь
  404). Провал `nginx -t` или проверки — откат всего набора и reload.
  Доступность `app.vashfindir.ru` и `api.telegram.org` печатается, но не
  откатывает.

Секреты: `TG_SSH_PRIVATE_KEY`, `TG_SERVER_HOST`, `TG_SERVER_USER`.

Локально перед PR:

```bash
gateway/scripts/nginx-test.sh          # nginx -t
gateway/scripts/nginx-test.sh --dump   # + эффективный конфиг (nginx -T)
```

Новый файл в наборе — добавить в `MANAGED` и установку `scripts/install.sh`,
в раскладку `scripts/nginx-test.sh` и в таблицу выше. Новый домен — сертификат
до деплоя (`certbot certonly --nginx -d <домен>`) и домен в `CERTS`/`DOMAINS`
обоих скриптов.

## Откат

- Провал при деплое — откатывает `install.sh` сам.
- После успешного деплоя — `git revert` и деплой.
- Вручную на хосте: прежний файл сайта из git в
  `/etc/nginx/sites-available/tg-gateway`, затем `nginx -t && systemctl reload nginx`.

## Проверка вручную

```bash
curl -s https://tg.vashfindir.ru/health                       # alive
curl -s -o /dev/null -w '%{http_code}\n' https://tg.vashfindir.ru/bot-api/getMe   # 403 не с app
```

Сквозная: написать боту — операция создаётся (вебхук), бот отвечает (`/bot-api/`).
