# Этап 1. Справочник категорий и назначение листингам

Модуль `Marketplace` · риск HIGH-LOCAL (миграция схемы) · PR с миграцией выпускается отдельным релизом.

## Задача

Завести справочник категорий компании и дать листингу не больше одной категории.
Назначение массовое: по списку id или по фильтру реестра.

## Результат

- Таблица `marketplace_listing_categories` (`id`, `company_id`, `name`, `slug`, `created_at`), unique `(company_id, slug)`.
- В `marketplace_listings` колонки `category_id` (FK `ON DELETE RESTRICT`, индекс) и `category_source` (`manual` | `rule`).
- Entity `MarketplaceListingCategory`, enum `ListingCategorySource`, связь `MarketplaceListing::$category`.
- `MarketplaceListingCategoryRepository`; DBAL-запрос `ListingCategoryAssignmentQuery` (массовый `UPDATE` и чтение в рамках компании).
- Actions: `CreateListingCategoryAction`, `RenameListingCategoryAction`, `DeleteListingCategoryAction` (отказ, если категория используется), `AssignListingCategoryAction` (`categoryId = null` снимает категорию).
- `ListingCategoryFacadeInterface` + `ListingCategoryFacade`: `list()`, `categoriesForListings()`.
- Builder `MarketplaceListingCategoryBuilder`; раздел ARCHITECTURE «Marketplace: категории листингов».

Не входит в этап: контроллеры и UI (этап 2), правила (этап 4). `category_source = rule` пока никто не пишет.

## Что считаем выполненным

Тесты и код в одной ветке, гейты по каталогам модуля зелёные, миграция прошла
`up` / `down` / `up` на чистой базе, ARCHITECTURE обновлён.

## Чек-лист приёмки

- [x] Слаг: «Платья», « платья » и «ПЛАТЬЯ» — одна категория; повторное создание даёт конфликт.
- [x] Переименование в имя другой категории — конфликт; смена регистра у той же — не конфликт.
- [x] Повторное массовое назначение той же категории: `changed = 0`, число строк и состояние не меняются.
- [x] Чужую категорию назначить нельзя (`NotFound`); листинги чужой компании не меняются.
- [x] Фильтр шире лимита — отказ, никаких изменений.
- [x] `categoryId = null` снимает категорию и `category_source`.
- [x] Удаление используемой категории — отказ с числом листингов, данные не меняются; неиспользуемая удаляется.
- [x] `categoriesForListings()` возвращает только листинги своей компании.
- [x] `SchemaInSyncWithMigrationsTest` зелёный; stan, cs, strict-types по `src/Marketplace` и `tests/` модуля.

## Итог

25.11.2026. Все пункты чек-листа закрыты тестами `tests/Integration/Marketplace/Application/ListingCategoryActionsTest.php` (11 тестов, 29 проверок).

- Миграция `Version20261125120000` прошла up → down → up на чистой базе; `doctrine:schema:update --dump-sql` пуст (кроме тестовой `test_money_holder`); `SchemaInSyncWithMigrationsTest` зелёный.
- Регресс модуля: Unit 1058, Integration 595, Functional 281 — OK.
- PHPStan по `src/Marketplace`, `tests/Integration/Marketplace`, `tests/Builders/Marketplace` — 0 ошибок; CS и strict-types по изменённым файлам — 0.
- Отклонение от плана: Actions и фасад пока не используются контроллерами, поэтому контейнер их вырезает; тест собирает их вручную. Публичными для тестов не делал: вызывающие появятся в этапе 2.
- Миграция только добавляет таблицу и nullable-колонки, обратима, бэкап не нужен.
