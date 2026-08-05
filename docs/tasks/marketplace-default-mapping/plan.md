# Базовый маппинг маркетплейсов: всё в YAML

## Context

В модуле Маркетплейс два экрана маппинга, и настроены они сегодня неравномерно:

- `/marketplace/cost-pl-mapping` — механизм автонастройки уже написан (YAML → preview → apply → модалка «Базовый маппинг затрат»), но `wildberries.cost_mappings` в конфиге **пустой**, а Ozon-правила собраны из гипотез (`confidence: low/medium`, пометки «до финальной сверки») и расходятся с тем, как владелец настроил кабинет руками.
- `/marketplace/pl-mappings` — автонастройки **нет вообще**, только ручное создание правил.

Эталон — эталонный кабинет на PROD: 12 правил продаж/возвратов (матрица 6×2) и 86 правил затрат, настроенных вручную. Цель ветки — перенести это знание в YAML, чтобы новый кабинет настраивался кнопкой, а не 98 ручными выборами.

Отдельно зафиксировано: в эталоне два Ozon-правила возвратов имеют неверный знак (`is_negative=false` при положительных суммах в источнике), из-за чего за первое полугодие 2026 завышены выручка с СПП и себестоимость (точные суммы переданы Владельцу отдельно). **YAML пишем с правильным знаком**, но правку самих данных PROD ветка не делает — это отдельное решение владельца (см. «Вне scope»).

## Решения, принятые до начала

| Вопрос | Решение |
|---|---|
| Конфликт с существующим правилом | Только создавать недостающие. Существующее правило не трогаем никогда (`SKIPPED_EXISTING`), как в затратах. Ошибочный знак у эталонный кабинет правится вручную двумя галочками |
| Помесячные категории WB (утилизация) | 9 кодов по различимой букве месяца → `OPEX_WH_MP_DEDUCTIONS`. Работает на текущем движке точного совпадения, нового кода не требует |
| `ozon_agency_fee` | `PRODUCT_INFRA_FF_SERVICES`, `confidence: medium` |
| Источник истины по Ozon | Эталонный кабинет. Где эталон и текущий YAML расходятся — берём эталон |
| Раскладка файлов | Два YAML в `config/marketplace/`, каждый со своим провайдером. Существующий провайдер затрат и его тесты не трогаем — меньше риска, чем обобщать рабочий парсер |

## Часть A — YAML затрат (только данные, кода не пишем)

Файл: `site/config/marketplace/default_cost_mapping.yaml`

### A1. Ozon: пересобрать из эталона

Правило сборки: 67 кодов эталона 1:1 + 16 кодов, которых в эталоне нет, но которые уже описаны в текущем YAML (`charity`, `defect_rate_*`, `fines_incomplete`, `fines_wrong_item`, `marketing_action_operation`, `marketing_services_subscription`, `original_label`, `partial_compensation_to_client`, `pin_review`, `sending_push_notifications`, `site_advertising`, `temporary_storage`, `fines_shipment_delay_rated_cancelled`) — оставляем как есть. Полный перечень кодов брать из `OzonCostCategory::all()` (`src/Marketplace/Domain/OzonCostCategory.php`), чтобы покрыть каталог, а не только то, что встретилось в одном кабинете.

**Восемь правил, где текущий YAML расходится с эталоном — привести к эталону:**

| cost_code | было в YAML | станет |
|---|---|---|
| `ozon_package_labor` | OPEX_WH_MP_DEDUCTIONS | COGS_RETURNS_DELIVERY |
| `ozon_package_materials` | OPEX_WH_MP_DEDUCTIONS | COGS_RETURNS_DELIVERY |
| `ozon_return_from_stock` | OPEX_WH_MP_DEDUCTIONS | COGS_RETURNS_DELIVERY |
| `ozon_additional_packaging_warehouse` | OPEX_WH_MP_DEDUCTIONS | OPEX_WH_STORAGE |
| `ozon_supply_shortage` | OPEX_WH_MP_DEDUCTIONS | OPEX_WH_RECEIVING |
| `ozon_fines_shipment_delay` | OPEX_WH_MP_DEDUCTIONS | OPEX_WH_PENALTIES |
| `ozon_service_fee_rfbs` | COGS_DELIVERY | OPEX_WH_MP_DEDUCTIONS |
| `ozon_early_payment` | OVERHEAD_ADMIN_BANK | OVERHEAD_ADMIN_BANK (оставляем; в эталоне не смаплен, но код финансовой услуги однозначен) |

