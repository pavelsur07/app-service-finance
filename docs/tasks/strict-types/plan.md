# Миграция на declare(strict_types=1)

## Проблема

`CLAUDE.md` требует `declare(strict_types=1)` в каждом PHP-файле. В 309 файлах из
2323 его нет, и `make site-cs-check` этого не ловит: правило `declare_strict_types`
выключено в `site/.php-cs-fixer.php`. Требование держится только на внимательности
ревьюера.

## Почему это не одна строка в конфиге

`declare(strict_types=1)` в файле X влияет на вызовы, которые X делает наружу
(аргументы больше не приводятся), и на `return` внутри X. На вызовы функций самого
X извне не влияет — там режим задаёт файл-вызыватель.

Реальный сценарий поломки здесь: PostgreSQL через PDO отдаёт `numeric`/`bigint`
строками. `$row['amount']` в параметр `float $amount` сейчас приводится молча,
после `declare` бросит `TypeError`. Поэтому финансовые модули — главная зона.

## Распределение долга и покрытие

| Модуль | Без declare | Всего | Тестов |
|---|---|---|---|
| Deals | 31 | 42 | 3 |
| Analytics | 24 | 26 | 10 |
| Notification | 8 | 8 | 0 |
| Cash | 98 | 200 | 87 |
| Company | 25 | 104 | 58 |
| Marketplace | 32 | 420 | 135 |
| Finance | 10 | 93 | 47 |
| Shared | 9 | 60 | 26 |
| Telegram | 9 | 19 | 7 |
| Billing | 5 | 23 | 8 |
| Balance | 4 | 34 | 6 |
| Admin | 4 | 10 | 10 |
| Twig | 3 | 4 | 1 |
| Report | 3 | 3 | 5 |
| Exception | 3 | 3 | 0 |
| DataFixtures | 8 | 8 | 0 |
| Catalog | 2 | 49 | 13 |
| MarketplaceAnalytics | 1 | 81 | 18 |
| Util | 1 | 1 | 0 |
| Kernel.php | 1 | 1 | 0 |
| tests/ | 28 | 606 | — |

Риск не совпадает с объёмом. `Cash` — 98 файлов при 87 тестах, то есть самый
безопасный из крупных. Опасны `Deals` (74% модуля, 3 теста), `Analytics` (92%,
10 тестов) и `Notification` (100%, тестов нет).

## Процедура на каждом батче

```bash
docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix src/<Module> \
  --rules=declare_strict_types --allow-risky=yes --using-cache=no
docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix src/<Module> \
  --using-cache=no
make site-test
```

Первая команда добавляет только `declare`, вторая нормализует форматирование по
`@Symfony`. Конфиг до Stage 6 не трогаем: выключенное правило существующие
объявления не снимает, только не добавляет новые.

## Stage 1: тривиальные файлы без бизнес-логики
Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: db63e91ceae0f5adaf61e5abb743ba56d2ed20ee
Definition of Done:
- 13 файлов получили `declare(strict_types=1)`
- `make site-test` не хуже baseline
- посторонних изменений в диффе нет
Work items:
- 1.1 — `src/Exception` (3)
- 1.2 — `src/Util` (1), `src/Kernel.php` (1)
- 1.3 — `src/DataFixtures` (8)
Stage checks:
- `make site-test`, сравнение с baseline
Reviewer focus:
- нет ли правок помимо вставки declare и нормализации пустых строк

## Stage 2: хорошо покрытые модули
Risk: HIGH-LOCAL
owner_gate: no
Work items: Marketplace (32), Company (25), Finance (10), Catalog (2), MarketplaceAnalytics (1)
Reviewer focus: денежные пути в Finance

## Stage 3: Cash
Risk: HIGH-LOCAL
owner_gate: no
Work items: по поддиректориям Entity / Repository / Application / Controller, прогон тестов после каждого
Reviewer focus: строки из PDO в числовые параметры

## Stage 4: мелкие модули
Risk: HIGH-LOCAL
owner_gate: no
Work items: Shared (9) первым и с полным прогоном — от него зависят остальные; далее Telegram (9), Billing (5), Balance (4), Admin (4), Report (3), Twig (3)

## Stage 5: модули с тонкой сеткой тестов
Risk: HIGH-LOCAL
owner_gate: yes
Work items: Deals (31), Analytics (24), Notification (8)
Порядок: сначала регрессионные тесты на денежные и парсящие пути, потом declare.
Ручной smoke обязателен.

## Stage 6: закрыть контур
Risk: HIGH-LOCAL
owner_gate: yes
Work items:
- 28 файлов в `tests/`
- флип `'declare_strict_types' => true` в `site/.php-cs-fixer.php`
- убрать из `CLAUDE.md` заметку «make site-cs-check этого не проверяет»
Definition of Done: `make site-cs-check` зелёный по этому правилу

## Операционные оговорки

- Тесты требуют поднятого `site-redis`: без него ~194 ложных падения на локах в
  Cash/Marketplace, легко принять за регресс от `declare`.
- Baseline снять до первого батча — суд по нему, а не по «зелёно/красно».
- Мерж этой задачи **запустит прод-деплой**: `site/src/**` входит в path-фильтр
  `deploy.yml`. Мержить по одному Stage, дожидаясь завершения деплоя.
