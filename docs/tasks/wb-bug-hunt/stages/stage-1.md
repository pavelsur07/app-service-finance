### Stage 1: исправление подтверждённых дефектов WB — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required (Release Gate: решение по Draft PR)

#### Stage scope
- Stage base commit: `e7111f424b8ed17202ff7f1c1dc60adf26a7eb84`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`, `1.6`

#### What was done
- 1.1 `WildberriesAdClient`: транспортные ошибки (таймаут, обрыв) ретраятся в
  том же цикле из 3 попыток, что 429/5xx; sleep вынесен за `try/finally`, чтобы
  не искажать `durationMs`.
- 1.2 `MarketplaceCostPriceResolver` читает `orderDt` и `order_dt`;
  `WbReturnsRawProcessor` передаёт дату возврата как последний fallback.
  Себестоимость возврата WB больше не становится `0.00` молча.
- 1.3 `MarketplaceBarcodeCatalogService::fillFromWbRows` читает camelCase через
  `WbSalesReportRowNormalizer`; справочник barcode→size снова наполняется.
- 1.4 `WbFinancialReportSyncPlanner::planRefreshRecentDays`: модель «одна строка
  статуса на день»; уже обновлённые дни и дни initial/missing входят в
  скользящее окно, порядок по `updated_at ASC`.
- 1.5 `WbFinanceReportConnector::pull` отдаёт `continuationDelaySeconds` при
  переходе на следующий день (по образцу `OzonSellerReportConnector`).
- 1.6 Логирование: `ProcessWbCostsAction` (sellerOperName через normalizer,
  агрегированный error), `WbAdDailySpendCommand` и
  `WbInventoryDailySyncCommand` (warning по элементу + один агрегированный
  error за прогон).

#### Files changed
- `site/src/MarketplaceAds/Infrastructure/Api/Wildberries/WildberriesAdClient.php` — modified
- `site/src/MarketplaceAds/Command/WbAdDailySpendCommand.php` — modified
- `site/src/Marketplace/Application/Service/MarketplaceCostPriceResolver.php` — modified
- `site/src/Marketplace/Application/Processor/WbReturnsRawProcessor.php` — modified
- `site/src/Marketplace/Application/Service/MarketplaceBarcodeCatalogService.php` — modified
- `site/src/Marketplace/Application/Service/WbFinancialReportSyncPlanner.php` — modified
- `site/src/Marketplace/Application/ProcessWbCostsAction.php` — modified
- `site/src/Ingestion/Application/Source/Wildberries/WbFinanceReportConnector.php` — modified
- `site/src/Inventory/Command/WbInventoryDailySyncCommand.php` — modified
- `site/tests/Unit/MarketplaceAds/WildberriesAdClientTest.php` — modified (+2 теста)
- `site/tests/Unit/MarketplaceAds/Command/WbAdDailySpendCommandTest.php` — modified (+2 теста, 1 скорректирован)
- `site/tests/Unit/Marketplace/Application/Processor/WbReturnsRawProcessorRefundAmountTest.php` — modified (+2 теста)
- `site/tests/Unit/Marketplace/Application/Service/MarketplaceBarcodeCatalogServiceTest.php` — new
- `site/tests/Unit/Marketplace/Application/Service/WbFinancialReportSyncPlannerTest.php` — modified (+2 теста, 3 скорректированы под однострочную модель)
- `site/tests/Unit/Ingestion/Application/Source/Wildberries/WbFinanceReportConnectorTest.php` — modified (+1 assertion)
- `site/tests/Integration/Inventory/Command/WbInventoryDailySyncCommandTest.php` — modified (+2 теста)
- `site/phpstan-baseline.neon` — сокращение (createMock в WbInventoryDailySyncCommandTest 2→1)
- `docs/tasks/wb-bug-hunt/*` — new

#### Definition of Done
- [x] Регрессионные тесты 1.1–1.5 красные на `stage_base_commit` (8 failures + 1 error, прогон 2026-09-04), зелёные после
- [x] `composer test:unit` зелёный
- [x] Интеграционные тесты `Marketplace|Ingestion|Inventory` зелёные
- [x] PHPStan level 8 (`make site-stan`) зелёный, baseline сокращён
- [x] php-cs-fixer по изменённым файлам чист
- [x] Внешнее ревью Codex — `REVIEW_GREEN`
- [x] Stage Report, checkpoint, handoff записаны

#### Baseline
- `phpunit --testsuite unit --filter 'Wb|Wildberries'` — OK (553)
- `phpunit --testsuite integration --filter 'Wb|Wildberries'` — OK (188)
- `composer test:unit` на master — OK (2273, 4 pre-existing deprecations в
  `UserEntityTest` и `MarketplaceProcessCostsRouteTest`)

#### Checks
- targeted: `phpunit --testsuite unit --filter '<8 классов>'` — OK (122)
- targeted: `phpunit --testsuite integration --filter WbInventoryDailySyncCommandTest` — OK (9)
- module: `phpunit --testsuite integration --filter 'Marketplace|Ingestion|Inventory'` — OK (894)
- full relevant stage: `composer test:unit` — OK (2282, те же 4 deprecations)
- static: `make site-stan` — `[OK] No errors`
- style: `php-cs-fixer --dry-run` по изменённым файлам — 0 fixable

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: sleep внутри замера `durationMs` в `WildberriesAdClient` — исправлено
- MINOR fixed: none
- FOLLOW-UP: см. handoff (Inventory messenger-retry, stale WB finance transactions, N+1 в first-available resolver, размер `"0"` в кэше листингов, гонка сессий, 204 в WbOrdersClient, decimalToMinor/rowKey в preview mapper)

#### External Claude Code review
- Ревьюер: Codex (`codex exec -s read-only --ephemeral`, дифф и контекст через stdin, без шелла)
- Iterations: 3
- Result: REVIEW_GREEN
- Confirmed findings fixed:
  - it.1 IMPORTANT `WbInventoryDailySyncCommand`: все Throwable как warning → warning по компании + один агрегированный error, 2 интеграционных теста
  - it.2 IMPORTANT `WbAdDailySpendCommand`: error на каждое подключение в цикле → warning по подключению + один агрегированный error для не-transient, тест с двумя инцидентами
- Rejected findings with reason: none
- Ограничение ревьюера: без шелла и БД — факты о схеме (уникальный индекс
  статусов), cron и лимитере переданы в промпте.

#### Review fixes applied
- см. выше

#### Risks / reviewer focus
- 1.4 меняет частоту refresh: теперь каждый день из 14-дневного окна
  обновляется циклически (1 день в час на подключение), а не один раз. Нагрузка
  на WB API ограничена cooldown/лимитером и `--max-days=1`.
- 1.2 изменит `cost_price` у новых возвратов WB, где раньше было `0.00`.
  Уже сохранённые возвраты не пересчитываются автоматически — при необходимости
  пересчёт через существующий `RecalculateListingCostPriceMessage`/reprocess.
- 1.5: переход на следующий день теперь идёт отложенным continuation-сообщением
  (70 с), а не немедленным циклом; курсор на день сохраняется при завершении
  задачи, как и для страничных продолжений.

#### Checkpoint
- `docs/tasks/wb-bug-hunt/checkpoint.md` updated
- exact next action: Release Gate — решение Владельца по Draft PR

#### Open questions
- none

#### Expected owner response
Recommended response:
`Перевести Draft PR в Ready и смержить в master (с автодеплоем)`

Alternative responses, when relevant:
- `Оставить Draft, нужны правки: <что именно>`
- `Проверить прод по логу импорта себестоимости` (read-only SQL из handoff)
