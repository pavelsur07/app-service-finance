init: docker-down-clear site-clear docker-up site-init

docker-up:
	docker-compose up -d
down:
	docker-compose down --remove-orphans
docker-down-clear:
	docker-compose down -v --remove-orphans
docker-pull:
	docker-compose pull
docker-build:
	docker-compose build --pull

site-clear:
	docker run --rm -v ${PWD}/site:/app -w /app alpine sh -c 'rm -rf  .ready var/cache/* var/log/* var/test/*'

site-init: site-composer-install site-wait-db site-migrations site-fixtures site-frontend-install

site-composer-install:
	docker-compose run --rm site-php-cli composer install

site-frontend-install:
	docker-compose run --rm site-frontend npm run build

site-wait-db:
	until docker-compose exec -T site-postgres pg_isready --timeout=0 --dbname=app ; do sleep 1 ; done

site-migrations:
	docker-compose run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction

site-fixtures:
	docker-compose run --rm site-php-cli php bin/console doctrine:fixtures:load --no-interaction

site-cs-check:
	docker-compose run --rm site-php-cli composer cs:check   # Проверка PHP-стиля

site-cs-fix:
	docker-compose run --rm site-php-cli composer cs:fix # Автопочинка PHP-стиля
    #docker-compose run --rm site-php-cli composer cs:phpcs   # Проверка через phpcs (PSR-12)
    #docker-compose run --rm site-php-cli composer cs:twig    # Линт Twig-шаблонов

# ===== LOCAL CI / QUALITY =====

.PHONY: site-ci site-ci-php site-ci-frontend site-ci-full site-ci-php-advisory site-ci-frontend-advisory site-ci-unit site-ci-api-types-check site-composer-validate site-lint-container site-lint-yaml site-lint-twig site-phpstan site-frontend-install-ci site-frontend-typecheck site-frontend-build site-ci-migrations-empty-db

# Быстрый локальный CI-набор перед PR: backend quality + frontend build + unit tests.
site-ci: site-ci-php site-ci-frontend site-ci-unit

# Расширенный локальный CI-набор для изменений в БД/API.
site-ci-full: site-ci site-ci-migrations-empty-db site-ci-api-types-check

# PHP quality gate. PHP style и PHPStan пока вынесены отдельно, потому что текущий код требует отдельной стабилизации.
site-ci-php: site-composer-validate site-lint-yaml site-lint-twig site-lint-container

# Advisory checks: включать в обязательный gate после отдельной стабилизации.
site-ci-php-advisory: site-cs-check site-phpstan

site-composer-validate:
	docker compose run --rm -T site-php-cli composer validate --no-check-publish

site-lint-container:
	docker compose run --rm -T -e APP_ENV=test -e APP_DEBUG=0 -e APP_SECRET=test -e DATABASE_URL='pgsql://app:app@site-postgres:5432/app_test?serverVersion=15&charset=utf8' -e REDIS_DSN='redis://site-redis:6379' -e MESSENGER_TRANSPORT_DSN='redis://site-redis:6379/messages' site-php-cli php bin/console lint:container --env=test

site-lint-yaml:
	docker compose run --rm -T site-php-cli php bin/console lint:yaml --parse-tags config

site-lint-twig:
	docker compose run --rm -T site-php-cli php bin/console lint:twig templates

site-phpstan:
	docker compose run --rm -T -e APP_ENV=test -e APP_DEBUG=0 -e APP_SECRET=test -e DATABASE_URL='pgsql://app:app@site-postgres:5432/app_test?serverVersion=15&charset=utf8' -e REDIS_DSN='redis://site-redis:6379' -e MESSENGER_TRANSPORT_DSN='redis://site-redis:6379/messages' site-php-cli composer analyse

site-frontend-install-ci:
	docker compose run --rm -T site-frontend yarn install --frozen-lockfile

site-frontend-typecheck: site-frontend-install-ci
	docker compose run --rm -T site-frontend yarn typecheck

site-frontend-build: site-frontend-install-ci
	docker compose run --rm -T site-frontend yarn build

site-ci-frontend: site-frontend-build

