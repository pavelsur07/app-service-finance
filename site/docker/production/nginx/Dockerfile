# --- ЭТАП 1: Сборка фронтенда (Node) ---
FROM node:20-alpine AS frontend_builder
WORKDIR /app
COPY package.json package-lock.json ./
# Используем install, если ci падает из-за несовпадения версий
RUN npm install
COPY . .
RUN npm run build

# --- ЭТАП 2: Сборка PHP (для манифеста) ---
FROM php:8.3-cli-alpine AS builder
RUN apk add --no-cache libpq-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql opcache bcmath zip \
    && pecl install redis && docker-php-ext-enable redis

WORKDIR /app
COPY ./ ./
# Забираем билд из первого этапа
COPY --from=frontend_builder /app/public/build ./public/build

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/bin --filename=composer --quiet \
    && composer install --no-dev --optimize-autoloader --no-scripts

# --- ЭТАП 3: Финальный Nginx ---
FROM nginx:1.27-alpine3.21
RUN apk add --no-cache curl
COPY ./docker/common/nginx/conf.d /etc/nginx/conf.d
WORKDIR /app

# Копируем только то, что нужно для отдачи статики и работы PHP
# COPY ./public/index.php ./public/index.php
COPY --from=builder /app/public/build ./public/build

HEALTHCHECK --interval=5s --timeout=3s --start-period=1s CMD curl --fail http://127.0.0.1/health || exit 1
