# ozon-fetcher

Go-сервис: получение финансовых данных Ozon Seller API и публикация source
facts в Symfony по контракту [source-fact-envelope](../_contracts/source-fact-envelope/v1/).

Статус: **scaffold** — health endpoint и скелет; Ozon API client, сборка
envelope и publisher — следующие Stages (см. «Roadmap»).

Границы: сервис не пишет в БД Symfony, не решает об идемпотентности по
состоянию БД, не назначает категории ОПиУ, периоды и проводки
(`services/AGENTS.md` §1).

## Запуск (dev)

```bash
make ozon-fetcher-build
make ozon-fetcher-up
curl -s http://localhost:8180/health   # {"status":"ok"}
```

Переменные окружения: `LISTEN_ADDR` (по умолчанию `:8080`; в dev-compose
проброшен на 8180). Секреты Ozon API (`OZON_CLIENT_ID`, `OZON_API_KEY`) —
только env, появятся с Stage «Ozon API client».

## Тесты

```bash
make ozon-fetcher-test
```

## Roadmap (Stages, отдельные задачи)

1. Ozon API client: auth, rate limit, курсорная пагинация, retry на 429.
2. Сборка envelope v1 + `payload_hash` (SHA-256 канонической формы).
3. Publisher: `POST /internal/source-facts`, backoff/jitter, idempotent replay.
4. Dual-run против Symfony-пути, паритет сумм до копейки на архивном raw.
5. Cutover по кабинету — только после данных M1/M2 и решения владельца.