# Advisory frontend check: включать в обязательный gate после исправления текущих TS-ошибок.
site-ci-frontend-advisory: site-frontend-typecheck

site-ci-unit:
	docker compose run --rm -T site-php-cli composer test:unit

# Проверка миграций на test-БД через уже существующую подготовку окружения.
site-ci-migrations-empty-db:
	docker compose up -d site-postgres site-redis
	until docker compose exec -T site-postgres pg_isready --timeout=0 --dbname=app ; do sleep 1 ; done
	docker compose run --rm -T -e APP_ENV=test site-php-cli php bin/console doctrine:database:create --if-not-exists --env=test
	docker compose run --rm -T -e APP_ENV=test site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test

site-ci-api-types-check:
	docker compose up -d site-postgres site-redis
	docker compose run --rm -T -e APP_ENV=dev -e APP_DEBUG=0 -e DATABASE_URL='pgsql://app:secret@site-postgres:5432/app?serverVersion=15&charset=utf8' -e REDIS_DSN='redis://site-redis:6379' -e MESSENGER_TRANSPORT_DSN='redis://site-redis:6379/messages' site-php-cli sh -c "php bin/console nelmio:apidoc:dump --format=json > var/openapi.json"
	docker compose run --rm -T site-frontend sh -c "npx openapi-typescript var/openapi.json -o /tmp/schema.check.d.ts && diff /tmp/schema.check.d.ts assets/api/schema.d.ts"

# ===== TESTS =====

site-test-telegram:
	 docker-compose run --rm site-php-cli composer test -- --testsuite telegram

# Быстрые юнит-тесты (без БД)
site-test-unit:
	docker-compose run --rm site-php-cli composer test:unit

# Интеграционные (готовим среду + БД)
site-test-int: site-test-integration

site-test-integration: site-test-init
	docker-compose run --rm site-php-cli composer test:integration

# Все тесты
site-test: site-test-init
	docker-compose run --rm site-php-cli composer test

# ---- подготовка окружения тестов (smoke) ----
site-test-smoke-init: site-test-env site-test-wait-db site-test-db site-test-migrations

site-test-smoke: site-test-smoke-init
	docker-compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite=integration --filter SmokePersistenceTest

# Покрытие (нужен xdebug или pcov в CLI-образе)
site-test-cov: site-test-init
	docker-compose run --rm -e XDEBUG_MODE=coverage site-php-cli ./vendor/bin/phpunit -c phpunit.xml --coverage-html var/coverage --coverage-text

# ---- подготовка окружения тестов ----
site-test-init: site-composer-install site-test-env site-test-wait-db site-test-db site-test-migrations site-test-fixtures

site-test-env:
	# .env.test (если нет — скопируем из .env)
	docker-compose run --rm -T site-php-cli sh -lc 'test -f .env.test || cp .env .env.test'
	docker-compose run --rm site-php-cli php bin/console cache:clear --env=test

site-test-wait-db:
	# Ждём Postgres
	until docker-compose exec -T site-postgres pg_isready --timeout=0 --dbname=app ; do sleep 1 ; done

site-test-db:
	docker-compose run --rm -T site-php-cli php bin/console doctrine:database:create --if-not-exists --env=test

site-test-migrations:
	docker-compose run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test

site-test-fixtures:
	# Если для интеграционных нужны данные — загружаем минимальные фикстуры
	docker-compose run --rm site-php-cli php bin/console doctrine:fixtures:load --no-interaction --env=test

# Полный пересоздатeль test-БД (на случай поломанных фикстур)
site-test-db-rebuild: site-test-wait-db
	docker-compose run --rm site-php-cli php bin/console doctrine:database:drop --force --env=test
	docker-compose run --rm site-php-cli php bin/console doctrine:database:create --env=test
	docker-compose run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test
	docker-compose run --rm site-php-cli php bin/console doctrine:fixtures:load --no-interaction --env=test

# ===== CODEX TESTS =====

CODEX_SYSTEM_PATH := /usr/bin:/usr/local/bin:/usr/sbin:/bin:/sbin

