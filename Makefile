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

site-init: site-composer-install site-wait-db site-migrations site-fixtures

site-composer-install:
	docker-compose run --rm site-php-cli composer install

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

# ===== TESTS =====

site-test-telegram:
	 docker-compose run --rm site-php-cli composer test -- --testsuite telegram

# Быстрые юнит-тесты (без БД)
site-test-unit:
	docker-compose run --rm site-php-cli composer test:unit

# Интеграционные (готовим среду + БД)
site-test-int: site-test-init
	docker-compose run --rm site-php-cli composer test:int

# Все тесты
site-test: site-test-init
	docker-compose run --rm site-php-cli composer test

# Покрытие (нужен xdebug или pcov в CLI-образе)
site-test-cov: site-test-init
	docker-compose run --rm -e XDEBUG_MODE=coverage site-php-cli ./vendor/bin/phpunit -c phpunit.xml.dist --coverage-html var/coverage --coverage-text

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
	- docker-compose run --rm site-php-cli php bin/console doctrine:database:create --env=test

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


build: build-site

build-site:
	docker --log-level=debug build --pull --file=site/docker/production/nginx/Dockerfile --tag=${REGISTRY}/site:${IMAGE_TAG} site
	docker --log-level=debug build --pull --file=site/docker/production/php-fpm/Dockerfile --tag=${REGISTRY}/site-php-fpm:${IMAGE_TAG} site
	docker --log-level=debug build --pull --file=site/docker/production/php-cli/Dockerfile --tag=${REGISTRY}/site-php-cli:${IMAGE_TAG} site

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
