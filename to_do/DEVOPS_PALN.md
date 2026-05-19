Проверил 3 вложенных файла: **GitHub Actions deploy workflow**, **docker-compose.prod.yml**, **Makefile**.

Главный вывод: проект уже не “сырой” — есть GHCR, compose-prod, healthcheck’и, Redis/Postgres, workers, scheduler. Но для стабильного PROD сейчас есть **3 критичных риска**:

1. **Новый код поднимается до миграций БД**
   В workflow сначала `deploy`, потом отдельный job `migrations`. Если новая версия кода ожидает новую колонку/индекс/таблицу — PROD может получить 500 до завершения миграций.

2. **Прод использует `latest`-образы**
   В CI собирается и `latest`, и `${github.sha}`, но `docker-compose.prod.yml` запускает только `latest`. Это ломает воспроизводимость и быстрый rollback: непонятно, какой именно код сейчас в PROD.

3. **“Rolling update” фактически не rolling**
   В compose у сервисов одиночные контейнеры с `container_name`. `docker compose up -d` пересоздаёт контейнер, но не даёт настоящего zero-downtime rolling как в Swarm/Kubernetes/blue-green. `--wait` ждёт running/healthy, но это не равно отсутствию разрыва трафика. Docker официально описывает `--wait` как ожидание running/healthy-состояния, а не как rolling-механизм. ([Docker Documentation][1])

---

## Что сейчас есть по факту

### CI/CD

В workflow:

* build matrix собирает 4 образа:

    * `land-nginx`
    * `site-nginx`
    * `site-php-fpm`
    * `site-php-cli`
* push идёт в GHCR.
* deploy по SSH копирует только:

    * `docker-compose.prod.yml`
    * `docker/`
* затем на сервере:

    * `docker compose pull ...`
    * `up -d --wait site-php-fpm`
    * `up -d --wait site`
    * `up -d --wait land`
    * workers/scheduler
    * `docker image prune -f`
* миграции запускаются отдельным job после deploy.

### docker-compose.prod.yml

Есть:

* Traefik 3.3
* landing nginx
* site nginx
* PHP-FPM
* PHP-CLI
* 3 messenger worker’а
* Postgres 15
* Redis 7
* scheduler на supercronic
* healthcheck’и для nginx/php/postgres/redis/workers
* volume’ы для storage/logs/db/redis/certs

Хорошо: `depends_on: condition: service_healthy` используется для Postgres/Redis/PHP. Compose действительно умеет ждать healthcheck зависимостей при `service_healthy`. ([Docker Documentation][2])

### Makefile

Есть локальные команды для:

* поднятия окружения
* миграций
* фикстур
* unit/integration tests
* OpenAPI types
* scheduler test

Но есть смешение `docker-compose` и `docker compose`. Это надо привести к одному стандарту.

---

# План улучшения от простого к сложному

## Этап 1. Быстрые правки с максимальным эффектом

### 1. Запретить параллельные деплои

Сейчас в workflow нет `concurrency`. Два push в `master` могут запустить два деплоя одновременно и перемешать `current`, `latest`, миграции и контейнеры.

Добавить в workflow:

```yaml
concurrency:
  group: production-deploy
  cancel-in-progress: false
```

GitHub Actions официально поддерживает concurrency, чтобы в одной группе выполнялся только один workflow/job одновременно. ([GitHub Docs][3])

---

### 2. Убрать `latest` из PROD-деплоя

Сейчас compose использует:

```yaml
image: ghcr.io/pavelsur07/site-php-fpm:latest
```

Нужно перейти на:

```yaml
image: ghcr.io/pavelsur07/site-php-fpm:${IMAGE_TAG}
```

И на сервере перед deploy:

```bash
export IMAGE_TAG="${{ github.sha }}"
```

Так PROD будет запускать конкретную версию, а не “что сейчас лежит в latest”.

Минимально заменить для:

```yaml
ghcr.io/pavelsur07/land-nginx:${IMAGE_TAG}
ghcr.io/pavelsur07/site-nginx:${IMAGE_TAG}
ghcr.io/pavelsur07/site-php-fpm:${IMAGE_TAG}
ghcr.io/pavelsur07/site-php-cli:${IMAGE_TAG}
```

---

### 3. Переставить миграции до переключения web-контейнеров

Текущий порядок опасный:

```text
deploy new containers
then migrations
```

Безопаснее:

