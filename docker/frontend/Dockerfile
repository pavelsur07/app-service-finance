# syntax=docker/dockerfile:1
ARG APP
FROM node:24.18.1-bookworm-slim AS build
ARG APP
WORKDIR /workspace
RUN case "$APP" in account|admin) ;; *) echo 'APP must be account or admin' >&2; exit 2 ;; esac
COPY apps/${APP}/package.json apps/${APP}/package-lock.json ./apps/${APP}/
COPY site/packages/api/package.json ./site/packages/api/
COPY site/packages/ui/package.json ./site/packages/ui/
RUN cd "apps/$APP" && npm ci --no-audit --no-fund
COPY apps/${APP}/ ./apps/${APP}/
COPY site/packages/ ./site/packages/
COPY site/assets/styles/kit/ ./site/assets/styles/kit/
RUN cd "apps/$APP" && npm run build

FROM nginx:1.27-alpine3.21
ARG APP
COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /workspace/apps/${APP}/dist/ /usr/share/nginx/html/
EXPOSE 80
HEALTHCHECK --interval=5s --timeout=3s --start-period=5s --retries=5 \
  CMD wget -q -O /dev/null http://127.0.0.1/health || exit 1
