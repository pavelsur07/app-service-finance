# cs:check — красный гейт на PR #2720 (разобрано)

Статус: причина подтверждена 2026-10-19 и устранена в PR #2720 (этап 1,
`docs/tasks/admin-users-cabinets/stages/stage-1.md`). Починка гейта (#2726)
не требуется, PR #2726 закрыт.

## Симптом

Джоб «🎨 PHP code style» в `.github/workflows/deploy.yml` — обязательная
проверка в защите ветки — красный (exit 8) на PR #2720. Локальная проверка
давала `Found 0 … files that can be fixed`.

## Причина — два конфига php-cs-fixer

В `site/` под git два конфига: `.php-cs-fixer.php` и `.php-cs-fixer.dist.php`.
Без `--config` php-cs-fixer берёт `.php-cs-fixer.php`, по нему же работает CI
(ключ кэша в `deploy.yml` хэширует его). Конфиги по-разному выравнивают
phpdoc: `.dist` выравнивает колонки `@param` пробелами, основной это
выравнивание убирает. Локальная проверка шла с `--config=.php-cs-fixer.dist.php`
и не видела нарушения.

Доказательства:

- Лог джоба (run 39239276129): `1) src/Company/Repository/UserRepository.php`,
  `Found 1 of 3159 files that can be fixed`. Проверено 3159 файлов, найден
  один файл ветки, `vendor` в выдаче нет.
- Тот же файл из ветки через stdin: с `--config=.php-cs-fixer.php` — diff по
  выравниванию трёх строк `@param` в `getRegisteredUsers()`; с
  `--config=.php-cs-fixer.dist.php` — 0 нарушений.

## Отвергнутые гипотезы

- «`site/.php-cs-fixer.php` не существует, ключ кэша константный» — файл в
  репозитории, ключ меняется вместе с ним.
- «Гейт сканирует `site/vendor`» — в выдаче CI нарушений из vendor нет.

## Как проверять локально

```bash
docker exec -w /app <php-контейнер> php vendor/bin/php-cs-fixer fix \
  --config=.php-cs-fixer.php --dry-run --diff --using-cache=no <пути>
```

## Остатки

Ветки `chore/cs-check-cleanup`, `debug/cs-annotated`, `debug/cs-annotated-2`,
`backup/cs-cleanup-rebased` больше не нужны. Удаление remote-веток — по
команде владельца.
