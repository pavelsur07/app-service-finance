# Единый шлюз: Telegram + ИИ-провайдеры

Решения Владельца 31.10.2026:

- провайдеры ИИ — OpenAI, DeepSeek, Kimi (Moonshot, международный `api.moonshot.ai`);
- ai-service не ходит к провайдерам напрямую — только через наш шлюз, по
  аналогии с tg-gateway;
- шлюз — на том же хосте, что tg-gateway (109.73.204.179, системный nginx рядом
  с `mybots.vashfindir.ru`), единый проект вместо отдельного tg-gateway.

Ключи и секреты — `keys.md`. Проверки подключения — раздел «Проверки» ниже.

## Схема

```
Telegram ──▶ tg.vashfindir.ru/telegram/webhook ──▶ app.vashfindir.ru        (вход, публичный)
app ──▶ tg.vashfindir.ru/bot-api/ ──▶ api.telegram.org                      (выход, только app)

ai-service (app 217.198.13.171)
  └─▶ https://ai.vashfindir.ru/{openai|deepseek|kimi}/v1/…
        Authorization: Bearer <AI_GATEWAY_TOKEN>
            ▼
      шлюз: IP app + токен → подмена на ключ провайдера
            ▼
      api.openai.com · api.deepseek.com · api.moonshot.ai
```

- Ключи провайдеров — **только на шлюзе**. На app-сервере и в ai-service —
  один токен шлюза: компрометация app не раскрывает ключи, отзыв — смена токена.
- Адаптеры ai-service не меняются: все три API OpenAI-совместимы, меняются
  `base_url` и `api_key_env` в `routes.yaml`.
- В проде ai-service не стартует, если `base_url` провайдера (кроме `stub`) не
  начинается с `AI_SERVICE_LLM_GATEWAY_URL`.

## Принцип: один шлюз — отдельный домен на назначение

| | Один домен, пути | **Один шлюз, отдельные домены** |
|---|---|---|
| Экспозиция | публичный вебхук и приватный выход ИИ на одном имени | `tg.` публичный, `ai.` — только app |
| Сертификат, логи, лимиты | общие | свои на домен |
| Вебхук Telegram | перерегистрировать URL | без изменений |
| Код и деплой | один | один: общие сниппеты, общий откат |

Kong/Envoy не нужны: шесть маршрутов, nginx на хосте уже есть.

## Структура в репозитории

```
gateway/                          (вместо tg-gateway/, G0)
  nginx/
    conf.d/gateway-log-format.conf  JSON: статус, upstream, время; без query, Authorization и тел
    snippets/
      app-only.conf               allow 217.198.13.171; deny all — IP app в одном месте
      upstream-tls.conf           proxy_ssl_verify, CA, verify_depth, server_name
    sites/
      tg.vashfindir.ru.conf       Telegram
      ai.vashfindir.ru.conf       /openai/ /deepseek/ /kimi/ (G1, подробно — g1.md)
      00-default.conf             неизвестный Host → закрыть (G1, отдельным решением)
  scripts/install.sh              установка набора с откатом (запускает деплой)
  scripts/nginx-test.sh           nginx -t в контейнере (CI и локально)
  README.md                       схема, секреты, ротация, откат
.github/workflows/deploy-gateway.yml   (вместо deploy-tg-gateway.yml)
```

## Практики

**Безопасность**

- Не открытый прокси: upstream — константы конфига, из запроса берётся только путь.
- Ключи — `/etc/nginx/gateway-secrets.conf` (root, 0600), собирается деплоем из
  GitHub Secrets (`scripts/render-secrets.sh`), в репозитории нет, в логи не попадает.
- Токен клиента — через `map`; на время ротации шлюз принимает два токена
  (текущий и следующий) — смена без простоя.
- Провайдеру уходят только нужные заголовки; служебные заголовки ответа
  (`openai-organization` и т. п.) скрываются.
- Свои файлы — без зависимостей от `/etc/nginx/conf.d`; `mybots` не трогаем.

**Надёжность**

- Атомарный деплой всего набора сайтов: бэкап → установка → `nginx -t` → при
  ошибке откат всех файлов → reload. Ошибка в конфиге ИИ не роняет Telegram.
- Таймауты и лимиты по доменам: Telegram 30 с; ИИ 120 с, тело до 1 МБ,
  `limit_req` на провайдера (страховка от всплеска расходов).

**Наблюдаемость**

- JSON access log на домен (`upstream_status`, `request_time`), logrotate.
- `/health` на каждом домене; позже — cron app-сервера раз в 10 минут → ERROR в
  GlitchTip при сбое.

**Хост** (действия Владельца, отдельно): ufw 22/80/443, unattended-upgrades —
сначала read-only сверка текущего состояния.

## Этапы

### G0. tg-gateway → `gateway/` без изменения поведения

