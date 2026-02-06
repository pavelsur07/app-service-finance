# Скрипт-установщик расширений (проверенный стандарт сообщества)
FROM mlocati/php-extension-installer:latest AS extension_installer

### 1. Builder Stage ###
FROM php:8.3-cli-alpine AS builder

# Копируем установщик из первого этапа
COPY --from=extension_installer /usr/bin/install-php-extensions /usr/local/bin/

# Устанавливаем системные утилиты и PHP расширения одним слоем
RUN apk add --no-cache git unzip \
    && install-php-extensions pdo_pgsql zip bcmath opcache redis

# Установка Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Сначала только файлы зависимостей для кэширования слоя
COPY composer.json composer.lock ./

# Установка зависимостей без скриптов (оптимизация скорости)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress \
    && rm -rf /root/.composer/cache

### 2. CLI (runtime) ###
FROM php:8.3-cli-alpine

COPY --from=extension_installer /usr/bin/install-php-extensions /usr/local/bin/

# Устанавливаем только рантайм-зависимости и те же расширения
RUN apk add --no-cache bash coreutils su-exec \
    && install-php-extensions pdo_pgsql zip bcmath opcache redis

# Настройка PHP
RUN mv $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini
COPY ./docker/common/php/conf.d /usr/local/etc/php/conf.d
COPY ./docker/production/php/conf.d /usr/local/etc/php/conf.d

# Скрипты ожидания и входа
COPY ./docker/common/wait-for-it.sh /usr/local/bin/wait-for-it
COPY ./docker/production/entrypoint-cli.sh /usr/local/bin/entrypoint-cli
RUN chmod +x /usr/local/bin/wait-for-it /usr/local/bin/entrypoint-cli

# Создание пользователя
RUN adduser -u 1000 -G www-data -s /bin/sh -D app

WORKDIR /app

# Копируем vendor из builder-а
COPY --from=builder /app/vendor ./vendor

# Копируем остальные файлы приложения
COPY . .

# Финальная оптимизация автозагрузки Composer (уже с кодом приложения)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev && rm /usr/bin/composer

# Права доступа
RUN mkdir -p var/cache var/log \
    && chown -R app:www-data var \
    && chmod -R 775 var

ENTRYPOINT ["/usr/local/bin/entrypoint-cli"]