**Двадцать один код, который в эталоне остался без категории ОПиУ** (все с нулевым объёмом затрат — заготовки каталога):

| pl_code | cost_code |
|---|---|
| COGS_DELIVERY | `ozon_delivery`, `ozon_logistic_delivery`, `ozon_logistic_direct_trans`, `ozon_logistic_direct_vdc`, `ozon_logistic_kgt`, `ozon_dropoff_ff`, `ozon_dropoff_ppz` |
| COGS_RETURNS_DELIVERY | `ozon_logistic_return_trans`, `ozon_return_delivery`, `ozon_return_after_delivery`, `ozon_return_not_delivered`, `ozon_return_partial` |
| OPEX_WH_STORAGE | `ozon_return_storage_pvz`, `ozon_return_storage_wh` |
| OPEX_WH_RECEIVING | `ozon_logistic_inbound_seller` |
| OVERHEAD_PROD_CERT | `ozon_marking` |
| OVERHEAD_ADMIN_BANK | `ozon_flexible_payment` |
| COGS_ACQUIRING | `ozon_installment` (`confidence: medium`) |
| PROMO_INTERNAL | `ozon_stars_membership` (`confidence: medium`) |
| PRODUCT_INFRA_FF_SERVICES | `ozon_agency_fee` (`confidence: medium`) |

### A2. Wildberries: заполнить пустой список

17 стабильных кодов (объединение `WbCostCategory::all()`, `RestoreMarketplaceCostCategoriesAction::getCategoriesForMarketplace()` и наблюдаемых в эталоне слагов):

| pl_code | cost_code |
|---|---|
| COGS_MP_COMMISSION | `commission` |
| COGS_DELIVERY | `logistics_delivery`, `logistics_correction` |
| COGS_RETURNS_DELIVERY | `logistics_return`, `pvz_processing` |
| COGS_ACQUIRING | `acquiring` |
| OPEX_WH_STORAGE | `storage`, `warehouse_logistics` |
| OPEX_WH_RECEIVING | `product_processing` (это `paidAcceptance`, см. `WbProductProcessingCalculator:39`) |
| OPEX_WH_PENALTIES | `penalty` |
| PROMO_INTERNAL | `advertising`, `wb_okazanie_uslug_wb_prodvizhenie`, `wb_spisanie_za_otzyv`, `wb_loyalty_discount_compensation`, `wb_avans_za_uslugu_bally_za_otzyvy`, `wb_predostavlenie_uslug_po_podpiske_dzhem`, `wb_vozvrat_neispolzovannogo_ostatka_avansa_za_uslu` |

Плюс 9 помесячных кодов утилизации → `OPEX_WH_MP_DEDUCTIONS`. Код формируется в `WbDeductionCalculator:91-96`: слаг названия с префиксом `wb_`, обрезанный до 50 байт, поэтому от месяца остаётся одна буква на 50-й позиции:

```
wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_ + {y|f|m|a|i|s|o|n|d}
```

`m` = март и май, `a` = апрель и август, `i` = июнь и июль — коллизии по построению. В `note` каждого правила зафиксировать, что код обрезан и месяц неразличим; сам дефект усечения выносим в follow-up.

### A3. Обновить существующий функциональный тест

`tests/Functional/Marketplace/Controller/CostPLMappingDefaultSetupControllerTest.php` содержит хардкод-таблицу `NEW_OZON_COST_MAPPINGS` (21 пара), где `ozon_service_fee_rfbs => COGS_DELIVERY`. После A1 тест станет красным — таблицу привести к новому YAML в том же коммите.

## Часть B — YAML и движок маппинга продаж/возвратов

### B1. Конфиг

Новый файл `site/config/marketplace/default_sale_mapping.yaml`, структура зеркалит затратный (`version: 1`, `marketplaces:` → `sale_mappings:`):

