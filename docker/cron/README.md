# Cron scheduler

Файл `app.cron` описывает расписание задач, которые запускаются внутри контейнера `site-php-cli` через `docker exec`.

## Логи

* `docker compose logs -f scheduler` — потоковые логи контейнера планировщика (JSON-формат supercronic).
* `var/log/cron/app.log` — агрегированный вывод команд cron в рабочей директории проекта.

## Валидация

Проверить синтаксис cron-файла можно командой:

```bash
docker compose exec scheduler supercronic -test /etc/crontabs/app.cron
```

## Требования к задачам

Каждая задача должна быть идемпотентной и обеспечивать собственное блокирование (например, через Symfony Lock / Redis), чтобы исключить гонки при параллельных запусках.
