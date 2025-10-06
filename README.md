# App Service Finance

## Scheduler

* Запуск: `docker compose up -d socket-proxy scheduler`
* Логи: `docker compose logs -f scheduler`
* Проверка синтаксиса: `docker compose exec scheduler supercronic -test /etc/crontabs/app.cron`
* Ручной прогон любой команды (минуя cron): `docker compose exec -T site-php-cli php /app/bin/console app:cash:auto-rules -vvv`