```yaml
version: 1
marketplaces:
  ozon:
    sale_mappings:
      - amount_source: sale_gross
        pl_code: REV_NOT_SPP
        is_negative: false
      - amount_source: sale_realization
        pl_code: REV_SPP_SALES
        is_negative: false
      - amount_source: sale_cost_price
        pl_code: COGS_PRODUCT_REV
        is_negative: false
      - amount_source: return_gross
        pl_code: REV_RETURNS
        is_negative: true
      - amount_source: return_realization
        pl_code: REV_SPP_RETURNS
        is_negative: true          # в эталоне false — ошибка, см. Context
        description: "Возврат с СПП Ozon"
      - amount_source: return_cost_price
        pl_code: COGS_PRODUCT_RET
        is_negative: true          # в эталоне false — ошибка
  wildberries:
    sale_mappings:
      # sale_gross → REV_NOT_SPP, sale_revenue → REV_SPP_SALES,
      # sale_cost_price → COGS_PRODUCT_REV, return_gross → REV_RETURNS,
      # return_refund → REV_SPP_RETURNS, return_cost_price → COGS_PRODUCT_RET
```

`operation_type` не хранить — он выводится из `AmountSource::getOperationType()`. Провайдер обязан отвергать правило, чей `amount_source` ограничен другим маркетплейсом (`AmountSource::getMarketplaceRestriction()`): `sale_realization`/`return_realization` — только Ozon.

`description` — опциональное поле. В эталоне заполнено только у `ozon / return_realization` («Возврат с СПП Ozon»), у остальных 11 правил `description_template` пустой, и `CloseMonthStageAction:177` подставляет название этапа. Оставляем так же: одно описание в YAML, у прочих правил поля нет.

### B2. Код (все файлы новые, существующий механизм затрат не трогаем)

| Файл | Роль |
|---|---|
| `src/Marketplace/Application/DTO/DefaultSaleMappingRule.php` + `…RuleSet.php` | правило: marketplace, amountSource, plCode, isNegative, description, confidence, note |
| `src/Marketplace/Infrastructure/Provider/DefaultSaleMappingYamlProvider.php` | парсинг и валидация по образцу `DefaultCostMappingYamlProvider` (версия, неизвестный маркетплейс, дубли `amount_source`, ограничение маркетплейса) |
| `src/Marketplace/Enum/DefaultSaleMappingPreviewStatus.php` | `WILL_CREATE`, `SKIPPED_EXISTING`, `MISSING_PL_CATEGORY`, `INVALID_TARGET_CATEGORY` |
| `src/Marketplace/Infrastructure/Query/ActiveSaleMappingsByAmountSourceQuery.php` | DBAL-запрос активных правил компании по маркетплейсу, индексированный по `amount_source` (аналог `MarketplaceSaleMappingRepository::findActiveIndexedByAmountSource`, но по `string $companyId`) |
| `src/Marketplace/Application/Action/PreviewDefaultSaleMappingAction.php` | правила × `PLCategoriesByCodeQuery` (переиспользуем как есть) × активные правила → список статусов |
| `src/Marketplace/Application/Action/ApplyDefaultSaleMappingAction.php` | preview → транзакция → писать только `WILL_CREATE` |
| `src/Marketplace/Infrastructure/Writer/DefaultSaleMappingWriter.php` | `INSERT … ON CONFLICT (company_id, marketplace, operation_type, amount_source, pl_category_id) DO NOTHING`, по образцу `DefaultCostMappingWriter` |
| `src/Marketplace/Application/Command/{Preview,Apply}DefaultSaleMappingCommand.php` | companyId, marketplace (+ actorUserId для apply) |
| `src/Marketplace/Application/DTO/DefaultSaleMappingPreview*.php`, `…ApplyResult.php` | результаты |
| `src/Marketplace/Controller/SaleMappingDefaultSetupController.php` | `POST /marketplace/pl-mappings/default/{preview,apply}`, CSRF-токен `marketplace_default_sale_mapping`, `getActiveCompany()` |

Правила статусов в preview:
- нет категории ОПиУ с таким `code` → `MISSING_PL_CATEGORY` (блокирующий, как в затратах);
- найдено больше одной или тип не `LEAF_INPUT` → `INVALID_TARGET_CATEGORY` (блокирующий);
- уже есть активное правило на этот `amount_source` → `SKIPPED_EXISTING` (независимо от того, совпадает ли категория и знак);
- иначе `WILL_CREATE`, создаётся сразу активным (`is_active = true`), `sort_order = 0` — как во всех правилах эталона; поля в YAML нет, пока не понадобится.

