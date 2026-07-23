## Stage 2: фильтр тегов в расширенной юнит-экономике — DONE

**Риск:** 🟠 HIGH-LOCAL (меняется финансовая семантика totals)
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** yes (нужен Stage 1)
**Следующее действие:** ждать решения Владельца по Stage 3 (группировка «свернуть по тегу»)

### Scope Stage

- Stage base commit: `a3723a57`
- Ветка: `codex/listing-tags`

### Что сделано

- `ListingTagFacade` (`src/Marketplace/Facade/`) — кросс-модульная точка входа: `list`,
  `listingIdsByTags(any|all)`, `tagsForListings`.
- `ListingTagAssignmentRepository::listingIdsByTags` (DBAL): any → `DISTINCT … IN`,
  all → `GROUP BY … HAVING COUNT(DISTINCT tag_id) = :n`.
- `UnitExtendedQuery`: параметры `tagIds` + `tagsMatchAll`. Фильтр сужает набор листингов **до**
  цикла, поэтому totals считаются по отфильтрованному набору. При активном фильтре `totals.adSpend`
  = сумма построчной атрибутированной рекламы (а не `getTotalAdCostForPeriod()` за весь период).
  Каждая строка несёт `tags`.
- API `UnitExtendedController`: `tags[]` (uuid → 422, лимит 100), `tagsMatch=any|all`.
- Экспорт: `tagIds`/`tagsMatchAll` в `UnitExtendedExportRequest` и контроллере (bad uuid → 400),
  колонка «Теги» в XLSX.
- Фронтенд: `data-tags` на странице; фильтр-чипы + переключатель Любой/Все; прокидка в
  `useUnitExtended` и `ExportXlsButton`; `tags` в типе `UnitExtendedItem`;
  `client.ts buildUrl` научился сериализовать массивы как `key[]=…`.

### Затронутые файлы

- `src/Marketplace/Facade/ListingTagFacade.php` — new
- `src/Marketplace/Infrastructure/Query/ListingTagAssignmentRepository.php` — modified (`listingIdsByTags`)
- `src/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQuery.php` — modified
- `src/MarketplaceAnalytics/Controller/Api/UnitExtendedController.php` — modified
- `src/MarketplaceAnalytics/Controller/Api/UnitExtendedExportController.php` — modified
- `src/MarketplaceAnalytics/Controller/UnitExtendedIndexController.php` — modified
- `src/MarketplaceAnalytics/Infrastructure/Export/UnitExtendedExportRequest.php` — modified
- `src/MarketplaceAnalytics/Infrastructure/Export/UnitExtendedXlsxExporter.php` — modified
- `templates/marketplace_analytics/unit_extended/index.html.twig` — modified
- `assets/react/_legacy/shared/http/client.ts` — modified (array query params)
- `assets/react/_legacy/unit-extended-page.tsx` — modified
- `assets/react/_legacy/marketplace-analytics/unit-extended/{unitExtended.types.ts,useUnitExtended.ts,UnitExtendedFilters.tsx,UnitExtendedWidget.tsx,ExportXlsButton.tsx}` — modified
- `tests/Unit/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQueryTest.php` — modified (+4 теста)
- `tests/Functional/MarketplaceAnalytics/UnitExtendedTagFilterControllerTest.php` — new
- `ARCHITECTURE.md` — modified (ListingTagFacade)

### Ponytail-skip (сознательно)

Колонку «Теги» **не добавлял в React-таблицу** `UnitExtendedTable.tsx` — фиксированная шапка с
клоном через портал, синхронизация ширин по индексу колонки и `colCount` для colSpan разворота
breakdown делают вставку несортируемой колонки дорогой и хрупкой, а при активном фильтре по тегу
данные в колонке одинаковы во всех строках. Теги отдаются в API (`item.tags`) и в XLSX-экспорте —
этого достаточно; колонку в таблицу добавить отдельно по реальному запросу.

### Self-review

- [x] Scope compliance — фильтр тегов в юнит-экономике; чужие модули не тронуты
- [x] Кросс-модульная граница — `MarketplaceAnalytics` ходит в теги только через Facade
- [x] Security (IDOR) — `listingIdsByTags`/`tagsForListings` фильтруют по `company_id` в SQL
- [x] Финансовая семантика — при фильтре totals.adSpend = сумма строк; без фильтра — полный период (тесты)
- [x] Валидация trust boundary — `tags[]` uuid → 422 (API) / 400 (экспорт), лимит 100
- [x] N+1 — теги: один batch-запрос на набор
- [x] CS-Fixer (точечно) — чисто; ESLint по изменённым файлам — чисто; Vite build — OK; PHPStan в проекте нет
- [x] `make site-test` (полный прогон на свежей БД) — зелёный (2587 тестов)
- [x] `ARCHITECTURE.md` обновлён (ListingTagFacade)

### External Claude Code review

- Iterations: 0
- Result: N/A — реализацию выполнял Claude Code; внешний review той же моделью своего diff
  не даёт независимости. Проведён полный внутренний review.

### Команды для проверки

- `docker compose run --rm site-php-cli vendor/bin/phpunit tests/Unit/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQueryTest.php`
- `docker compose run --rm site-php-cli vendor/bin/phpunit tests/Functional/MarketplaceAnalytics/UnitExtendedTagFilterControllerTest.php`
- `node_modules/.bin/eslint 'assets/react/_legacy/marketplace-analytics/unit-extended/*.tsx'`
- `make site-test`

### Риски / на что обратить внимание ревьюеру

- **Смысл totals.adSpend меняется при фильтре**: сумма атрибутированной рекламы по тегированным
  листингам (может быть меньше полного периода — неатрибутированная реклама в разрез по тегам
  не попадает). Это правильное поведение, но отличается от режима «без фильтра».
- **`$request->query->all('tags')`** при скалярном `?tags=foo` даёт framework-400 (BadRequest), а не
  наш 422 — вход всё равно отвергается без 500. Фронтенд всегда шлёт `tags[]`.
- **`buildUrl` в общем http-клиенте** теперь сериализует массивы как `key[]` — аддитивно,
  срабатывает только для array-значений, остальные запросы не затронуты.
- **Мультитеги двоят выручку при будущей группировке** — в Stage 2 не проявляется (фильтр не
  группирует), но всплывёт в Stage 3.

### Открытые вопросы

- нет
