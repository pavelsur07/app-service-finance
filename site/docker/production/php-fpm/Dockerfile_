FROM php:8.3-cli-alpine AS builder

# После ("щадящий" вариант):
ENV MAKEFLAGS="-j1"

RUN apk add --no-cache libpq-dev libzip-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure opcache --disable-opcache-jit \
    && docker-php-ext-install pdo_pgsql opcache bcmath zip

# Install ext-redis for the build stage so that Composer sees the extension
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV COMPOSER_PROCESS_TIMEOUT=1200 \
    COMPOSER_RETRY=5 \
    COMPOSER_DISABLE_NETWORK=0

RUN apk add --no-cache git openssh-client

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/bin --filename=composer --quiet

WORKDIR /app

COPY ./composer.json ./composer.lock ./


RUN composer install --no-dev --prefer-dist --no-progress --no-scripts --optimize-autoloader \
    && rm -rf /root/.composer/cache


### FPM ###
FROM php:8.3-fpm-alpine

RUN apk add --no-cache libpq-dev libzip-dev fcgi \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure opcache --disable-opcache-jit \
    && docker-php-ext-install pdo_pgsql opcache bcmath zip

# ⬇️ Добавляем ext-redis для рантайма PHP-FPM
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

RUN mv $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

COPY ./docker/common/php/conf.d /usr/local/etc/php/conf.d
COPY ./docker/common/php/php-fpm.d /usr/local/etc/php-fpm.d
COPY ./docker/production/php/conf.d /usr/local/etc/php/conf.d

WORKDIR /app

COPY --from=builder /app ./
COPY ./ ./

ENV APP_ENV prod

RUN php /app/bin/console importmap:install
RUN php /app/bin/console asset-map:compile

RUN chown www-data:www-data ./var -R

COPY ./docker/production/entrypoint-init-storage.sh /usr/local/bin/entrypoint-init-storage
RUN chmod 555 /usr/local/bin/entrypoint-init-storage

ENTRYPOINT ["/usr/local/bin/entrypoint-init-storage"]
CMD ["php-fpm"]


HEALTHCHECK --interval=5s --timeout=3s --start-period=1s \
    CMD REDIRECT_STATUS=true SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
