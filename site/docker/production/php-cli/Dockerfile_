FROM php:8.3-fpm-alpine AS builder

RUN apk add --no-cache libpq-dev libzip-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure opcache --disable-opcache-jit \
    && docker-php-ext-install pdo_pgsql opcache bcmath zip

# ext-redis для стадии builder (чтобы composer не ругался на ext-redis)
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

RUN apk add --no-cache unzip
RUN apk add --no-cache git openssh-client

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_PROCESS_TIMEOUT=1200 \
    COMPOSER_RETRY=5 \
    COMPOSER_DISABLE_NETWORK=0

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/bin --filename=composer --quiet

WORKDIR /app

COPY ./composer.json ./composer.lock ./

RUN composer install --no-dev --prefer-dist --no-progress --no-scripts --optimize-autoloader \
    && rm -rf /root/.composer/cache


### CLI (runtime) ###

FROM php:8.3-cli-alpine

# su-exec нужен, чтобы стартовать контейнер как root (инициализация volume),
# а затем "уронить" права до пользователя app для запуска воркера
RUN apk add --no-cache libpq-dev libzip-dev bash coreutils su-exec \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure opcache --disable-opcache-jit \
    && docker-php-ext-install pdo_pgsql opcache bcmath zip

# ext-redis для рантайма (CLI-воркер)
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

RUN mv $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

COPY ./docker/common/php/conf.d /usr/local/etc/php/conf.d
COPY ./docker/production/php/conf.d /usr/local/etc/php/conf.d

COPY ./docker/common/wait-for-it.sh /usr/local/bin/wait-for-it
RUN chmod 555 /usr/local/bin/wait-for-it

# Пользователь app (uid=1000) добавляем в группу www-data (group-write на 0775)
RUN adduser -u 1000 -G www-data -s /bin/sh -D app

WORKDIR /app

COPY --from=builder /app ./
COPY ./ ./

# Права на var/cache и var/log — под app (воркер работает под app)
RUN mkdir -p var/cache var/log \
 && chown -R app:www-data var \
 && chmod -R 775 var

# ENTRYPOINT выполняется ПОСЛЕ mount volume (/app/var/storage),
# поэтому именно здесь надо mkdir/chown/chmod для storage.
COPY ./docker/production/entrypoint-cli.sh /usr/local/bin/entrypoint-cli
RUN chmod 555 /usr/local/bin/entrypoint-cli

ENTRYPOINT ["/usr/local/bin/entrypoint-cli"]
