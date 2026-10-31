# gateway — шлюз на 109.73.204.179

Системный nginx на хосте `109.73.204.179` (ssh `sv_bots_tw_root`). Рядом живут
чужие сайты — `mybots.vashfindir.ru` и Ubuntu `default`; их не трогаем.
Источник правды — этот каталог: ручные правки на хосте перезапишет деплой.

```
Telegram ──▶ tg.vashfindir.ru/telegram/webhook ──▶ app.vashfindir.ru         (вход, публичный)
app ──▶ tg.vashfindir.ru/bot-api/ ──▶ api.telegram.org                       (выход, только IP app)
app ──▶ ai.vashfindir.ru/{openai|deepseek|kimi}/… ──▶ провайдер ИИ           (только IP app + токен)
```

План и этапы — `docs/tasks/ai/gateway/` (`plan.md`, `g0.md`, `g1.md`), секреты — `keys.md`.

## Файлы

| Репозиторий | Хост |
|---|---|
| `nginx/conf.d/gateway-log-format.conf` | `/etc/nginx/conf.d/gateway-log-format.conf` |
| `nginx/conf.d/gateway-app.conf` | `/etc/nginx/conf.d/gateway-app.conf` |
| `nginx/snippets/*.conf` | `/etc/nginx/snippets/gateway/` |
| `nginx/sites/tg.vashfindir.ru.conf` | `/etc/nginx/sites-available/tg-gateway` + symlink в `sites-enabled` |
| `nginx/sites/ai.vashfindir.ru.conf` | `/etc/nginx/sites-available/ai-gateway` + symlink в `sites-enabled` |
| — (собирает `scripts/render-secrets.sh` из GitHub Secrets) | `/etc/nginx/gateway-secrets.conf`, root:root 0600 |

- `gateway-app.conf` — `geo $gateway_from_app`: IP app-сервера, при его смене
  правится только здесь. `snippets/app-only.conf` — `if … return 403`, а не
  `allow/deny`: срабатывает раньше проверки токена (чужой IP получает 403, не
  401) и не пишет в error log путь запроса.
- `ai-client.conf` — токен клиента (`$ai_client_ok`), после `app-only.conf`.
- `ai-upstream.conf` — заголовки к провайдеру и от него; подключается в
  location: `proxy_set_header` из server в location со своими не наследуется.
- `upstream-tls.conf` — проверка TLS upstream; `proxy_ssl_name` задаёт location.
- `log_format gateway_json` — в `conf.d`, чтобы быть объявленным раньше сайтов.
  Путь без query, без заголовков и тел. `/bot-api/` (токен бота в пути) —
  `access_log off` и `error_log /dev/null`.
- Логи: `/var/log/nginx/{tg,ai}-gateway.{access,error}.log`, ротирует штатный
  logrotate nginx (daily, 14).

## Шлюз ИИ (`ai.vashfindir.ru`)

- Клиент (ai-service): `base_url` = `https://ai.vashfindir.ru/<провайдер>/v1`,
  `Authorization: Bearer <AI_GATEWAY_TOKEN>`. Шлюз меняет его на ключ провайдера.
- Upstream: `openai` → `api.openai.com`, `deepseek` → `api.deepseek.com`,
  `kimi` → `api.moonshot.ai`. Из запроса берётся только путь — не открытый прокси.
- Ответы шлюза: чужой IP 403; нет или неверный токен 401 `unauthorized`;
  провайдер без ключа 503 `provider_not_configured`; тело > 1 МБ 413; больше
  10 r/s (burst 20) на провайдера 429; неизвестный путь 404. Таймаут ответа
  провайдера 120 с, стриминг без буферизации.
- Провайдеру не уходят `Cookie`, `X-Forwarded-For`, `X-Real-IP`,
  `OpenAI-Organization`, `OpenAI-Project`; клиенту — `Set-Cookie`,
  `openai-organization`, `openai-project`.

### Ротация токена шлюза

1. Новый токен → секрет `AI_GATEWAY_TOKEN_NEXT`, деплой шлюза (принимает оба).
2. Новый токен → секрет `AI_GATEWAY_TOKEN` у ai-service, деплой app.
3. На шлюзе: `AI_GATEWAY_TOKEN` = новый, `AI_GATEWAY_TOKEN_NEXT` очистить, деплой шлюза.

Ключ провайдера: новый ключ в кабинете → секрет → деплой шлюза (проверка 1
покажет 200) → старый ключ удалить в кабинете.

## Деплой

`.github/workflows/deploy-gateway.yml`:

- PR с изменениями `gateway/**` — `shellcheck`, `scripts/nginx-test.sh`
  (`nginx -t`), тесты `tests/render-secrets-test.sh`, `tests/behavior-test.sh`
  (поведение обоих доменов в docker с мок-upstream), `tests/install-test.sh`
  (установка и откат);
- push в master и ручной запуск — то же, затем на раннере
  `scripts/render-secrets.sh` (значения проверяются, не печатаются) и на хосте
  `scripts/install.sh`: бэкап всех управляемых файлов → установка → `nginx -t`
  → reload → проверки хоста (`/health` обоих доменов по 80 и 443, `/bot-api/` и
  `/openai/` с самого хоста → 403, неизвестный путь → 404). Любой сбой до
  конца проверок — откат всего набора и reload; если не удался и он, бэкап
  остаётся на хосте (путь в логе).
- Проверка 1 — после установки: `GET /models` у каждого настроенного провайдера
  с его ключом прямо с хоста. Не 200 — деплой красный (выход 3), но конфиг не
  откатывается. Провайдер без ключа — `not configured`.

Секреты: `TG_SSH_PRIVATE_KEY`, `TG_SERVER_HOST`, `TG_SERVER_USER`;
`AI_GATEWAY_TOKEN` (обязателен), `AI_GATEWAY_TOKEN_NEXT`,
`AI_OPENAI_API_KEY`, `AI_DEEPSEEK_API_KEY`, `AI_KIMI_API_KEY`.

Локально перед PR:

```bash
gateway/scripts/nginx-test.sh          # nginx -t
gateway/scripts/nginx-test.sh --dump   # + эффективный конфиг (nginx -T)
gateway/tests/render-secrets-test.sh
gateway/tests/behavior-test.sh
gateway/tests/install-test.sh
```

Новый файл в наборе — добавить в `MANAGED` и установку `scripts/install.sh`,
в `scripts/layout.sh` и в таблицу выше. Новый домен — сертификат до деплоя
(`certbot certonly --nginx -d <домен>`), домен в `CERTS` (`install.sh`) и
`GATEWAY_DOMAINS` (`layout.sh`).

## Откат

- Провал при деплое — откатывает `install.sh` сам.
- После успешного деплоя — `git revert` и деплой.
- Экстренно только ИИ: `rm /etc/nginx/sites-enabled/ai-gateway && nginx -t &&
  systemctl reload nginx` — Telegram не затронут.

## Проверка вручную

```bash
curl -s https://tg.vashfindir.ru/health                                          # alive
curl -s https://ai.vashfindir.ru/health                                          # alive
curl -s -o /dev/null -w '%{http_code}\n' https://tg.vashfindir.ru/bot-api/getMe   # 403 не с app
curl -s -o /dev/null -w '%{http_code}\n' https://ai.vashfindir.ru/openai/v1/models # 403 не с app
```

Сквозная Telegram: написать боту — операция создаётся (вебхук), бот отвечает (`/bot-api/`).
