# Task 7 — Финальная проверка и регрессия

Дата проверки: 2026-05-04 (UTC)

## Что выполнено

### 1) Проверка синтаксиса PHP-файлов
- `site/src/Marketplace/Controller/MarketplaceController.php` — OK
- `site/src/Marketplace/Controller/CostsJsonExportController.php` — OK
- `site/src/Marketplace/Repository/MarketplaceCostRepository.php` — OK

### 2) Попытка запуска интеграционных тестов Marketplace
- `php bin/phpunit tests/Integration/Marketplace` — не выполнено: отсутствует `vendor/symfony/phpunit-bridge/bin/simple-phpunit.php`
- `vendor/bin/phpunit tests/Integration/Marketplace` — не выполнено: отсутствует `vendor/bin/phpunit`

Вывод: в текущем окружении не установлены dev-зависимости (`vendor`) для запуска PHPUnit.

### 3) Проверка phpstan/php-cs-fixer
В проекте есть скрипты `cs:check`/`cs:fix`, но инструменты также недоступны без установленных зависимостей в `vendor`.

### 4) Ручная браузерная проверка из задания
Ручная проверка в браузере в этом CI-окружении не выполнена (нет интерактивной браузерной сессии).

Рекомендуется прогон в рабочем/stage окружении по чек-листу:
1. `/marketplace/costs` — дефолты Ozon + текущий месяц.
2. Фильтр category + Apply — остальные фильтры сохраняются.
3. Фильтр marketplace + Apply — остальные фильтры сохраняются.
4. Фильтр date_from/date_to + Apply — включительные границы.
5. Tabs (Все / С листингом / Общие) — меняется только `mapped`, прочие фильтры сохраняются.
6. Пагинация (если >50) — фильтры сохраняются.
7. `Выгрузить в JSON` — скачивание и валидность (`filters`, `count`, `costs`).
8. Прямой URL `/marketplace/costs/export.json?...` — `200`, JSON, `Content-Disposition: attachment`.
9. Невалидные query-параметры — без `500`, fallback на дефолты.
10. Регрессия страниц: `/marketplace/sales`, `/marketplace/returns`, `/marketplace/cost-categories`, `/marketplace`.

## Изменённые файлы
- Добавлен отчёт: `site/TASK7_FINAL_CHECK_REPORT.md`.
