# Cron scheduler

Файл `app.cron` описывает расписание команд Symfony, которые выполняются непосредственно внутри контейнера планировщика.

## Логи

* `docker compose logs -f scheduler` — потоковые логи контейнера планировщика (JSON-формат supercronic).
* Вывод cron-команд остаётся в stdout/stderr; Docker ограничивает каждый лог `10m × 3` через `x-logging` в `docker-compose.prod.yml`.
* Ручные maintenance-логи и backup-файлы размещаются по правилам из [`docs/maintenance/production-logging.md`](../../docs/maintenance/production-logging.md), не в домашнем каталоге пользователя.

## Валидация

Проверить синтаксис cron-файла можно командой:

```bash
docker compose exec scheduler supercronic -test /etc/crontabs/app.cron
```

## Требования к задачам

Каждая задача должна быть идемпотентной и обеспечивать собственное блокирование (например, через Symfony Lock / Redis), чтобы исключить гонки при параллельных запусках.