- Подробно — `g0.md`. Сниппеты, JSON-лог, `deploy-gateway.yml`; старый workflow
  удаляется. `00-default` перенесён в G1: он меняет ответ чужим сайтам хоста.
- Проверка: `nginx -T` до/после — разница только в include и формате лога;
  `/health`, `/bot-api/` → `api.telegram.org`; Владелец пишет боту — операция
  создаётся.
- Откат — прежние файлы из бэкапа деплоя.

### G1. `ai.vashfindir.ru`

- Владелец: DNS A `ai.vashfindir.ru → 109.73.204.179`, `certbot`, секреты
  (`keys.md`).
- Подробно — `g1.md` (контракт поведения B1–B12, тесты, решения).
- `00-default` (неизвестный Host → 444 / `ssl_reject_handshake`) — не в G1:
  меняет ответ `mybots` на неизвестные Host, отдельное решение Владельца.
- `ai.vashfindir.ru.conf`: `/health`, `/openai/`, `/deepseek/`, `/kimi/`;
  `app-only` + токен; подмена `Authorization`; `limit_req`; остальное 404.
- Деплой проверяет ключи провайдеров с хоста шлюза (проверка 1 ниже).

### G2. ai-service через шлюз (доработка PR #2834 до мержа)

- `routes.yaml`: провайдеры `openai`, `deepseek`, `kimi` через шлюз; anthropic,
  yandex, local — из прод-маршрутов убрать. `categorize_transactions` —
  `fallback` deepseek → kimi → openai (порядок по цене, утверждается по
  `evals-compare`).
- Проверка при старте: провайдер мимо шлюза → сервис не стартует (+ тест).
- `prices.yaml` — три провайдера.
- `python -m ai_service llm-ping` — проверка 3 ниже.
- Прод-compose и деплой: вместо ключей провайдеров — `AI_GATEWAY_TOKEN` и
  `AI_SERVICE_LLM_GATEWAY_URL`.

### G3. Живые проверки и включение (по команде Владельца, §3.3)

`llm-ping` → `evals-compare` на синтетике → порядок моделей → `echo` → подсказки
одной компании (`docs/maintenance/ai-service-prod.md`).

## Проверки подключения каждого ИИ

Простые проверки «подключились, всё работает» — без задач и без данных
клиентов. Для каждого провайдера — `openai`, `deepseek`, `kimi`.

| # | Где | Что | Ожидание | Стоит денег |
|---|---|---|---|---|
| 1 | хост шлюза, при каждом деплое шлюза | `GET <провайдер>/v1/models` с ключом провайдера | 200 — ключ действителен, провайдер доступен с хоста | нет |
| 2 | app-сервер | `GET https://ai.vashfindir.ru/<провайдер>/v1/models` с `AI_GATEWAY_TOKEN` | 200 у всех трёх | нет |
| 3 | контейнер ai-service | `python -m ai_service llm-ping` — один чат-запрос «Ответь одним словом: ok», `max_tokens` 5 | по строке на провайдера: `ok`, модель, задержка; ключи и ответ целиком не печатаются | доли копейки |

Отрицательные (шлюз закрыт, как задумано):

| # | Что | Ожидание |
|---|---|---|
| 4 | п. 2 без токена или с неверным | 401 |
| 5 | п. 2 с любого другого IP | 403 |
| 6 | неизвестный путь, неизвестный Host | 404 / закрытое соединение |
| 7 | access log шлюза после проверок | есть строки запросов, нет токена, ключей и тел |

`llm-ping`: выход 0, только если все провайдеры маршрутов ответили; провайдер
без ответа — строка `fail: <код>` и выход 1. Команда — диагностика, в pipeline
задач не участвует, трассы не пишет.

## Чек-лист готовности

- [ ] DNS `ai.vashfindir.ru` → 109.73.204.179, сертификат, `certbot renew --dry-run` зелёный
- [ ] G0: бот работает после переноса, `nginx -T` сверен
- [ ] секреты шлюза заведены (`keys.md`), деплой шлюза зелёный, проверка 1 — 200 у трёх
- [ ] на шлюзе `gateway-secrets.conf` root:root 0600, проверка 7 чистая
- [ ] проверки 2, 4, 5, 6 — как в таблицах
- [ ] G2 смёржен: на app нет ключей провайдеров (`docker exec ai-service env | grep -c API_KEY` → 0)
- [ ] старт мимо шлюза запрещён (тест в CI)
- [ ] проверка 3 (`llm-ping`) — `ok` у трёх, по команде Владельца

## Откат

- ИИ: `AI_SERVICE_ENABLED=false` и деплой app.
- Домен `ai.`: убрать сайт из `sites-enabled`, reload — Telegram не затронут.
- G0: прежние файлы tg-gateway из бэкапа деплоя.
