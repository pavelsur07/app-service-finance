# --- ЭТАП 1: Сборка фронтенда (Node) ---
FROM node:20-alpine AS frontend_builder
WORKDIR /app
COPY package.json package-lock.json ./
# Используем install, если ci падает из-за несовпадения версий
RUN npm ci
COPY . .
RUN npm run build

# --- ЭТАП 2: Финальный Nginx ---
FROM nginx:1.27-alpine3.21
RUN apk add --no-cache curl tzdata \
    && cp /usr/share/zoneinfo/Europe/Moscow /etc/localtime \
    && echo "Europe/Moscow" > /etc/timezone
ENV TZ=Europe/Moscow
COPY ./docker/common/nginx/conf.d /etc/nginx/conf.d
WORKDIR /app

# Статика из исходников (favicon, robots.txt и т.д.)
COPY ./public ./public
# Vite-билд из первого этапа
COPY --from=frontend_builder /app/public/build ./public/build

HEALTHCHECK --interval=5s --timeout=3s --start-period=1s CMD curl --fail http://127.0.0.1/health || exit 1
