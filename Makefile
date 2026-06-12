DOCKER_COMPOSE ?= $(shell if docker compose version >/dev/null 2>&1; then printf 'docker compose'; elif docker-compose version >/dev/null 2>&1; then printf 'docker-compose'; else printf 'docker compose'; fi)

init: docker-down-clear site-clear docker-up site-init

docker-up:
	$(DOCKER_COMPOSE) up -d
down:
	$(DOCKER_COMPOSE) down --remove-orphans
docker-down-clear:
	$(DOCKER_COMPOSE) down -v --remove-orphans
docker-pull:
	$(DOCKER_COMPOSE) pull
docker-build:
	$(DOCKER_COMPOSE) build --pull

site-clear:
	docker run --rm -v ${PWD}/site:/app -w /app alpine sh -c 'rm -rf  .ready var/cache/* var/log/* var/test/*'

site-init: site-composer-install site-wait-db site-migrations site-fixtures site-frontend-install

site-composer-install:
	$(DOCKER_COMPOSE) run --rm site-php-cli composer install

site-frontend-install:
	$(DOCKER_COMPOSE) run --rm site-frontend npm run build

site-wait-db:
	until $(DOCKER_COMPOSE) exec -T site-postgres pg_isready --timeout=0 --dbname=app ; do sleep 1 ; done

site-migrations:
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction

site-fixtures:
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:fixtures:load --no-interaction

site-cs-check:
	$(DOCKER_COMPOSE) run --rm site-php-cli composer cs:check   # Проверка PHP-стиля

site-cs-fix:
	$(DOCKER_COMPOSE) run --rm site-php-cli composer cs:fix # Автопочинка PHP-стиля
    #$(DOCKER_COMPOSE) run --rm site-php-cli composer cs:phpcs   # Проверка через phpcs (PSR-12)
    #$(DOCKER_COMPOSE) run --rm site-php-cli composer cs:twig    # Линт Twig-шаблонов

# ===== TESTS =====

site-test-telegram:
	 $(DOCKER_COMPOSE) run --rm site-php-cli composer test -- --testsuite telegram

# Быстрые юнит-тесты (без БД)
site-test-unit:
	$(DOCKER_COMPOSE) run --rm site-php-cli composer test:unit

# Интеграционные (готовим среду + БД)
site-test-int: site-test-integration

site-test-integration: site-test-init
	$(DOCKER_COMPOSE) run --rm site-php-cli composer test:integration

# Все тесты
site-test: site-test-init
	$(DOCKER_COMPOSE) run --rm site-php-cli composer test

# ---- подготовка окружения тестов (smoke) ----
site-test-smoke-init: site-test-env site-test-wait-db site-test-db site-test-migrations

site-test-smoke: site-test-smoke-init
	$(DOCKER_COMPOSE) run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite=integration --filter SmokePersistenceTest

# Покрытие (нужен xdebug или pcov в CLI-образе)
site-test-cov: site-test-init
	$(DOCKER_COMPOSE) run --rm -e XDEBUG_MODE=coverage site-php-cli ./vendor/bin/phpunit -c phpunit.xml --coverage-html var/coverage --coverage-text

# ---- подготовка окружения тестов ----
site-test-init: site-composer-install site-test-env site-test-wait-db site-test-db site-test-migrations site-test-fixtures

site-test-env:
	# .env.test (если нет — скопируем из .env)
	$(DOCKER_COMPOSE) run --rm -T site-php-cli sh -lc 'test -f .env.test || cp .env .env.test'
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console cache:clear --env=test

site-test-wait-db:
	# Ждём Postgres
	until $(DOCKER_COMPOSE) exec -T site-postgres pg_isready --timeout=0 --dbname=app ; do sleep 1 ; done

site-test-db:
	$(DOCKER_COMPOSE) run --rm -T site-php-cli php bin/console doctrine:database:create --if-not-exists --env=test

site-test-migrations:
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test

site-test-fixtures:
	# Если для интеграционных нужны данные — загружаем минимальные фикстуры
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:fixtures:load --no-interaction --env=test

# Полный пересоздатeль test-БД (на случай поломанных фикстур)
site-test-db-rebuild: site-test-wait-db
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:database:drop --force --env=test
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:database:create --env=test
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test
	$(DOCKER_COMPOSE) run --rm site-php-cli php bin/console doctrine:fixtures:load --no-interaction --env=test

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
	$(DOCKER_COMPOSE) exec -T site-php-cli sh -c "php bin/console nelmio:apidoc:dump --format=json > var/openapi.json"
	@echo "✓ OpenAPI spec exported to site/var/openapi.json"

# Lint спеки через Spectral (опционально, нужен сконфигурированный контейнер с node)
api-doc-lint: api-doc-export
	$(DOCKER_COMPOSE) exec -T site-frontend sh -c "npx -y @stoplight/spectral-cli lint var/openapi.json"

# Основная команда разработчика: сгенерировать TS-типы из экспортированной спеки
api-types:
	$(DOCKER_COMPOSE) exec -T site-php-cli sh -c "php bin/console nelmio:apidoc:dump --format=json > var/openapi.json"
	$(DOCKER_COMPOSE) exec -T site-frontend sh -c "npx openapi-typescript var/openapi.json -o assets/api/schema.d.ts"
	@echo "✓ TS types regenerated at site/assets/api/schema.d.ts"
	@echo "Don't forget to commit the updated schema.d.ts"

# Проверка что закоммиченные типы соответствуют спеке (используется в CI)
api-types-check:
	$(DOCKER_COMPOSE) exec -T site-php-cli sh -c "php bin/console nelmio:apidoc:dump --format=json > var/openapi.json"
	$(DOCKER_COMPOSE) exec -T site-frontend sh -c "npx openapi-typescript var/openapi.json -o /tmp/schema.check.d.ts && diff /tmp/schema.check.d.ts assets/api/schema.d.ts"

build: build-site

build-site:
#	docker --log-level=debug build --pull --file=site/docker/production/nginx/Dockerfile --tag=${REGISTRY}/site:${IMAGE_TAG} site
	docker --log-level=debug build --pull --file=site/docker/production/php-fpm/Dockerfile --tag=${REGISTRY}/site-php-fpm:${IMAGE_TAG} site
#	docker --log-level=debug build --pull --file=site/docker/production/php-cli/Dockerfile --tag=${REGISTRY}/site-php-cli:${IMAGE_TAG} site

try-build:
	REGISTRY=localhost IMAGE_TAG=0 make build

sched-up:
	$(DOCKER_COMPOSE) up -d scheduler

sched-logs:
	$(DOCKER_COMPOSE) logs -f scheduler

sched-test:
	$(DOCKER_COMPOSE) exec scheduler supercronic -test /etc/crontabs/app.cron

sched-down:
	$(DOCKER_COMPOSE) stop scheduler