```text
pull new images
backup database
run migrations using new site-php-cli image
update php-fpm/site/workers
run smoke check
```

Но важно: миграции должны быть backward-compatible. То есть сначала добавляем nullable-колонки/индексы/таблицы, потом выкатываем код, потом отдельной задачей удаляем старое.

---

### 4. Отключать workers на время рискованных миграций

Сейчас workers обновляются после PHP-FPM, но миграции идут вообще после деплоя. При изменении схемы workers могут в этот момент писать/читать старую структуру.

Минимально:

```bash
docker compose -f docker-compose.prod.yml stop \
  site-messenger-worker-sync \
  site-messenger-worker-pipeline \
  site-messenger-worker-ads \
  scheduler

docker compose -f docker-compose.prod.yml run --rm site-php-cli \
  bin/console doctrine:migrations:migrate --no-interaction

docker compose -f docker-compose.prod.yml up -d \
  site-messenger-worker-sync \
  site-messenger-worker-pipeline \
  site-messenger-worker-ads \
  scheduler
```

---

### 5. Добавить внешний smoke-check после deploy

Healthcheck контейнера — это хорошо, но он не доказывает, что приложение реально работает снаружи через Traefik.

После deploy добавить:

```bash
curl -fsS https://app.vashfindir.ru/health
```

И отдельно желательно бизнес-smoke:

```bash
curl -fsS https://app.vashfindir.ru/login
```

Или закрытый endpoint:

```bash
curl -fsS -H "X-Health-Token: $HEALTH_CHECK_TOKEN" \
  https://app.vashfindir.ru/internal/health/ready
```

---

### 6. Не удалять старые Docker images сразу после deploy

Сейчас есть:

```bash
docker image prune -f
```

Это ухудшает rollback. Минимум — убрать prune из обычного deploy. Делать очистку отдельной ручной задачей или cron с сохранением последних N версий.

---

## Этап 2. Исправить CI, чтобы плохой код не доходил до PROD

Сейчас workflow проверяет миграции на пустой БД и API types, но не видно обязательного запуска unit/integration tests перед deploy.

Нужно добавить перед build/deploy:

```text
composer validate
composer install
php bin/console lint:container --env=test
php bin/console lint:twig templates
php bin/console doctrine:schema:validate --skip-sync
php bin/phpunit --testsuite unit
php bin/phpunit --testsuite integration --filter SmokePersistenceTest
yarn install --frozen-lockfile
yarn build
```

Порядок лучше такой:

1. `quality`
2. `unit-tests`
3. `migration-empty-db`
4. `api-types-check`
5. `build-and-push`
6. `deploy`

---

### Отдельная ошибка в текущем `migrations-empty-db`

В workflow создаётся БД:

```text
app_test
```

Но `DATABASE_URL` указывает:

```text
pgsql://app:app@127.0.0.1:5432/app
```

То есть по самому файлу видно несоответствие: создаётся `app_test`, а миграции могут идти в `app`. Нужно либо убрать создание `app_test`, либо явно использовать:

```yaml
DATABASE_URL: "pgsql://app:app@127.0.0.1:5432/app_test?serverVersion=15&charset=utf8"
```

---

## Этап 3. Логи и диск

Сейчас `x-logging` задан, но применяется не ко всем сервисам. Видно применение у:

```yaml
site-php-fpm
site-postgres
```

Но не видно у:

```yaml
traefik
land
site
site-messenger-worker-sync
site-messenger-worker-pipeline
site-messenger-worker-ads
scheduler
site-redis
```

Нужно применить:

```yaml
logging: *default-logging
```

ко всем долгоживущим сервисам. Иначе Docker json logs могут разрастись и забить диск.

---

## Этап 4. Backup и rollback

Сейчас в compose есть volume’ы:

```yaml
postgres_data
site_storage
site_company_storage
site_var_log
traefik-certs
site-redis-data
```

Критичные для backup:

```text
postgres_data
site_storage
site_company_storage
traefik-certs
```

Нужно добавить:

1. ежедневный `pg_dump`
2. backup storage volume’ов
3. хранение вне сервера
4. проверку restore хотя бы раз в месяц
5. ручную команду rollback на предыдущий `IMAGE_TAG`

Минимальный rollback должен выглядеть так:

```bash
export IMAGE_TAG=<previous_sha>
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --wait
```

---

## Этап 5. Привести Makefile в порядок

Сейчас смешано:

