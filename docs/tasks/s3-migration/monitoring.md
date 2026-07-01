# S3 — мониторинг живости хранилища

Наблюдаемость объектного хранилища (`ObjectStorageInterface`). Принцип: **liveness ≠
readiness** — сбой S3 НЕ должен валить container-healthcheck php-fpm (`/health`), иначе
traefik выкинет ноду из ротации (amplification). Поэтому мониторинг — отдельно.

## #1 — Синтетический health-check (проактивно)

Команда `app:storage:healthcheck` (`src/Shared/Command/StorageHealthCheckCommand.php`):
`write` → `read` → verify → `delete` крошечного probe-объекта (`_healthcheck/probe-*.txt`)
с замером латентности.

- Крон (supercronic, `docker/cron/app.cron`): `*/5 * * * *`.
- Сбой (недоступность S3 / протухшие ключи / read-back mismatch) → `logger->error` →
  **GlitchTip алерт** + exit 1. Успех печатает `OK (N ms)` в docker logs (heartbeat).
- Ловит проблему до того, как её увидит пользователь.

## #2 — Инструментирующий декоратор

`LoggingObjectStorage` (`src/Shared/Service/Storage/LoggingObjectStorage.php`) декорирует
`ObjectStorageInterface` через DI (`decorates:` в `services.yaml`). Прозрачен для всего
прикладного кода:

- Таймит каждую операцию (write/read/readStream/exists/delete).
- `warning` на медленных (`> app.object_storage_slow_ms`, дефолт 1000 мс) — только лог.
- `error` на сбоях со структурным контекстом (operation, path, duration_ms, exception) →
  GlitchTip. Реросит исходное исключение.
- Даёт латентность и error-rate на **реальных** боевых путях (не только синтетика).

## Уровни (по правилам логирования проекта)

- `error` — сбой операции хранилища = инцидент (будим человека) → GlitchTip.
  AWS SDK уже ретраит внутри, так что исключение = неустранимый сбой, не transient.
- `warning` — медленная операция: ожидаемо, обрабатывается само, в GlitchTip не идёт.

## Что НЕ делали (осознанно)

- S3 в container `/health` — amplification.
- Circuit breaker / retry-слой — timeweb managed, SDK ретраит; добавлять только при
  реальных transient-сбоях.
- INFO на каждую операцию — шум.

## Возможные follow-ups

- Readiness-эндпоинт `/health/storage` (под `HEALTH_CHECK_TOKEN`) для внешнего аптайм-монитора.
- Lifecycle-правило на префикс `_healthcheck/` в бакете (на случай, если delete probe не
  прошёл при частичном сбое — чтобы не копились).