Инвариант, который надо проверить тестом: у всех правил с `operation_type = return` в YAML `is_negative = true`. Именно его нарушение и дало расхождение в эталонном кабинете.

### B3. UI

- `templates/marketplace/pl_mappings/_default_mapping_modal.html.twig` — модалка с простой таблицей (не более 6 строк на маркетплейс, вкладки и карточки-счётчики из затратной версии не нужны).
- Кнопка «Настроить автоматически» в `templates/marketplace/pl_mappings.html.twig` рядом с блоком `missingAmountSources` (строки 54–79) — там уже стоит бейдж «Не настроено: N», это естественное место. Кнопка активна только когда выбран конкретный маркетплейс (фильтр `all` — нечего применять).
- Скрипт — свой блок в шаблоне по образцу `cost_pl_mapping/index.html.twig:165-388`, но без вкладок: fetch + рендер таблицы + apply + reload. Затратную страницу под общий скрипт **не рефакторим** — она рабочая и покрыта тестом.

### B4. Wiring

`config/services.yaml` — регистрация нового провайдера рядом с существующим (строки 447-449):

```yaml
App\Marketplace\Infrastructure\Provider\DefaultSaleMappingYamlProvider:
    arguments:
        $configPath: "%kernel.project_dir%/config/marketplace/default_sale_mapping.yaml"
```

## Часть C — тесты и документация

- `tests/Unit/Marketplace/Infrastructure/Provider/DefaultSaleMappingYamlProviderTest.php` — по образцу существующего теста затратного провайдера: валидный конфиг, неизвестный маркетплейс, дубль `amount_source`, `sale_realization` под `wildberries` → исключение.
- `tests/Unit/Marketplace/Config/DefaultMappingConfigTest.php` — гварды на оба боевых YAML: каждый `pl_code` из белого списка используемых кодов (защита от опечатки), каждый `cost_code` существует в `OzonCostCategory::all()` / известном списке WB, у всех `return_*` правил `is_negative = true`.
- `tests/Functional/Marketplace/Controller/SaleMappingDefaultSetupControllerTest.php` — по образцу `CostPLMappingDefaultSetupControllerTest`: preview не пишет в БД; apply создаёт 6 правил; повторный apply создаёт 0; при отсутствии категории ОПиУ apply возвращает блокирующую ошибку.
- `ARCHITECTURE.md` — новые Action, Provider, Query, Enum.
- `docs/tasks/<id>/plan.md`, `stages/stage-*.md`, `handoff.md` по регламенту.

## Вне scope этой ветки

- **Правка знака в эталонном кабинете и перезакрытие янв–июн 2026.** Это мутация PROD; кроме того, финансовая блокировка периода блокирует переоткрытие этих периодов. Отдельное решение владельца.
- **Усечение слага WB до 50 байт** (`WbDeductionCalculator:91-96`), из-за которого июнь и июль схлопываются в один код. Follow-up: расширить колонку или брать хеш хвоста.
- Префиксные правила (`cost_code_prefix`) в движке затрат — не нужны, раз помесячные коды перечисляются явно.
- Рефакторинг затратной модалки под общий скрипт.

## Проверка

1. `make site-test-unit` — провайдеры и конфиг-гварды.
2. `make site-test` — функциональные тесты обоих экранов (`CostPLMappingDefaultSetupControllerTest` после правки таблицы должен быть зелёным).
3. Локально на компании с импортированным деревом ОПиУ:
   - `/marketplace/pl-mappings?op=sale&marketplace=ozon` → «Настроить автоматически» → preview показывает 3 `WILL_CREATE` для продаж и 3 для возвратов → apply → 6 правил, у возвратных стоит «минус»;
   - повторный клик → все `SKIPPED_EXISTING`, создано 0;
   - компания без кодов в дереве ОПиУ → `MISSING_PL_CATEGORY`, кнопка «Применить» заблокирована;
   - `/marketplace/cost-pl-mapping?marketplace=wildberries` → «Настроить базовый маппинг» → появились правила WB, которых раньше не было ни одного.
4. `make site-cs-check` — точечно по изменённым файлам: в репозитории красный baseline (сотни файлов), сравнивать с состоянием файлов до правки.
