# Stage 1: Action — отчёты по каналам

stage_base_commit: `f47d1760e` (master), ветка `feat/pl-preview-rows-export`.
Risk: LOW — только чтение, расчёт не меняется.

## 1. Какую задачу решаем

Выгрузке строками нужен набор отчётов предпросмотра «по одному на канал» и отдельный общий
отчёт (`marketplace = null`) для контроля суммы каналов (ТЗ §3.2, §4.2). Считать их должен
существующий `BuildMarketplacePlPreviewAction` — своих формул выгрузка не вводит (ТЗ §5.4).

## 2. Что будет результатом

- `Marketplace\Application\DTO\PlPreviewRowsExport` (`final readonly`): `from`, `to`,
  `list<MarketplacePlPreviewReport> $channels`, `?MarketplacePlPreviewReport $all`.
- `Marketplace\Application\Action\BuildPlPreviewRowsExportAction` (`final readonly`, `__invoke`):
  - `marketplace` задан → один отчёт по каналу, `all = null` (контроль бессмыслен);
  - не задан → сначала общий отчёт, затем по отчёту на каждый канал из объединения:
    каналы с SELLER-подключением компании ∪ каналы с данными периода в общем отчёте
    (`costCoverage`, `salesCoverage`, `adReconciliation`) — канал с данными, но без
    подключения, не теряется; подключение без данных даёт нулевой канал;
  - порядок каналов — по значению enum (как `projectWarnings`), детерминирован;
  - ошибка периода — `MarketplacePlPreviewPeriodException` из исходного Action, до запросов по каналам.
- Integration-тест `BuildPlPreviewRowsExportActionTest` на `PlPreviewFixture`.

## 3. Что считаем выполненной работой

Action и DTO в `src/Marketplace/Application/`, тест зелёный, PHPStan и CS чистые по изменённым
файлам, существующие тесты предпросмотра (baseline 35 tests / 128 assertions) зелёные.

## 4. Чек-лист приёмки

- [x] Без `marketplace`: каналы фикстуры `ozon`, `wildberries` (данные без подключений) — оба в результате, в порядке значений.
- [x] SELLER-подключение без данных (`yandex_market`) добавляет нулевой канал; PERFORMANCE-подключение канал не добавляет.
- [x] Сумма `total` каналов по каждой строке дерева = `total` общего отчёта на фикстуре.
- [x] С `marketplace` — ровно один отчёт этого канала, `all === null`.
- [x] Фильтр тегов передаётся в каждый вызов (колонки каналов = колонки общего).
- [x] `from > to` и > 366 дней → `MarketplacePlPreviewPeriodException`.
- [x] Данные чужой компании в отчёты не попадают (каналы по `companyId`).
- [x] Нет `flush()`, `Request`/`Response`; `declare(strict_types=1)`; constructor injection.
- [x] PHPStan по изменённым файлам без новых ошибок; CS без замечаний.

## Итог этапа

Сделано: `PlPreviewRowsExport`, `BuildPlPreviewRowsExportAction`,
`tests/Integration/Marketplace/PlPreview/BuildPlPreviewRowsExportActionTest.php` (7 тестов).
Пункты чек-листа закрыты все: каналы фикстуры `ozon`, `wildberries` берутся из данных без
подключений; SELLER `yandex_market` даёт нулевой канал, PERFORMANCE `sber_megamarket` — нет;
подключение чужой компании канал не добавляет; сумма `total` каналов = общему отчёту.

Проверки: `phpunit tests/Integration/Marketplace/PlPreview tests/Functional/Marketplace/Controller/PlPreviewControllerTest.php`
— OK, 42 tests / 150 assertions (baseline 35 / 128 + 7 новых). PHPStan по трём файлам — No errors;
php-cs-fixer (`.php-cs-fixer.php`, без кеша) — 0 замечаний после выравнивания phpdoc.

Внутреннее ревью: IDOR — каналы по `companyId` команды, подключения по `companyId`; запись в БД
отсутствует; запросов подключений не больше 4 (по числу каналов enum). Замечаний BLOCKER/IMPORTANT нет.