```bash
docker-compose
docker compose
```

Нужно выбрать один стандарт. Лучше современный:

```bash
docker compose
```

Также опасный target:

```makefile
docker-down-clear:
	docker-compose down -v --remove-orphans
```

Для локалки нормально, но если случайно выполнить с prod-контекстом — можно удалить volume’ы. Я бы переименовал:

```makefile
local-docker-down-clear
```

И добавил защиту:

```makefile
guard-local:
	@test "$$APP_ENV" != "prod" || (echo "Refuse to run on prod"; exit 1)
```

---

## Этап 6. Безопасность deploy

### Сейчас есть риск

В workflow используется:

```bash
scp -o StrictHostKeyChecking=no
ssh -o StrictHostKeyChecking=no
```

Это удобно, но небезопасно. Лучше добавить `known_hosts` через secret:

```bash
mkdir -p ~/.ssh
echo "${{ secrets.SSH_KNOWN_HOSTS }}" >> ~/.ssh/known_hosts
```

И убрать `StrictHostKeyChecking=no`.

---

### Traefik dashboard

В compose:

```yaml
--api.dashboard=true
--api.insecure=true
127.0.0.1:8080:8080
```

Так как порт прибит к `127.0.0.1`, наружу он не должен торчать. Но лучше всё равно:

* либо выключить `api.insecure`
* либо закрыть dashboard basic auth / VPN / SSH tunnel only

---

### Hardcoded placeholder

В prod env есть:

```yaml
LLM_API_URL: "https://example.com/v1/chat/completions"
LLM_API_KEY: "change-me"
```

Если LLM-функционал не используется — отключить feature flag. Если используется — вынести в secrets.

---

## Этап 7. Наблюдаемость

Минимальный набор для стабилизации PROD:

1. Sentry уже проброшен через `SENTRY_DSN` — проверить, что реально ловит PHP exceptions.
2. Добавить алерты:

    * 5xx на Traefik/nginx
    * контейнер unhealthy
    * диск > 80%
    * Postgres connections
    * Redis memory / queue length
    * failed Messenger messages
3. Добавить команду состояния:

```bash
docker compose ps
docker compose logs --tail=200 site-php-fpm
docker compose logs --tail=200 site-messenger-worker-pipeline
docker system df
df -h
```

---

# Приоритетный план внедрения

## Сначала сделать обязательно

1. Добавить `concurrency` в GitHub Actions.
2. Перейти с `latest` на `${IMAGE_TAG}`.
3. Перенести миграции до обновления web/workers.
4. Добавить внешний smoke-check после deploy.
5. Убрать `docker image prune -f` из обычного deploy.
6. Применить `logging: *default-logging` ко всем сервисам.
7. Исправить `migrations-empty-db`: `app_test` vs `DATABASE_URL=/app`.

## Потом

8. Добавить unit/smoke tests как обязательный gate перед deploy.
9. Стандартизировать Makefile на `docker compose`.
10. Добавить backup Postgres/storage и restore-check.
11. Убрать `StrictHostKeyChecking=no`.
12. Разделить health endpoints:

    * `/health/live` — процесс жив
    * `/health/ready` — БД/Redis доступны
    * `/internal/health/deep` — расширенная проверка с токеном

## Более сложный этап

13. Перейти на release-директории:

```text
releases/<sha>/
current -> releases/<sha>
```

14. Добавить blue-green или хотя бы два backend-контейнера без `container_name`.
15. Убрать `container_name`, чтобы появилась возможность scale/rolling.
16. Настроить нормальный zero-downtime deploy через Traefik service switching.

---

# Что я бы не делал сейчас

Не надо сразу тащить Kubernetes, Swarm или сложный GitOps. Сейчас максимальный эффект дадут простые вещи:

```text
SHA-tag images
safe migration order
concurrency
smoke checks
rollback
backups
logs rotation
```

И только после этого имеет смысл усложнять инфраструктуру.

[1]: https://docs.docker.com/reference/cli/docker/compose/up/?utm_source=chatgpt.com "docker compose up"
[2]: https://docs.docker.com/compose/how-tos/startup-order/?utm_source=chatgpt.com "Control startup and shutdown order in Compose"
[3]: https://docs.github.com/actions/writing-workflows/choosing-what-your-workflow-does/control-the-concurrency-of-workflows-and-jobs?utm_source=chatgpt.com "Control the concurrency of workflows and jobs"
