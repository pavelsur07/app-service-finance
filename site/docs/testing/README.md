# Testing

Команды для запуска тестов по слоям:

- Unit:

  ```bash
  php bin/phpunit --testsuite unit
  ```

- Integration:

  ```bash
  php bin/phpunit --testsuite integration
  ```

- Functional:

  ```bash
  php bin/phpunit --testsuite functional
  ```

## Composer scripts

Примеры запуска через composer:

```bash
composer test
composer test:unit
composer test:integration
composer test:functional
```
