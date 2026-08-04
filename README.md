# App Service Finance

## Scheduler

* Запуск: `docker compose up -d scheduler`
* Логи: `docker compose logs -f scheduler`
* Проверка синтаксиса: `docker compose exec scheduler supercronic -test /etc/crontabs/app.cron`
* Ручной прогон любой команды (минуя cron): `docker compose exec -T site-php-cli php /app/bin/console app:cash:auto-rules -vvv`

## Управление пользователями

* Повысить пользователя до супер-админа: `docker compose exec -T site-php-cli php /app/bin/console security:promote user@example.com --super-admin`

## Тесты

* Подготовка test-БД: `make site-test-prepare`
* Обычный интеграционный прогон: `make site-test-integration`
* Полная пересборка test-БД с фикстурами: `make site-test-db-rebuild`

`site-test-integration` не пересоздаёт БД и не загружает application fixtures при каждом запуске. Обычные integration/functional тесты изолируются транзакцией через DAMA Doctrine Test Bundle; PostgreSQL-specific сценарии с реальными commit/lock/schema checks должны наследоваться от `PostgresResetTestCase`.

## Обновление ключей шифрования

`api_key` подключений маркетплейсов хранится в БД зашифрованным (sodium secretbox, версии ключей). Ключи задаются через GitHub Secrets: карта версий `APP_ENCRYPTION_KEYS_JSON` + активная версия `APP_ENCRYPTION_CURRENT_KEY_VERSION`.

* Ротация ключа (v1 → v2): строго по runbook'у `docs/maintenance/encryption-key-rotation.md` — секреты → деплой → `app:marketplace:rotate-connection-keys` (dry-run → `--execute` → контроль) → уборка старой версии.
* Никогда не удалять версию ключа из секрета, пока в БД есть строки на ней — данные станут нечитаемыми.
* Ключ — base64 от 32 байт: `openssl rand -base64 32`.

## Документация
- Точка входа: `docs/README.md`
