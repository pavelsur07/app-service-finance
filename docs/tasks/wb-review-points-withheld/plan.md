# WB: «Сумма баллов, удержанных в рамках акции "Баллы за отзывы"» (GlitchTip #387)

## Какую задачу решаем

С 14.10.2026 гейт `app:marketplace:wb-costs:unrecognized-check` (cron 06:40) красный:
у WB появилась операция `sellerOperName = "Сумма баллов, удержанных в рамках акции
\"Баллы за отзывы\""`, которую не поддерживает ни один калькулятор. Строки не попадают в
`marketplace_costs`, расход на отзывы не виден в ОПиУ.

### Данные прода (19.10, read-only)

- Одна компания — ИП Лазарева (`3f4d87cc-a967-43a7-9b5e-8a9113c0a910`), один товар
  (nmId 687862798), 104 строки, `rrDate` 13.10–18.10.
- Сумма — **только** в `cashbackAmount`, строкой: `350/400/500` и `-350/-400/-500`
  (начисление и сторно). Нетто за окно +2 150 ₽.
- `forPay`, `deduction`, `retailAmount`, `ppvzForPay`, `penalty` и прочие денежные
  поля — `"0"`. `bonusTypeName`, `docTypeName` пустые. Ключи в camelCase.
- Аванса «Аванс за услугу "Баллы за отзывы"» у компании нет ни в сырье с 01.08, ни в
  `marketplace_costs` — двойного учёта с авансом нет.
- Статья `PROMO_GIVEAWAYS` «Раздачи / самовыкупы / отзывы» (`LEAF_INPUT`) есть у всех
  трёх компаний с активным WB seller (Вумджой, Лазарева, Сухоносов).

### Решение Владельца (19.10)

Это **расход**. Статья ОПиУ — «Раздачи / самовыкупы / отзывы» (`PROMO_GIVEAWAYS`).
В описании указать: удержание внутри маркетплейса, по сути — реклама/маркетинг.

## Что будет результатом

1. Калькулятор `WbReviewPointsWithheldCalculator`: строка с этой операцией даёт одну
   затрату `wb_review_points_withheld`.
   - сумма = `abs(cashbackAmount)`; `cashbackAmount > 0` → `CHARGE`, `< 0` → `STORNO`;
     `|cashbackAmount| < 0.01` → затраты нет, но строка распознана;
   - `cost_date` — `normalizer->operationDate()`, товар — из листинга, если найден
     (`requiresListing() = false`);
   - `external_id` — `WbCostExternalIdBuilder` с кодом категории (идемпотентный повтор);
   - `description` — «Баллы за отзывы: удержание внутри маркетплейса (по сути реклама/маркетинг)».
2. Категория в каталоге `WbCostCategory`: `wb_review_points_withheld`,
   «Баллы за отзывы (удержание)», группа «Продвижение и реклама» / «Продвижение и реклама»,
   bucket `other`.
3. Маппинг в `config/marketplace/default_cost_mapping.yaml`:
   `wb_review_points_withheld → PROMO_GIVEAWAYS`, `include_in_pl: true`,
   `note: "Удержание внутри маркетплейса, по сути реклама/маркетинг"`.
4. Гейт #387 зелёный после пересчёта Лазаревой за 13.10–18.10 (+ дни до деплоя).

Вне scope: `WbRawFinancialReportBuilder` — строки с `cashbackAmount` там по-прежнему
считаются в `quality.unclassified_money_rows`; сверка выплаты (`forPay = 0`) от новой
затраты не меняется. Отдельная задача, если понадобится.

## Этапы

### Stage 1 — калькулятор, каталог, маппинг, тесты (код)

