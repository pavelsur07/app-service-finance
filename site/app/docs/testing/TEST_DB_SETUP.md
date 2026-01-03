# Настройка тестовой БД для integration/functional

## Пример `DATABASE_URL` для test

```dotenv
# .env.test.local
DATABASE_URL="postgresql://app_user:app_pass@127.0.0.1:5432/app_service_finance_test?serverVersion=16&charset=utf8"
```

> Используйте отдельную БД (например, `app_service_finance_test`).
> В test-окружении Doctrine читает только `DATABASE_URL` из `config/packages/test/doctrine.yaml`.

## Поднять БД и применить миграции/схему

### Вариант 1: миграции (рекомендуется)

```bash
# создать БД
createdb app_service_finance_test

# применить миграции
php bin/console doctrine:migrations:migrate --env=test
```

### Вариант 2: схема (если миграций нет)

```bash
# создать БД
createdb app_service_finance_test

# обновить схему
php bin/console doctrine:schema:update --force --env=test
```

## Быстрый локальный workflow

1. Создайте `.env.test.local` с `DATABASE_URL` на отдельную БД.
2. Поднимите БД и примените миграции:

   ```bash
   createdb app_service_finance_test
   php bin/console doctrine:migrations:migrate --env=test
   ```

3. Запускайте нужные тесты:

   ```bash
   php bin/phpunit --testsuite integration
   php bin/phpunit --testsuite functional
   ```
