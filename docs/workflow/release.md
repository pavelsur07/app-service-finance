# Production release

Релиз разрешается владельцем по AGENTS.md §3.2. Локальная реализация и Draft PR
не разрешают запуск production. PR с миграцией требует отдельного разрешения
на миграцию вместе с выпуском.

Ручные действия `deploy`, `migrations`, `release` доступны только на master.
`release_sha` обязателен: полный 40-символьный SHA, равный SHA запуска workflow.
Не подставлять «последний master» в середине запуска. Пример после разрешения:

```bash
gh workflow run deploy.yml --ref master -f production_action=release -f release_sha=<full-SHA>
```

`release`: проверенный комплект → полный логический backup всех non-template
БД текущего PostgreSQL → Doctrine и сервисные миграции → проверка версий схем
→ rolling deploy → здоровье → SHA/digest/длительности этапов.

`migrations`: backup, миграции и проверка версий без deploy. `deploy` и обычный
push: проверка версий, затем deploy, без самостоятельного запуска миграций.
Проверяются Doctrine up-to-date и реестры Goose/Python против SQL-файлов того
же SHA. Глобальный doctrine:schema:validate не добавлен: историческая схема
может отличаться от ORM mapping и это не эквивалент pending migrations.

Manifest содержит SHA, доверенные workflow/run/attempt, provenance сборок и
12 digest (9 application + 3 уже установленных infrastructure images).
Каждый образ привязан к descriptor успешной сборки; SPA — после browser smoke.
Наличие Docker-тега само по себе не разрешает выпуск. Переиспользуются только
артефакты доверенного deploy.yml на master после обязательных gates, включая
PHPStan; наличие всех digest проверяется в реестре. Ошибка доступа не является
cache miss. При отсутствии комплекта выполняются сборки и проверки.

Один production job держит `production-release`, cancel-in-progress=false;
удалённый flock дополнительно исключает перемешивание этапов. До изменения
приложения проверяется Git ancestry установленного high-water SHA. Повтор того
же SHA разрешён. High-water записывается до миграции, last-success — только
после deploy/health. Частичный rerun сохраняет успешные build descriptors и
имена upstream-артефактов; resolver учитывает последние результаты jobs до
публикующего attempt. Конфигурация архивируется git archive ровно из SHA.

Все запускаемые application-контейнеры используют digest Compose override.
Для Traefik/PostgreSQL/Redis фиксируется фактически запущенный RepoDigest,
а не текущий upstream tag; перед мутацией digest сверяется повторно.
Обычный релиз не пересоздаёт infrastructure: app up всегда с --no-deps и
явным списком. Drift инфраструктуры блокирует комплект; не обходить его
автоматическим downgrade — нужен новый проверенный выпуск. Worker с
отключённым healthcheck проверяется по running, включая enabled eval worker.

Backups: `/var/backups/app-service-finance/<sha>/<run>-<attempt>/`, umask 077,
каталоги 0700, файлы 0600. Workflow deploy user должен уже иметь право записи
в корень; при отсутствии права — ошибка, без sudo/chmod расширения доступа.
Проверяются pg_dump, непустой custom archive, полное чтение pg_restore в
/dev/null и checksum. Это не проверка восстановления. Не включены globals
ролей/конфигурация кластера; каждая БД имеет собственный consistent snapshot.
При ошибке backup/migration/schema следующие этапы не исполняются. Автоотката
и автоматического удаления backups нет; retention и восстановление остаются
отдельными задачами утверждённого плана.

Сохранена существующая очистка Docker images старше 336 часов. Upload tar
удаляется после распаковки, предыдущая конфигурация — после успешного health.
Неуспешные staging/config и backups сохраняются для диагностики; их ручная
очистка/retention — follow-up, не расширять её на данные автоматически.

До handoff:

```bash
make test-release-pipeline test-deploy-workflow test-frontend-release test-frontend-auth-routing
RELEASE_TEST_POSTGRES_IMAGE=postgres:15-alpine make test-release-pipeline
```

Вторая команда включает временную локальную PostgreSQL: реальный dump,
полное чтение, checksum и подтверждение неизменности БД; внешние сервисы
репетируются заглушками. Production не вызывается. После разрешённого
выпуска сверить SHA, digest, версии схем, здоровье и длительности через
read-only wrappers (docs/maintenance/prod-access.md).

Запрос для PR с миграцией:

```text
Ready: PR #<n> "<title>" — добавляет миграцию <Version...>:
<обратимость, затронутые данные>. Выполнить release с backup, миграцией и deploy?
Reply: "run the migration and deploy #<n>"
```
