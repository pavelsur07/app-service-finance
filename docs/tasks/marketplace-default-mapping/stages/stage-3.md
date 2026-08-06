# Stage 3: актуальные PROD-типы Ozon не попадают в `ozon_other_service` — DONE

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `5b77b5bb`

## Результат

- В `OzonCostCategory` добавлены восемь точных идентификаторов из read-only PROD-аудита. Шесть стали алиасами существующих категорий; созданы `ozon_stock_insurance` и `ozon_brand_verified`.
- Владелец подтвердил финансовые назначения: страхование → `OPEX_WH_MP_DEDUCTIONS`, «Бренд проверен» → `OVERHEAD_PROD_CERT`.
- `default_cost_mapping.yaml` и каталог взаимно полны: 85/85 кодов, без лишних правил. Версия `OzonServiceCategoryMap` поднята до `2026-08-06.1`.
- Новые данные будут классифицироваться точно, без warning и fallback. Исторические данные PROD не менялись.

## Проверки

- Baseline targeted — 25 тестов, 1948 assertions: green.
- Targeted resolver/provider/config/functional apply — 38 тестов, 2340 assertions: green.
- Финальный targeted после review-fix — 27 тестов, 2318 assertions: green.
- `make site-test-unit` — 1826 тестов, 10455 assertions: green.
- Каталог/YAML — 85/85, differences 0; `git diff --check` — green.
- PHP CS Fixer dry-run подтвердил прежний style baseline в трёх legacy-файлах; новых нарушений в изменённых строках нет, `WidgetGroupBackwardCompatTest` clean.

## Review

- Внутренний review полного diff от `5b77b5bb`: BLOCKER/IMPORTANT нет.
- Первый запуск внешнего reviewer исчерпал 40 turns без вердикта и не был засчитан. Повторный review: `REVIEW_GREEN`; безопасный MINOR про case-вариант `MarketPlace` исправлен комментарием.
- Финальный внешний review после исправления: `REVIEW_GREEN`, BLOCKER/IMPORTANT нет.
- Замечание о дублировании всех алиасов в domain fixture отклонено: этот тест требует отдельное каноническое имя для каждой строки; восемь алиасов полностью проверяются через `OzonServiceCategoryMapTest`, две новые категории добавлены в domain fixture.
- `MarketplaceServiceItemProductReviewsManagementSubscription` оставлен в `ozon_reviews`: это точная предметная категория; альтернативная подписочная категория ведёт в тот же `PROMO_INTERNAL` и не меняет ОПиУ.

## Follow-up / Production Gate

- Уже сохранённые строки `ozon_other_service` и нерешённые `marketplace_mapping_errors` автоматически не меняются.
- `OperationMarketplacePackageRedistribution` раньше fuzzy-резолвился в материалы, теперь точно попадает в упаковку партнёрами; обе категории ведут в `COGS_RETURNS_DELIVERY`, поэтому ОПиУ не меняется, но исторические widget/xlsx-группы останутся прежними до reprocess.
- Любая историческая переобработка или очистка журнала — отдельная production mutation с отдельным разрешением Владельца.

## Scope safety

- Миграций, SQL-записи, очередей, внешних API-вызовов и PROD mutation не было.
- Посторонние untracked `.mimocode/`, `docs/integrations/` и `site/ui-kit/_audit/*` не затрагивались.
