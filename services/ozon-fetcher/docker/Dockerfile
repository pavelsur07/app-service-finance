# syntax=docker/dockerfile:1
# multi-stage: сборка в golang, рантайм в alpine, non-root.
# Stdlib-only намеренно: go.mod без внешних зависимостей, go.sum не нужен.
FROM golang:1.23-alpine AS build
WORKDIR /src
COPY go.mod ./
COPY cmd ./cmd
RUN go build -trimpath -ldflags="-s -w" -o /out/ozon-fetcher ./cmd/ozon-fetcher

FROM alpine:3.20
RUN adduser -D -u 10001 app
COPY --from=build /out/ozon-fetcher /usr/local/bin/ozon-fetcher
USER app
EXPOSE 8080
ENTRYPOINT ["ozon-fetcher"]
