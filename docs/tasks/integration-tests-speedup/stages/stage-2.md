## Stage 2: Postgres tuning для локального test-инстанса — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** 🛑 STOP, ждать Владельца (решить: paratest теперь / CI-тюнинг / остановиться на достигнутом)

### Почему не paratest (п.2 из исходного анализа)

Перед стартом проверил окружение: 2 vCPU, ~1.2Gi свободного RAM, часть уже в swap. При 2 ядрах
paratest даёт реалистично ×1.5–2, а не линейный рост, плюс требует новую dev-зависимость (STOP по
CLAUDE.md) и отдельные тестовые БД на воркер. Через `AskUserQuestion` Владелец подтвердил начать
с более дешёвого и безопасного пункта — тюнинга Postgres.

### Что сделано
- В `docker-compose.yml` для `site-postgres` добавлен `command: postgres -c fsync=off
  -c synchronous_commit=off -c full_page_writes=off`.
- Контейнер пересоздан (`docker compose up -d site-postgres`), настройки подтверждены через
  `SHOW fsync/synchronous_commit/full_page_writes` — все `off`.
- `docker-compose.prod.yml` не тронут — прод не затронут.
- CI (`.github/workflows/deploy.yml`) поднимает Postgres отдельным GitHub Actions service-контейнером
  напрямую из образа, никак не связан с этим `docker-compose.yml` — тюнинг сюда не долетает,
  это отдельная задача при желании продолжать.

### Затронутые файлы
- `docker-compose.yml` — modified (site-postgres: добавлен `command`)
- `docs/tasks/integration-tests-speedup/plan.md` — modified (добавлен раздел Stage 2)

### Self-review
- [x] Scope compliance — только конфигурация локального Postgres, без правок src/
- [x] Forbidden actions — none; не прод, не миграция, не публичный API, не новая зависимость
- [x] `composer test:integration` — зелёный дважды подряд: 695/695, 3334 assertions
- [x] `composer test:unit` — зелёный (регрессий нет): 1427/1427
- [x] CS-Fixer — N/A (yaml/docker-compose, не PHP)
- [x] ARCHITECTURE.md — N/A

### Измеренный эффект
- До Stage 2 (после Stage 1): **1m 38–39s**
- После Stage 2: **~50–53s** (два прогона подряд)
- Дополнительное ускорение ≈ **×1.9**
- Суммарно от исходного baseline (12m07s) — ускорение ≈ **×14**

### Команды для проверки
- `docker exec symfony-postgres psql -U app -d app -c "SHOW fsync; SHOW synchronous_commit; SHOW full_page_writes;"`
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:integration`

### Риски / на что обратить внимание ревьюеру
- `fsync=off` отключает гарантию сохранности данных Postgres при аварийном отключении
  питания/сбое ОС хоста — приемлемо для эфемерной локальной dev/test БД в docker volume, но
  Владельцу стоит знать: при таком сбое **локальная dev-БД** (не только test) может повредиться и
  потребовать пересоздания через миграции+фикстуры. Прод не затронут (`docker-compose.prod.yml`
  отдельный файл, не менялся).
- Настройка не действует в CI — там свой Postgres service-контейнер. Если нужно ускорить CI
  аналогично, это отдельный этап (правка `.github/workflows/deploy.yml`).
- Изменение потребовало пересоздания уже запущенного контейнера `site-postgres` — на момент
  выполнения к нему был подключен только сам контейнер и site-redis (воркеров messenger не было
  поднято), поэтому обрыв соединений при пересоздании прошёл без последствий.

### Открытые вопросы
- Продолжать ли на paratest (п.2, с учётом что выигрыш дальше ограничен 2 ядрами), или
  на этом остановиться — суммарный выигрыш уже ×14, решение за Владельцем.
