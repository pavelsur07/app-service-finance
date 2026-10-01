# marketplace-ozon-reconciliation: вкладка «Сверка с Ozon»

Baseline: см. checkpoint.md

## Факты Phase 0 (из кода)
- Продажи в учёте (`marketplace_sales.total_revenue`) и возвраты (`marketplace_returns.refund_amount`) лежат в базе
  `sale_amount` (цена продавца). «Реализация» (`marketplace_ozon_realizations`) — в базе `sale_price` (цена покупателя).
  Прямое R↔L дало бы системную разницу ×2.25, поэтому цепочка: R ↔ B(sale_price) и B(sale_amount) ↔ L.
- Сырой by-day — один `marketplace_raw_documents` на день (`api_endpoint = ozon::v1/finance/accrual/by-day`),
  `raw_data = {accruals:[…], service_types:{id:name}}`. Затраты — детерминированная функция сырья:
  комиссия `posting.products[].commission.commission.amount`, услуги `posting.products[].delivery.services[]`,
  `item_fees.fees[].fees[]`, `non_item_fee`; знак → `operation_type` (>0 storno, иначе charge); категория через
  `OzonAccrualServiceCategoryResolver`.
- Комиссии в `marketplace_ozon_realizations` нет → «Реализация» покрывает только продажи и возвраты. Остальные блоки — только B↔L.
- Затраты, чей `raw_document_id` не указывает на by-day документ (легаси v3 до 09.09.2026), в сверку не входят и
  показываются отдельной информационной строкой.
- Прод-SQL для замеров «до» не запускался: классификатор auto mode режет `codex-psql-ro` без явного разрешения Владельца;
  фича аддитивная, существующие данные не меняются.

## Блоки (по группам xlsx Ozon, `OzonCostCategory::$xlsxGroup`)
SALES, RETURNS, COMMISSION («Вознаграждение Ozon»), LOGISTICS («Услуги доставки»), FBO_SERVICES, PARTNER_SERVICES,
ADVERTISING, COMPENSATIONS, OTHER_FEES, UNRECOGNIZED (код вне справочника).

## Проверки
- `REALIZATION_VS_RAW` — SALES, RETURNS в базе sale_price.
- `RAW_VS_LEDGER` — SALES, RETURNS (база sale_amount) и все блоки затрат по категориям.
Допуск: |signed delta| ≤ 1.00 ₽ → `within_tolerance` (0 → `matched`), иначе `mismatch`; нет источника → `no_data`.

## Stage 1: домен и хранилище снимков
Risk: HIGH-LOCAL (миграция)
Definition of Done:
- Enum блоков/проверок/статусов; правило «код категории → блок» поверх `OzonCostCategory`; политика допуска на `Money`.
- Entity `OzonReconciliationRun` + `OzonReconciliationLine`, репозитории с `companyId`, миграция (аддитивная, индексы), Builder.
- Unit-тесты политики и маппинга (все категории попадают ровно в один блок), `ARCHITECTURE.md`.
Work items: 1.1 enum+маппинг; 1.2 политика допуска; 1.3 Entity/Repository/Builder; 1.4 миграция+доки.
Stage checks: unit модуля, phpstan/cs изменённых файлов, миграция up/down на тестовой БД.
Reviewer focus: IDOR в репозитории, индексы, откат, ни одна категория не потеряна.

## Stage 2: источники данных (только чтение)
Risk: HIGH-LOCAL (финансовая семантика)
Definition of Done:
- Query «Реализация» (продажи, возвраты за месяц), Query «сырой by-day» (sale_price/sale_amount, затраты по категориям), Query «учёт».
- Все запросы с `companyId`, явные колонки, знак сохраняется, нет `float`.
- Integration-тесты: нормальный месяц, нет реализации, день без документа, сторно, легаси-затраты вне сверки, чужая компания.
Work items: 2.1 Query R; 2.2 Query B; 2.3 Query L; 2.4 integration-тесты.

## Stage 3: Action сверки и ручной запуск
Risk: MEDIUM
Definition of Done:
- `RunOzonReconciliationAction` собирает проверки, пишет снимок (идемпотентно по компании+периоду), `flush()` только в Action.
- Команда `app:marketplace:ozon-reconciliation:run`.
- Тесты: happy path, расхождение, `no_data`, идемпотентность, чужая компания.

## Stage 4: backend страницы
Risk: MEDIUM
Definition of Done:
- `GET /marketplace/ozon-reconciliation` (сводка), drill-down по блоку/категории с Pagerfanta, `POST` запуска (CSRF, `MARKETPLACE_WRITE`).
- Тесты доступа, IDOR, пагинации (limit>200 → 422).

## Stage 5: UI — вкладка «Сверка Ozon»
Risk: MEDIUM
Definition of Done:
- Вкладка в `marketplace/layout.html.twig`, страница с таблицей «блок × проверка», разница и статус видны без наведения,
  состояния loading/empty/error, мобильная ширина, UI Kit (`CLAUDE.frontend.md`).

## Stage 6: автоматизация и алерт
Risk: MEDIUM (cron)
Definition of Done:
- Ночной обход компаний (текущий и прошлый месяц), агрегированный ERROR при `mismatch`, запись в `health-gates.md`.
- Тест: компания без Ozon-подключения → пропуск без ошибки.
