# Stage 1 — калькулятор, каталог, маппинг, тесты

## Какую задачу решаем

Строки WB с `sellerOperName = "Сумма баллов, удержанных в рамках акции \"Баллы за отзывы\""`
не поддерживает ни один калькулятор. `ProcessWbCostsAction` считает строку распознанной,
если хотя бы один калькулятор из тега `app.marketplace.cost_calculator` вернул
`supports() = true`; иначе имя операции уходит в `unprocessed_cost_types`, и гейт #387
краснеет. Затраты пишут оба процессора (`ProcessWbCostsAction`, `WbCostsRawProcessor`) —
через тот же тег, поэтому одного калькулятора достаточно для обоих путей.

## Что будет результатом

| # | Файл | Изменение |
|---|---|---|
| 1 | `site/src/Marketplace/Wildberries/Infrastructure/Normalizer/WbSalesReportRowNormalizer.php` | `cashbackAmount()` — ключи `cashbackAmount` / `cashback_amount`, строка → float |
| 2 | `site/src/Marketplace/Wildberries/CostCalculator/WbReviewPointsWithheldCalculator.php` | новый калькулятор: `supports()` по имени операции (кавычки «»/„“/"" и пробелы нормализуются); сумма `abs(cashbackAmount)`; `> 0` → `CHARGE`, `< 0` → `STORNO`; `< 0.01` → `[]`; код `wb_review_points_withheld`; `category_name` «Баллы за отзывы (удержание)»; `description` «Баллы за отзывы: удержание внутри маркетплейса (по сути реклама/маркетинг)»; `external_id` через `WbCostExternalIdBuilder` |
| 3 | `site/config/services.yaml` | регистрация с тегом `app.marketplace.cost_calculator`, priority 98 |
| 4 | `site/src/Marketplace/Wildberries/Domain/WbCostCategory.php` | категория `wb_review_points_withheld`, группа «Продвижение и реклама», bucket `other` |
| 5 | `site/config/marketplace/default_cost_mapping.yaml` | `wb_review_points_withheld → PROMO_GIVEAWAYS`, `include_in_pl: true`, `note` «Удержание внутри маркетплейса, по сути реклама/маркетинг» |
| 6 | `site/src/DataFixtures/MarketplaceCostCategoriesFixtures.php` | категория для dev-фикстур — только если у фикстур есть `plRef` на «Раздачи…»; иначе не трогать |
| 7 | `site/tests/Unit/Marketplace/Wildberries/CostCalculator/WbReviewPointsWithheldCalculatorTest.php` | unit на все ветки калькулятора |
| 8 | `site/tests/Unit/Marketplace/Wildberries/Application/Processor/WbCostsRawProcessorTest.php` | новый калькулятор в `allWbCostCalculators()`; регрессия: строка-образец с прода (обезличенная) поддерживается ровно одним калькулятором |
| 9 | `ARCHITECTURE.md` | строка в `Changelog` |

Тест-кейсы калькулятора:
- camelCase-строка (как на проде: `cashbackAmount: "350"`) → одна затрата 350, `CHARGE`, код и описание как выше, `external_id = wb:<rrdId>:wb_review_points_withheld`;
- snake_case (`supplier_oper_name`, `cashback_amount`, `rrd_id`) → тот же результат;
- `"-400"` → 400, `STORNO`;
- `"0"` и отсутствие поля → `[]`, но `supports() = true` (строка распознана);
- имя в «ёлочках» и с двойным пробелом → `supports() = true`;
- «Аванс за услугу "Баллы за отзывы"», «Удержание», «Продажа» → `supports() = false`;
- `cost_date` = `saleDt`.

## Что считаем выполненным

Код и тесты в ветке `fix/wb-review-points-withheld` закоммичены локально; все гейты
зелёные; внешнее ревью Codex пройдено; находки закрыты или отклонены с причиной.

## Чек-лист готовности этапа

- [x] Новый калькулятор оформлен как соседние (`class` без `final` — так у всех WB-калькуляторов), `declare(strict_types=1)`
- [x] Unit-тест калькулятора покрывает все кейсы выше
- [x] Регрессия: на `master` образец с прода не поддерживается ни одним калькулятором (тест красный), на ветке — ровно одним
- [x] `DefaultMappingConfigTest` (каждый код каталога имеет правило, `pl_code` из стандартного дерева) — зелёный
- [x] `CostCategoryCatalogFacadeTest`, `WbCostCategoryTest` — зелёные
- [x] `make site-cs-check`, `make site-cs-strict-types` — зелёные (прогон без кеша перед handoff)
- [x] `make site-stan` — baseline не вырос
- [x] `make site-test-unit` — зелёный; integration-тесты маппинга (`SyncDefaultCostMappingCommandTest`, `ApplyDefaultCostMappingConsoleCommandTest`, `PreviewDefaultCostMappingActionTest`) — зелёные
- [x] Внешнее ревью Codex — `REVIEW_GREEN` или находки закрыты
- [x] `ARCHITECTURE.md` Changelog обновлён

## Итог этапа

Выполнено 19.10.2026, коммит `bd1687548`.

- Калькулятор `WbReviewPointsWithheldCalculator`, `cashbackAmount()` в нормализаторе,
  категория в `WbCostCategory`, правило → `PROMO_GIVEAWAYS` с `note`, регистрация priority 98.
- Отступления от плана: шаг 6 (фикстуры) не нужен — в dev-фикстурах нет статьи «Раздачи…».
  `PROMO_GIVEAWAYS` добавлен в `ALLOWED_PL_CODES` `DefaultMappingConfigTest` (статья есть в
  `default_pl_tree.json`). Ключ `product` = `null` — процессоры его не читают, а
  `Product|null` не проходит форму интерфейса; в тесте процессора — полное имя класса вместо
  `use`, чтобы не сдвигать номера строк анонимных классов в baseline.
- Регрессия: без нового калькулятора тест падает (`actual size 0 matches expected size 1`), с ним — зелёный.
- Гейты: CS (без кеша) — 0 файлов; strict_types — 0; PHPStan — `No errors`, baseline не тронут;
  unit — 3912 OK; integration Marketplace — 510 OK (локальный redis с алиасом `site-redis`, удалён).
- Внешнее ревью Codex (medium) — `REVIEW_GREEN` с первого раунда.
