# App Service Finance

## Scheduler

* Запуск: `docker compose up -d scheduler`
* Логи: `docker compose logs -f scheduler`
* Проверка синтаксиса: `docker compose exec scheduler supercronic -test /etc/crontabs/app.cron`
* Ручной прогон любой команды (минуя cron): `docker compose exec -T site-php-cli php /app/bin/console app:cash:auto-rules -vvv`

## Управление пользователями

* Повысить пользователя до супер-админа: `docker compose exec -T site-php-cli php /app/bin/console security:promote user@example.com --super-admin`
