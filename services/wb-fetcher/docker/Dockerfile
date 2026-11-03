# syntax=docker/dockerfile:1
# multi-stage: сборка в golang, рантайм в alpine, non-root (services/go.md «Docker»).
# Контекст сборки — services/ (общее ядро shared/fetchkit подключено replace
# в go.mod); что попадает в контекст — docker/Dockerfile.dockerignore.
FROM golang:1.27-alpine AS build
WORKDIR /src/wb-fetcher
ENV CGO_ENABLED=0 GOFLAGS=-p=2 GOWORK=off
COPY shared/fetchkit/go.mod shared/fetchkit/go.sum /src/shared/fetchkit/
COPY wb-fetcher/go.mod wb-fetcher/go.sum ./
RUN go mod download
COPY shared/fetchkit /src/shared/fetchkit
COPY wb-fetcher/cmd ./cmd
COPY wb-fetcher/internal ./internal
RUN go build -trimpath -ldflags="-s -w" -o /out/wb-fetcher ./cmd/wb-fetcher

FROM alpine:3.20
# wget (busybox) — для healthcheck /health; tzdata встроена в бинарник (time/tzdata).
RUN adduser -D -u 10001 app
COPY --from=build /out/wb-fetcher /usr/local/bin/wb-fetcher
USER app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1:8080/health >/dev/null || exit 1
ENTRYPOINT ["wb-fetcher"]
