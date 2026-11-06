### Stage 3: экспорт и закрытие — DONE

**Risk:** LOW (экспорт по готовому отчёту; документы)
**Stage base commit:** `9ab855215`
**Work items:** 3.1 экспорт в xlsx, 3.2 документы задачи и handoff

#### What was done
- `StockTurnoverXlsxExporter::export(StockTurnoverReport, outputPath)` — лист «Оборачиваемость» (12 колонок по TASK.md) и лист «Сводка»
  (всего, заморожено, доля, по корзинам, без себестоимости, даты снимков и устаревшие источники).
- `StockTurnoverExportController` (`GET /marketplace-analytics/stock-turnover/export.xlsx`): тот же `StockTurnoverFilterParser`,
  `ReportsApiRateLimiter` → 429, `StreamedResponse` с `stock-turnover-<date>-<period>d.xlsx`.
- Кнопка «Excel» в фильтрах страницы с текущими параметрами (без `page`).
- Сид functional-тестов вынесен в трейт `StockTurnoverFixtures`.
- `ARCHITECTURE.md` — маршрут экспорта, Changelog 2.152; TASK.md — исправлена формулировка источника себестоимости; stage-отчёты, checkpoint, handoff.

#### Files changed
- `site/src/MarketplaceAnalytics/Infrastructure/Export/StockTurnoverXlsxExporter.php`, `Controller/StockTurnoverExportController.php` — new
- `site/templates/marketplace_analytics/stock_turnover/index.html.twig` — кнопка Excel
- tests: `StockTurnoverExportControllerTest` (new, 3), `StockTurnoverFixtures` (new trait), `StockTurnoverControllerTest` — на трейт
- docs: `ARCHITECTURE.md`, `docs/tasks/stock-turnover-report/*`

#### Definition of Done
- [x] xlsx читается OpenSpout Reader: заголовки, 4 строки, корзина словами, пустая ячейка без цены, лист «Сводка»
- [x] фильтр корзины сужает строки, сводка в файле — по всем
- [x] без логина — редирект/401/403

#### Checks
- `phpunit tests/Functional/MarketplaceAnalytics/` — OK (25 tests, 101 assertions)
- `make site-stan` — OK, No errors (baseline не рос)
- `make site-cs-check` — OK (0 of 3783 files); `make site-cs-strict-types` — OK
- `make site-test-unit` — OK (4504 tests, 25332 assertions)

#### Internal review
- iterations: 1 (OpenSpout Reader отдаёт целые числа как int — проверки в тесте на равенство значений)

#### External review
- required: yes (handoff). Codex round 1: IMPORTANT формулы в xlsx (`StringCell` для текста, `testExportKeepsFormulaLookingTextAsStrings`), MINOR `period=abc` → 400 (fallback); независимое ревью ветки: параметры-массивы, редирект с пустой страницы, стабильный порядок, пустое состояние при фильтре — все с тестами RED→GREEN. Итог — `handoff.md`.

#### Risks / reviewer focus
- Экспорт читает весь отчёт в память до стрима (как и страница); для компаний с тысячами SKU — секунды, не минуты.
- Rate limiter общий с другими отчётами (`ReportsApiRateLimiter`).

#### Next
- handoff
