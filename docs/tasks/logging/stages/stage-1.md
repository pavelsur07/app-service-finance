## Stage 1: Уровни логирования для 4xx HTTP-ошибок — DONE

**Риск:** 🔴 HIGH (правка `framework.yaml` — поведение наблюдаемости)
**Следующее действие:** 🛑 STOP, ждать Владельца перед Stage 2

### Что сделано
- В `config/packages/framework.yaml` добавлен блок `framework.exceptions`, понижающий уровень логирования **конкретных 4xx-подклассов** до `notice`/`warning`:
  - `notice`: `NotFoundHttpException` (404), `MethodNotAllowedHttpException` (405), `AccessDeniedHttpException` (403), `Security\…\AccessDeniedException`, `UnauthorizedHttpException` (401)
  - `warning`: `BadRequestHttpException` (400), `UnprocessableEntityHttpException` (422), `ConflictHttpException` (409), `TooManyRequestsHttpException` (429)
- **Базовый `HttpException` и все 5xx НЕ затронуты** — остаются `error`/`critical` (иначе спрятали бы серверные ошибки вроде `ServiceUnavailableHttpException`).
- Эффект: 4xx больше не достигают отдельного `sentry`-handler (`level: error`) → не засоряют GlitchTip ложными «инцидентами». При этом они остаются в логах (на пониженном уровне).

### Эмпирическое подтверждение риска №1 (из Stage 0)
Регрессионный тест на **немодифицированном** `framework.yaml` падает: 404 действительно логируется на уровне ERROR. После фикса — проходит. Гипотеза Stage 0 подтверждена на реальном ядре.

### Затронутые файлы
- `site/config/packages/framework.yaml` — modified (блок `exceptions`)
- `site/config/packages/test/monolog.yaml` — new (test-only `TestHandler` для перехвата записей в тесте)
- `site/tests/Functional/Logging/ExceptionLogLevelTest.php` — new (регрессия)
- `docs/tasks/logging/plan.md` — modified (зафиксированы решения Владельца)

### Self-review
- [x] Scope compliance — только понижение уровней 4xx; продуктовые HTTP-ответы клиентам не изменены (меняется лишь log_level)
- [x] Patterns / naming — тест `final class`, namespace по конвенции `App\Tests\Functional\…`, extends `WebTestCaseBase`
- [x] Forbidden actions — none (нет миграций, нет правок legacy `src/`, нет изменения публичного API, нет новых зависимостей)
- [x] Security — N/A (конфиг логирования; PII/токены не затрагиваются)
- [x] CS-fixer — чисто на изменённом файле (`Found 0 of 1 files`); 678 «можно починить» в выводе — **существующий долг по всему репо, не из этой задачи**
- [x] PHPStan — N/A (в проекте не настроен: нет зависимости/таргета)
- [x] Tests — `ExceptionLogLevelTest` зелёный с фиксом, красный без фикса (регрессия доказана)
- [x] YAML lint — `lint:yaml` OK на обоих изменённых конфигах
- [x] ARCHITECTURE.md — N/A (нет нового Facade/Entity/Enum)

### Команды для проверки
```
# регрессия (зелёный с фиксом)
docker compose run --rm -T site-php-cli php bin/phpunit --testsuite functional --filter ExceptionLogLevelTest
# стиль изменённого файла
docker compose run --rm site-php-cli php vendor/bin/php-cs-fixer fix --dry-run --diff tests/Functional/Logging/ExceptionLogLevelTest.php
# валидность конфигов
docker compose run --rm -T site-php-cli php bin/console lint:yaml config/packages/framework.yaml config/packages/test/monolog.yaml --env=test
```

### Риски / на что обратить внимание ревьюеру
- Маппинг идёт по **классам**, а не по статус-коду. `throw new HttpException(422, …)` через **базовый** класс (без конкретного подкласса) останется на `error`. Это редкий кейс; вынесен в конвенцию Stage 6 (использовать конкретные классы 4xx). Если в коде есть массовые `new HttpException(4xx)`, имеет смысл проверить отдельно.
- `AccessDeniedException` (Security) иногда конвертируется в `AccessDeniedHttpException` до `ErrorListener` — поэтому в маппинг включены **оба** класса.
- `config/packages/test/monolog.yaml` добавляет публичный `TestHandler` только в test-окружении (на prod/dev не влияет).

### Открытые вопросы
- нет (решения Владельца по Stage 0 учтены; Stage 2 ждёт STOP)
