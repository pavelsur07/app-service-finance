### Stage 1: схема FBO / FBS в Query и контроллере — DONE

**Risk:** MEDIUM
**Stage base commit:** `6e46fda5c`
**Work items:** 1.1 (`FulfillmentScheme`), 1.2 (схема в `InventoryStockReportQuery`, `StockReportController`)

#### What was done
- Enum `FulfillmentScheme` (`fbo` | `fbs`, `FBS_TYPES = ['fbs','rfbs']`, `fromFulfillmentType()`).
- Все три метода `InventoryStockReportQuery` принимают схему; фильтр — одно SQL-выражение `CASE … IN (:fbsTypes)`;
  строка без типа — FBO. Эффективная дата ищется внутри схемы. Разбивка пустая при одном типе.
- Контроллер: параметр `scheme`, невалидное значение → `fbo`; в шаблон уходят `scheme`, `schemes`.

#### Files changed
- `site/src/Inventory/Enum/FulfillmentScheme.php` — new
- `site/src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php` — modified
- `site/src/Inventory/Controller/StockReportController.php` — modified
- `site/tests/Unit/Inventory/Enum/InventoryEnumsTest.php`, `site/tests/Functional/Inventory/Controller/StockReportControllerTest.php`,
  `site/tests/Integration/Inventory/Application/NormalizeInventorySnapshotActionTest.php` — tests

#### Definition of Done
- [x] `scheme=fbs` — только `fbs`/`rfbs`, `scheme=fbo` и без параметра — остальное
- [x] эффективная дата и разбивка внутри схемы; разбивка скрыта при одном типе
- [x] тесты: 4 новых + 2 кейса в `brokenQueryProvider`, старый rollup-тест переписан под FBO

#### Checks
- `phpunit tests/Unit/Inventory/Enum/InventoryEnumsTest.php` — OK (6)
- `phpunit StockReportControllerTest NormalizeInventorySnapshotActionTest` — OK (30 tests, 177 assertions)
- `phpstan analyse` по трём файлам — 0 ошибок; `php-cs-fixer --dry-run` — чисто

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none; MINOR fixed: `NormalizeInventorySnapshotActionTest` получил аргумент схемы

#### External review
- required: no (MEDIUM) — на handoff в составе общего ревью

#### Risks / reviewer focus
- строка без типа ни в одном режиме не теряется и не дублируется (тест `testRowWithoutFulfillmentTypeStaysInFboScheme`)
- `COUNT(DISTINCT …)` пагинации считается с фильтром схемы (тот же `baseQueryBuilder`)

#### Next
- continue to Stage 2 automatically
