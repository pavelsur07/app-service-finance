## Stage 2: sentry.yaml — окружение, релиз, PII/payload — DONE

**Риск:** 🔴 HIGH (конфигурация внешнего сервиса наблюдаемости)
**Следующее действие:** 🛑 STOP, ждать Владельца перед Stage 3

### Что сделано
- В `config/packages/sentry.yaml` (`options`) добавлено/зафиксировано:
  - `environment: '%env(APP_ENV)%'` — тег окружения (dev/test/prod ядра).
  - `release: '%env(default::SENTRY_RELEASE)%'` — релиз = git sha из деплоя.
  - `send_default_pii: false` — явно (хотя это и дефолт SDK).
  - `max_request_body_size: 'small'` — ограничение тела запроса в событии (меньше payload и риска утечки).
  - `ignore_exceptions` — оставлен как был (см. «Решения» ниже).
- В `site/.env` добавлен placeholder `SENTRY_RELEASE=` (пусто локально/в тестах; в деплое прокидывается git sha).

### Важная находка
Бандл sentry-symfony **уже** имеет дефолты `environment=%kernel.environment%` и `release=%env(default::SENTRY_RELEASE)%` (см. `config/reference.php:1567,1571`). То есть release-механизм был подключён изначально и не работал только потому, что переменная `SENTRY_RELEASE` нигде не задавалась. Stage 2 этот контракт активирует и делает явным в конфиге.

### Решения по scope
- **`ignore_exceptions` не расширял.** 4xx уже выведены из ERROR на Stage 1 (через `framework.exceptions`) → в GlitchTip не попадают, дублировать их здесь не нужно. Доменные исключения ловятся листенерами (`IngestionExceptionListener` → 422) и до ERROR не доходят. Добавлять спекулятивные классы = риск спрятать реальные ошибки. Расширяем только при появлении конкретного шумного класса (по факту в GlitchTip).
- **`server_name` не задавал** — SDK автоопределяет; в контейнере это id, тег малополезен, разделение staging/prod идёт по проекту/DSN.
- **`traces_sample_rate` не включал** — performance вне scope (решение Владельца).
- **`sample_rate` оставлен дефолтным (1.0)** — ошибки собираем все, семплинг ошибок не вводим.

### Затронутые файлы
- `site/config/packages/sentry.yaml` — modified (options)
- `site/.env` — modified (placeholder `SENTRY_RELEASE=`)

### Self-review
- [x] Scope compliance — только теги/PII/payload Sentry; DSN, транспорты, продуктовый код не тронуты
- [x] Patterns / naming — N/A (нет PHP-кода)
- [x] Forbidden actions — none (нет миграций, нет правок legacy `src/`, нет новых зависимостей, prod-infra не трогал)
- [x] Security — `send_default_pii: false` + `max_request_body_size: small` снижают риск утечки; секреты не логируются
- [x] CS / PHPStan — N/A (нет PHP-кода; PHPStan в проекте не настроен)
- [x] Tests — `lint:yaml` OK; `debug:config sentry options` показывает применённые опции; функциональный тест зелёный (ядро грузит обновлённый `sentry.yaml` без DI-ошибок)
- [x] ARCHITECTURE.md — N/A

### Команды для проверки
```
docker compose run --rm -T site-php-cli php bin/console lint:yaml config/packages/sentry.yaml --env=test
docker compose run --rm -T site-php-cli php bin/console debug:config sentry options --env=test
docker compose run --rm -T site-php-cli php bin/phpunit --testsuite functional --filter ExceptionLogLevelTest
```

### 🔴 Требуется действие Владельца (prod-infra, вне моих правок)
Чтобы release реально заполнялся в prod, деплой должен прокидывать git sha. Symfony-сторона готова; нужно дописать в `docker-compose.prod.yml` рядом с `SENTRY_DSN` (в обоих сервисах, ~стр. 23 и 403):
```yaml
SENTRY_RELEASE: ${SENTRY_RELEASE:-}
```
и в пайплайне деплоя экспортировать перед `up`:
```sh
export SENTRY_RELEASE=$(git rev-parse HEAD)
```
Не применял сам — это prod-инфраструктура и механика CI на стороне Владельца. `${…:-}` гарантирует, что отсутствие переменной ничего не ломает (пустой релиз = трекинг просто выключен).

### Риски / на что обратить внимание ревьюеру
- `environment` теперь `%env(APP_ENV)%`. В staging-деплое `APP_ENV` обычно тоже `prod` → тег `environment` будет `prod` и на staging, и на prod. Это **ожидаемо**: разделение идёт по отдельным GlitchTip-проектам (решение Владельца), а не по тегу.
- Тегирование/релиз нельзя проверить юнит-тестом без перехвата отправляемых событий — проверяется по факту в GlitchTip после деплоя.

### Открытые вопросы
- нет (Stage 3 — `before_send` скраббер — ждёт STOP)
