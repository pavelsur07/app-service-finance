# syntax=docker/dockerfile:1
# multi-stage: сборка в golang, рантайм в alpine, non-root (services/go.md «Docker»).
# Контекст сборки — services/ (общее ядро shared/fetchkit подключено replace
# в go.mod); что попадает в контекст — docker/Dockerfile.dockerignore.
FROM golang:1.27-alpine AS build
WORKDIR /src/ozon-fetcher
ENV CGO_ENABLED=0 GOFLAGS=-p=2 GOWORK=off
COPY shared/fetchkit/go.mod shared/fetchkit/go.sum /src/shared/fetchkit/
COPY ozon-fetcher/go.mod ozon-fetcher/go.sum ./
RUN go mod download
COPY shared/fetchkit /src/shared/fetchkit
COPY ozon-fetcher/cmd ./cmd
COPY ozon-fetcher/internal ./internal
RUN go build -trimpath -ldflags="-s -w" -o /out/ozon-fetcher ./cmd/ozon-fetcher

FROM alpine:3.20
# wget (busybox) — для healthcheck /health; tzdata встроена в бинарник (time/tzdata).
RUN adduser -D -u 10001 app
COPY --from=build /out/ozon-fetcher /usr/local/bin/ozon-fetcher
USER app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1:8080/health >/dev/null || exit 1
ENTRYPOINT ["ozon-fetcher"]
