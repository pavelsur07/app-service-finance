## Stage 1: YAML базового маппинга затрат пересобран по эталону — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously

### Scope Stage
- Stage base commit: `746c1bfa`
- Work items completed: `1.1` (Ozon из эталона), `1.2` (Wildberries с нуля), `1.3` (правка хардкод-таблиц в существующих тестах)

### Что сделано

- `default_cost_mapping.yaml` для Ozon пересобран: было 39 правил из гипотез, стало **83 — весь каталог `OzonCostCategory::all()`**. Источник истины — вручную настроенный эталонный кабинет (67 кодов), плюс 16 кодов, которых в кабинете нет, оставлены с прежними решениями.
- Восемь правил приведены к эталону (расходились с ручной настройкой владельца):
  `ozon_package_labor`, `ozon_package_materials`, `ozon_return_from_stock` → `COGS_RETURNS_DELIVERY`;
  `ozon_additional_packaging_warehouse` → `OPEX_WH_STORAGE`;
  `ozon_supply_shortage` → `OPEX_WH_RECEIVING`;
  `ozon_fines_shipment_delay` → `OPEX_WH_PENALTIES`;
  `ozon_service_fee_rfbs` → `OPEX_WH_MP_DEDUCTIONS`;
  `ozon_early_payment` оставлен на `OVERHEAD_ADMIN_BANK` (в эталоне не смаплен, но код финансовой услуги однозначен).
- 21 код, оставшийся в эталоне без категории ОПиУ, получил правила (все с нулевым объёмом затрат — заготовки каталога). `ozon_agency_fee` → `PRODUCT_INFRA_FF_SERVICES` по решению Владельца.
- **Wildberries: список был пустой (`cost_mappings: []`), стало 26 правил** — 17 стабильных кодов + 9 помесячных кодов утилизации.
- `product_processing` (заметная сумма выпадала из ОПиУ в эталоне) → `OPEX_WH_RECEIVING`: это `paidAcceptance`, платная приёмка (`WbProductProcessingCalculator:39`).

### Затронутые файлы
- `site/config/marketplace/default_cost_mapping.yaml` — modified (39 → 83 правила Ozon, 0 → 26 WB)
- `site/tests/Unit/Marketplace/Config/DefaultMappingConfigTest.php` — new
- `site/tests/Unit/Marketplace/Infrastructure/Provider/DefaultCostMappingYamlProviderTest.php` — modified (хардкод-таблица `NEW_OZON_COST_MAPPINGS`)
- `site/tests/Functional/Marketplace/Controller/CostPLMappingDefaultSetupControllerTest.php` — modified (та же таблица + список seed-категорий ОПиУ выводится из неё)
- `site/templates/marketplace/cost_pl_mapping/index.html.twig` — modified (починка preview, см. ниже)

### Побочная находка, исправленная в scope

Модалка «Базовый маппинг затрат» **никогда не показывала preview**: JS читал `payload.total` и `payload.groupedItems`, а контроллер отдаёт только `total`-less payload с `items`. Условие `(payload.total || 0) === 0` всегда истинно → всегда рендерился пустой стейт «нет правил». Починено в JS (группировка считается из `items`), без изменения контракта контроллера. Без этого правила WB из этого Stage остались бы невидимыми для пользователя.

### Self-review
- [x] Scope compliance
- [x] Patterns / naming
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR) — конфиг не содержит данных компаний
- [x] CS-Fixer / tests — green (baseline красный: 308 из 513 файлов в затронутых каталогах; новые файлы прогнаны фиксером)
- [x] ARCHITECTURE.md updated

### External review
- Совмещён со Stage 2 (общий дифф ветки), см. `stage-2.md`.

### Команды для проверки
- `make site-test-unit`
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --filter CostPLMappingDefaultSetupControllerTest`

### Риски / на что обратить внимание ревьюеру
- Помесячные коды утилизации WB (`…_za_a`, `…_za_m`, `…_za_i`) — обход усечения слага до 50 байт. Коллизии по построению (март/май, апрель/август, июнь/июль), но все ведут в одну статью, поэтому на результат ОПиУ не влияют.
- 20 правил помечены `confidence: medium`, 2 — `low`. Автонастройка их применяет; preview показывает уровень уверенности.

### Открытые вопросы
- нет
