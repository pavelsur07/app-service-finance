# cs:check — починка гейта (PR #2726)

Статус: работа остановлена по команде владельца 2026-10-19, состояние зафиксировано.
Ветка: `chore/cs-check-cleanup` → PR #2726. Базовая задача, которую разблокирует эта починка: PR #2720 (/admin/users — кабинеты и фильтры по владельцам).

## Симптом

Джоб «🎨 PHP code style» в `.github/workflows/deploy.yml` — обязательная проверка
в защите ветки — красный (exit 8) на дереве, которое локально даёт
`Found 0 of 3439 files that can be fixed`. Красный гейт блокирует merge обоих PR.

## Причина 1 — отравленный кэш (ИСПРАВЛЕНО, одобрено владельцем)

Ключ кэша php-cs-fixer хэшировал несуществующий `site/.php-cs-fixer.php` и не
включал `composer.lock` → ключ константный, заражённый кэш реплеился
(2–9 с вместо ~74 с холодного прогона — признак replay по таймингам шагов джоба).

Фикс: ключ = `hashFiles('site/.php-cs-fixer.dist.php', 'site/composer.lock')`
(коммит `e03ce654c`, ветка #2726).

## Причина 2 — гейт сканирует site/vendor (ГИПОТЕЗА, доказательства ниже)

`.php-cs-fixer.dist.php`: `->in(__DIR__)->exclude('var')` — `vendor` НЕ исключён.
В CI после `composer install` `site/vendor` существует → фиксер проверяет и
зависимости (у ряда пакетов есть нарушения @Symfony) → exit 8 независимо от
чистоты трекнутого кода. Комментарий в `site/.php-cs-fixer.strict-types.php`
подтверждает историю: «общий cs:check хронически красный (506 файлов из 2342
по правилам @Symfony)». strict-types-гейт зелёный, т.к. его конфиг ограничен
`src/` + `tests/` и использует `ignoreVCSIgnored(true)`.

Доказательства:

- Экспорт дерева без vendor (рецепт ниже): 0 нарушений на PHP 8.4.14 и 8.4.23,
  phar 3.95.1 и composer-dist 3.95.1 (версия по `site/composer.lock`).
- В фиксере 3.95.1 нет ветвлений `PHP_VERSION_ID` внутри линейки 8.4.x;
  polyfill-mbstring в комплекте → отсутствие mbstring в образе не влияет.
- node_modules не содержит PHP-файлов → единственный непроверенный источник
  в CI — vendor.
- Скан только по `site/vendor` локально запущен, но остановлен по команде
  владельца до результата (первый заход уперся в memory_limit 128M на обходе
  vendor/bin-симлинков; повтор с `-d memory_limit=2G` остановлен).

### Плановый фикс (НЕ применён)

Одна строка в `site/.php-cs-fixer.dist.php`: `->exclude('vendor')`.
Смена конфига меняет хэш в ключе кэша cs-fixer → холодный честный прогон.

## Локальная верификация (Docker недоступен на машине)

Артефакты в /tmp (пересоздаваемы): `/tmp/php84dir/php` (static 8.4.14),
`/tmp/php8423/php` (static 8.4.23), `/tmp/php-cs-fixer.phar` (3.95.1).

```bash
# экспорт дерева БЕЗ искажений (git archive не применяет autocrlf)
git -c core.autocrlf=false archive <REF> | tar -x -C /tmp/scan
cd /tmp/scan/site
PHP_CS_FIXER_IGNORE_ENV=1 /tmp/php84dir/php /tmp/php-cs-fixer.phar fix \
  --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php
```

Проверка только vendor: конфиг с `->in('<repo>/site/vendor')`, запуск с
`-d memory_limit=2G`.

## Состояние веток (2026-10-19)

- `chore/cs-check-cleanup` (PR #2726): `ac033a1a7` (style: cs:fix по 161 файлу)
  + `e03ce654c` (ключ кэша) + `e8dacc050` (merge origin/master 8bb689d8b,
  дельта #2724/#2728 — 0 новых нарушений). Запушено.
- `feat/admin-users-cabinets` (PR #2720): `e26afd832`+`19f5e6c79`+`e7fad28d5`,
  ждёт зелёного cs:check после мержа #2726 (подтянуть master мержем, не ребейзом).
- `debug/cs-annotated-2` (`4dc48a000`, запушена): диагностический шаг cs печатает
  нарушителей `::error::`-аннотациями (читаются через публичный API без auth).
  Не нужна, если причина 2 подтвердится локально. Удаление remote-веток — по
  явной команде владельца.
- `backup/cs-cleanup-rebased` — локальный бэкап промежуточного ребейза, можно удалить.

## Следующие шаги (при возобновлении)

1. Досканировать `site/vendor` (memory_limit=2G) → подтвердить нарушения.
2. Применить `->exclude('vendor')` в `site/.php-cs-fixer.dist.php`, коммит, push.
3. Дождаться зелёного CI #2726 → handoff-вопрос merge+deploy (в PR есть правка
   workflow — ключ кэша).
4. Подтянуть master в `feat/admin-users-cabinets` (merge), CI #2720 → handoff.
5. Попросить владельца удалить `debug/cs-annotated`, `debug/cs-annotated-2`.
