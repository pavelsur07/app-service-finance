FROM php:8.3-cli-alpine

ENV XDEBUG_VERSION 3.4.1

RUN apk add --no-cache libpq-dev bash coreutils git linux-headers libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev tzdata \
    && cp /usr/share/zoneinfo/Europe/Moscow /etc/localtime \
    && echo "Europe/Moscow" > /etc/timezone \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && git clone --branch $XDEBUG_VERSION --depth 1 https://github.com/xdebug/xdebug.git /usr/src/php/ext/xdebug \
    && docker-php-ext-configure xdebug --enable-xdebug-dev \
    && docker-php-ext-install pdo_pgsql xdebug bcmath zip gd \
    && apk del git linux-headers

ENV TZ=Europe/Moscow

# ✅ Устанавливаем phpredis через PECL, чтобы обеспечить поддержку транспорта Redis
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && php -r 'echo "Redis ext version: ", phpversion("redis"), PHP_EOL;' \
    && apk del --no-cache $PHPIZE_DEPS

RUN mv $PHP_INI_DIR/php.ini-development $PHP_INI_DIR/php.ini

COPY ./common/php/conf.d /usr/local/etc/php/conf.d
COPY ./development/php/conf.d /usr/local/etc/php/conf.d

# opcache.so на Alpine/musl не загружается из-за неэкспортированного символа zend_jit_status.
# В dev-среде opcache не нужен — отключаем попытку динамической загрузки.
RUN sed -i 's/^zend_extension=opcache/;zend_extension=opcache/' \
    /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

COPY ./common/wait-for-it.sh /usr/local/bin/wait-for-it
RUN chmod 555 /usr/local/bin/wait-for-it

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/bin --filename=composer --version=2.8.5 --quiet

RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app

WORKDIR /app

USER app
