### Stage 1: H3 — мутации только через POST + CSRF — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously (Stage 2)

#### Stage scope
- Stage base commit: `861d61169a772b75b82ae3496674ace0d6a2f1fb`
- Work items completed: 1.1, 1.2, 1.3, 1.4, 1.5 (+ расширение scope по результатам внешнего review)

#### What was done
- Все HTML-формовые POST-мутации модуля Marketplace переведены/подтверждены под POST + CSRF:
  - `MarketplaceController`: test/sync/sync-period (GET→POST), processRealization (GET→POST), createConnection, editConnection (POST-ветка), syncRealization, reprocess — +CSRF
  - `CreatePerformanceConnectionController` — +CSRF (общая модальная форма, token `marketplace_connection_create`)
  - `MarketplaceSaleMappingController`: toggle (GET→POST), create, edit — +CSRF
  - `MonthCloseController` close-stage/reopen-stage/preflight (JSON 403), `CostReconciliationController` reconcile, `Api/MonthPreliminaryRebuildController` (JSON 403), `CostPLMappingController` bulk-save, `Admin/MappingErrorResolveController`, Inventory (set-cost, sync-barcodes, sync-barcode-single, import-cost-price), `RecalculateSalesCostPriceController`
- Все twig-формы получили hidden `_token`; динамические модалки — через `data-token` + JS (паттерн существующего modal-delete-connection)
- sync-period читает date_from/date_to из тела запроса вместо query
- Тесты: новый `MarketplaceMutationSecurityTest` (25 тестов: GET→405, POST без токена→403, валидный токен→проход); обновлены существующие (Admin resolve, MonthPreliminaryRebuild ×5, InventoryImport ×3, InventorySetCostRedirect ×5, unit `MarketplaceControllerCreateConnectionTest` — сигнатуры + seam override)
- plan.md: зафиксировано уточнение scope и FOLLOW-UP (CostsDebugController, JSON API-контроллеры)

#### Files changed
- `site/src/Marketplace/Controller/MarketplaceController.php` — modified
- `site/src/Marketplace/Controller/CreatePerformanceConnectionController.php` — modified
- `site/src/Marketplace/Controller/MarketplaceSaleMappingController.php` — modified
- `site/src/Marketplace/Controller/MonthCloseController.php` — modified
- `site/src/Marketplace/Controller/CostReconciliationController.php` — modified
- `site/src/Marketplace/Controller/CostPLMappingController.php` — modified
- `site/src/Marketplace/Controller/Api/MonthPreliminaryRebuildController.php` — modified
- `site/src/Marketplace/Controller/Admin/MappingErrorResolveController.php` — modified
- `site/src/Marketplace/Controller/Inventory/InventoryController.php` — modified
- `site/src/Marketplace/Controller/Inventory/InventoryImportController.php` — modified
- `site/src/Marketplace/Controller/Inventory/InventorySyncSingleBarcodeController.php` — modified
- `site/src/Marketplace/Controller/RecalculateSalesCostPriceController.php` — modified
- `site/templates/marketplace/{index,pl_mappings,sales,returns}.html.twig`, `connection/edit.html.twig`, `month_close/index.html.twig`, `cost_pl_mapping/index.html.twig`, `admin/mapping_error_list.html.twig`, `inventory/{index,history}.html.twig` — modified
- `site/tests/Functional/Marketplace/Controller/MarketplaceMutationSecurityTest.php` — new
- `site/tests/Functional/{Admin/MarketplaceMappingErrorsPageTest,Marketplace/Controller/MonthPreliminaryRebuildControllerTest,Marketplace/Controller/Inventory/InventoryImportControllerTest,Marketplace/Controller/Inventory/InventorySetCostRedirectTest}.php` — modified
- `site/tests/Unit/Marketplace/Controller/MarketplaceControllerCreateConnectionTest.php` — modified
- `docs/tasks/marketplace-security-hardening/{plan.md,checkpoint.md}` — new

#### Definition of Done
- [x] Ни один state-changing роут модуля (HTML-формовый) не доступен по GET (405)
- [x] Все HTML-формовые POST-мутации проверяют CSRF-токен; без токена — 403
- [x] Поведение для пользователя (flash, редиректы) не изменилось
- [x] Функциональные тесты: GET→405, POST без токена→403, валидный токен→успех

#### Baseline
- `make site-test-unit` — OK (1722 tests)
- `php bin/phpunit tests/Functional/Marketplace` — OK (85 tests)

#### Checks
- targeted: `MarketplaceMutationSecurityTest` — OK (25 tests, 36 assertions)
- module: `php bin/phpunit tests/Functional/Marketplace tests/Functional/Admin` — OK (119 tests)
- full stage: `make site-test-unit` — OK (1722 tests); `lint:twig templates/marketplace` — OK (22 files)

#### Internal automatic review
- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: токены после 404-проверок; Request-импорты; согласованность token ids
- FOLLOW-UP: CostsDebugController (debug), JSON API-контроллеры — зафиксированы в plan.md

#### External Claude Code review
- Iterations: 3 (одна retry по регламенту max-turns)
- Result: REVIEW_GREEN
- Confirmed findings fixed: it.1 IMPORTANT (processRealization GET-без-CSRF) — исправлено; it.2 BLOCKER (createConnection/editConnection) + IMPORTANT (syncRealization/reprocess) — исправлено, scope расширен на все HTML POST-мутации модуля
- Rejected findings with reason: it.2 FOLLOW-UP ((string)-cast в testConnection) — pre-existing style, вне scope

#### Review fixes applied
- processRealization → POST+CSRF (+форма/JS в модалке)
- createConnection, editConnection, syncRealization, reprocess, performance/create → +CSRF
- month-close (4 роута), bulk-save, admin resolve, inventory (4 роута), recalculate-cost-price → +CSRF
- Дополнительные valid-token тесты (MINOR it.1/it.2)

#### Risks / reviewer focus
- SameSite=Lax остаётся дополнительной, но не единственной линией защиты
- JSON API-контроллеры без CSRF — осознанный FOLLOW-UP (отдельное дизайн-решение)

#### Checkpoint
- `docs/tasks/marketplace-security-hardening/checkpoint.md` updated
- exact next action: Stage 2 — H4 tenant-сверка в ProcessOzonRealizationAction и ProcessMarketplaceRawDocumentAction

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