| Шаг | Файл | Что |
|---|---|---|
| 1.1 | `site/src/Marketplace/Wildberries/Infrastructure/Normalizer/WbSalesReportRowNormalizer.php` | `cashbackAmount(array $row): float` по ключам `cashbackAmount`, `cashback_amount` (строка → float через существующий `float()`) |
| 1.2 | `site/src/Marketplace/Wildberries/CostCalculator/WbReviewPointsWithheldCalculator.php` | новый калькулятор по образцу `WbLoyaltyDiscountCalculator`; `supports()` — точное имя операции после нормализации кавычек («»/""/„“ → `"`) и пробелов |
| 1.3 | `site/config/services.yaml` | тег `app.marketplace.cost_calculator`, priority 98 (имя операции не пересекается с остальными) |
| 1.4 | `site/src/Marketplace/Wildberries/Domain/WbCostCategory.php` | категория `wb_review_points_withheld` |
| 1.5 | `site/config/marketplace/default_cost_mapping.yaml` | правило → `PROMO_GIVEAWAYS` с `note` |
| 1.6 | `site/tests/Unit/Marketplace/Wildberries/CostCalculator/WbReviewPointsWithheldCalculatorTest.php` | unit на все ветки: camelCase и snake_case; `+350` → CHARGE 350; `-350` → STORNO 350; `"0"` → `[]`; чужая операция → `supports=false`; «ёлочки» в имени → `supports=true`; `external_id` стабилен |
| 1.7 | `site/tests/Unit/Marketplace/Wildberries/Application/Processor/WbCostsRawProcessorTest.php` | регрессия (красная на `master`): строка-образец с прода (обезличенная) больше не попадает в `unprocessed_cost_types`, создаёт затрату нужной категории |
| 1.8 | существующие `WbCostCategoryTest`, `DefaultMappingConfigTest`, `DefaultCostMappingYamlProviderTest` | прогнать; поправить ожидания, если считают число правил/категорий |
| 1.9 | `ARCHITECTURE.md` | строка в `Changelog` раздела Marketplace (калькулятор + правило маппинга) |

Гейты: `make site-cs-check`, `make site-cs-strict-types`, `make site-stan` (baseline не
растёт), `make site-test-unit`, затронутые integration-тесты маппинга. PHPStan и гейты —
по одному (OOM на dev-машине).

Ревью: внутреннее + внешнее Codex (`site/bin/external-review.sh`), находки закрыть.

### Stage 2 — handoff и деплой

- push + PR одним шагом; вопрос Владельцу «merge and deploy #N».
- Миграций нет: категория создаётся автоматически при первой затрате, правило маппинга —
  ночной `cost-pl-mapping:sync-default` (04:35) или `cost-pl-mapping:apply-default`.

### Stage 3 — пересчёт и приёмка на проде

1. «До» (read-only): у 3f4d87cc число строк и сумма `marketplace_costs` WB за 13.10–
   дата деплоя; число строк с операцией в сырье; `unprocessed_cost_types` по документам.
2. Пересчёт — **отдельное одобрение Владельца** (§3.3):
   `codex-console app:marketplace:reprocess 3f4d87cc-a967-43a7-9b5e-8a9113c0a910 wildberries 2026-10-13 <дата деплоя>`.
3. «После»: затраты `wb_review_points_withheld` — число строк = числу строк сырья,
   нетто = сумме `cashbackAmount` (на 18.10 — 2 150 ₽); прочие категории за период не
   изменились; `unprocessed_cost_types` по этим документам пуст.
4. Правило маппинга у 3f4d87cc → «Раздачи / самовыкупы / отзывы» (после 04:35 или
   `apply-default --company-id=3f4d87cc… --marketplace=wildberries` с `--execute` — по одобрению).
5. Гейт: `codex-console app:marketplace:wb-costs:unrecognized-check --days-back=14` → exit 0;
   на следующее утро в 06:40 нового события #387 нет.

## Что считаем выполненным

- Строки «Сумма баллов, удержанных…» распознаются, дают затраты с правильным знаком и
  суммой, идут в «Раздачи / самовыкупы / отзывы» у всех WB-компаний.
- Пересчёт Лазаревой за окно выполнен и сверен с сырьём.
- Гейт #387 зелёный, в GlitchTip новых событий нет.

## Чек-лист приёмки

- [ ] Калькулятор: unit-тесты на все ветки зелёные; регрессионный тест процессора красный на `master`, зелёный на ветке
- [ ] `WbCostCategory` и `default_cost_mapping.yaml` содержат `wb_review_points_withheld` → `PROMO_GIVEAWAYS` с `note` про рекламу/маркетинг
- [ ] CS, strict_types, PHPStan (baseline не вырос), unit и затронутые integration — зелёные
- [ ] Внешнее ревью Codex — `REVIEW_GREEN` или находки закрыты с записанной причиной
- [ ] PR смержен и задеплоен
- [ ] Замеры «до» сняты до пересчёта
- [ ] Пересчёт 3f4d87cc выполнен по одобрению; строки/нетто совпадают с сырьём; другие категории не изменились
- [ ] Правило маппинга 3f4d87cc → «Раздачи / самовыкупы / отзывы»
- [ ] `unrecognized-check` exit 0; утром следующего дня #387 без нового события
