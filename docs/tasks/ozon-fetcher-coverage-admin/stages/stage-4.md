### Stage 4: прод — секрет, env, cron, миграция — DONE (приёмка на проде — после деплоя)

**Risk:** HIGH-EXTERNAL (прод, секрет, миграция) · **Base:** `3bea43a91` · **Branch:** `feat/ozon-fetcher-coverage-admin`
**Внешнее ревью:** в составе handoff задачи

## 1. Какую задачу решаем

Код этапов 1–3 на проде не заработает сам: у сервиса нет `SERVICE_API_TOKEN`,
поэтому его `/internal/*` закрыт. У scheduler нет адреса и токена сервиса,
поэтому cron выйдет «выключено». Таблиц витрины нет, пока не прогнана
миграция. Кроме того, выкатка этапа 1 без секрета закрыла бы ручную проверку
`fetch-status` на проде.

## 2. Что будет результатом работы

- **Секрет** `OZON_FETCHER_API_TOKEN` в GitHub — агент заводит через `gh secret set`:
  hex-48 из `openssl rand`, значение не выводится; в репозитории — только имя.
- **`docker-compose.prod.yml`:**
  - `ozon-fetcher`: `SERVICE_API_TOKEN: ${OZON_FETCHER_API_TOKEN:-}`;
  - `scheduler`: `OZON_FETCHER_BASE_URL: http://ozon-fetcher:8080` и
    `OZON_FETCHER_API_TOKEN: ${OZON_FETCHER_API_TOKEN:-}`.
  - Отклонение от плана задачи: в `x-php-env` (fpm, воркеры) переменные не
    добавляются. Страница читает только витрину, команда идёт только из
    scheduler, а лишние копии токена не нужны.
- **`deploy.yml`:**
  - экспорт `OZON_FETCHER_API_TOKEN` в одинарных кавычках;
  - проверка алфавита `A-Za-z0-9._~-`;
  - при `OZON_FETCHER_ENABLED=true` секрет обязателен, как
    `INTERNAL_AUTH_TOKEN`.
- **Миграция** `Version20261018100000` — по `docs/workflow/release.md`:
  отдельный `production_action=migrations`; в запросе «merge and deploy» —
  явная строка о миграции. Она обратима, данные не трогает (две новые пустые
  таблицы), бэкап не нужен.
- **`docs/maintenance/ozon-fetcher-prod.md`** уже описывает секрет (этап 1);
  дополнить разделом про витрину и cron.

## 3. Что считаем выполненной работой

Изменения в репозитории готовы, секрет заведён, `docker compose config` с
профилем проходит, отсутствие секрета ловит деплой. Проверка на проде
(после «merge and deploy» и миграции):
- `fetch-status` и `coverage` отвечают по новому токену;
- cron наполнил витрину: 4 кабинета, M, M-1, M-2;
- страница открывается;
- данные совпадают с `/internal/coverage`;
- в GlitchTip нет новых ошибок.

## 4. Чек-лист приёмки

- [ ] секрет `OZON_FETCHER_API_TOKEN` заведён (`gh secret list` показывает имя), значение нигде не выведено
- [ ] compose: `ozon-fetcher` и `scheduler` получают переменные; `docker compose -f docker-compose.prod.yml --profile ozon-fetcher config -q` — OK
- [ ] deploy.yml: экспорт, проверка алфавита, обязательность при `OZON_FETCHER_ENABLED=true`; YAML валиден
- [ ] `ozon-fetcher-prod.md`: витрина, cron, проверка новым токеном
- [ ] handoff: строка о миграции в запросе «merge and deploy»
- [ ] после деплоя (на приёмке): миграция прошла; витрина наполнена; страница 200; данные = `/internal/coverage`; GlitchTip чист

## Итог

- Секрет `OZON_FETCHER_API_TOKEN` заведён (hex-48, значение не выводилось); compose `config` с профилем — OK; YAML валиден.
- Внешнее ревью Codex (handoff), итерация 1: секреты подставляются в текст удалённого скрипта (так устроен весь
  шаг деплоя и до задачи), и кавычка в значении выполнилась бы на сервере раньше проверки алфавита → добавлен
  шаг раннера `🔎 Check service secrets alphabet` до SSH: секреты сервисов и allowlist проверяются как данные.
  Переделка передачи всех секретов в деплой — отдельная задача.