codex-prepare:
	PATH="$(CODEX_SYSTEM_PATH)" which php
	PATH="$(CODEX_SYSTEM_PATH)" php -v
	PATH="$(CODEX_SYSTEM_PATH)" php --ini
	PATH="$(CODEX_SYSTEM_PATH)" php -r 'foreach (["dom","xml","xmlwriter","SimpleXML","mbstring","curl","intl","zip","redis","sodium"] as $$ext) { echo $$ext . ": " . (extension_loaded($$ext) ? "OK" : "MISSING") . PHP_EOL; }'
	cd site && unset COMPOSER_NO_DEV && PATH="$(CODEX_SYSTEM_PATH)" COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress --no-scripts
	test -f site/vendor/autoload.php
	test -f site/bin/phpunit
	test -f site/vendor/phpunit/phpunit/phpunit
	cd site && PATH="$(CODEX_SYSTEM_PATH)" php -d memory_limit=1G bin/phpunit --version

codex-test-unit: codex-prepare
	cd site && APP_ENV=test APP_DEBUG=1 PATH="$(CODEX_SYSTEM_PATH)" php -d memory_limit=1G bin/phpunit --testsuite unit

codex-test-unit-filter: codex-prepare
	cd site && APP_ENV=test APP_DEBUG=1 PATH="$(CODEX_SYSTEM_PATH)" php -d memory_limit=512M bin/phpunit --testsuite unit --filter "$(FILTER)"

# ===== API TYPES / OPENAPI =====
#
# Оба контейнера (site-php-cli и site-frontend) видят каталог ./site/ как /app,
# поэтому промежуточный файл var/openapi.json читается обоими без проблем.
# site-php-cli экспортирует спеку через CLI-команду Nelmio (без HTTP/auth),
# site-frontend перегоняет JSON → TypeScript через openapi-typescript.

.PHONY: api-doc-export api-doc-lint api-types api-types-check

# Экспорт OpenAPI-спеки в JSON-файл (через PHP CLI — не требует HTTP и auth)
api-doc-export:
	docker compose exec -T site-php-cli sh -c "php bin/console nelmio:apidoc:dump --format=json > var/openapi.json"
	@echo "✓ OpenAPI spec exported to site/var/openapi.json"

# Lint спеки через Spectral (опционально, нужен сконфигурированный контейнер с node)
api-doc-lint: api-doc-export
	docker compose exec -T site-frontend sh -c "npx -y @stoplight/spectral-cli lint var/openapi.json"

# Основная команда разработчика: сгенерировать TS-типы из экспортированной спеки
api-types:
	docker compose exec -T site-php-cli sh -c "php bin/console nelmio:apidoc:dump --format=json > var/openapi.json"
	docker compose exec -T site-frontend sh -c "npx openapi-typescript var/openapi.json -o assets/api/schema.d.ts"
	@echo "✓ TS types regenerated at site/assets/api/schema.d.ts"
	@echo "Don't forget to commit the updated schema.d.ts"

# Проверка что закоммиченные типы соответствуют спеке (используется в CI)
api-types-check:
	docker compose exec -T site-php-cli sh -c "php bin/console nelmio:apidoc:dump --format=json > var/openapi.json"
	docker compose exec -T site-frontend sh -c "npx openapi-typescript var/openapi.json -o /tmp/schema.check.d.ts && diff /tmp/schema.check.d.ts assets/api/schema.d.ts"

build: build-site

build-site:
#	docker --log-level=debug build --pull --file=site/docker/production/nginx/Dockerfile --tag=${REGISTRY}/site:${IMAGE_TAG} site
	docker --log-level=debug build --pull --file=site/docker/production/php-fpm/Dockerfile --tag=${REGISTRY}/site-php-fpm:${IMAGE_TAG} site
#	docker --log-level=debug build --pull --file=site/docker/production/php-cli/Dockerfile --tag=${REGISTRY}/site-php-cli:${IMAGE_TAG} site

try-build:
	REGISTRY=localhost IMAGE_TAG=0 make build

sched-up:
	docker compose up -d scheduler

sched-logs:
	docker compose logs -f scheduler

sched-test:
	docker compose exec scheduler supercronic -test /etc/crontabs/app.cron

sched-down:
	docker compose stop scheduler
