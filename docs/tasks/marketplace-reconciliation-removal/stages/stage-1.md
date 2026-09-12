# Stage 1 — удаление кода

- **Риск:** MEDIUM
- **stage_base_commit:** `260765fc`

## Definition of Done

Роутов `/marketplace/reconciliation` и `/api/marketplace/reconciliation/*` нет;
сайдбар рендерится без `RouteNotFoundException`; сборка фронта зелёная;
«Закрытие месяца» и ingestion-сверка не затронуты.

## Что удалено

9 контроллеров (страница + 8 API), `RunUserReconciliationAction`,
`ReconciliationSession`, `ReconciliationSessionRepository`,
`ReconciliationSessionStatus`, `SalesReturnsTotalQuery`,
`templates/marketplace/reconciliation.html.twig`, React-остров (9 файлов),
vite-entry `reconciliation_page`.

## Что осознанно оставлено

Каталог `src/Marketplace/Application/Reconciliation/` (общий парсер Ozon xlsx),
`CostReconciliationQuery`, `ReconciliationFileReadException`, `ReconcileCostsAction`,
`CostReconciliationController` — обслуживают `POST /marketplace/month-close/reconcile`.

## Правки, вызванные удалением

- `_sidebar_marketplace.html.twig`: убран пункт «Сверка» и условие
  `current_route != 'marketplace_reconciliation'` из `mp_charges_active`.
- `phpstan-baseline.neon`: удалено 7 записей (42 строки) на удалённые файлы —
  без этого `make site-stan` падает на unmatched ignored errors. Baseline только сжат.
- `ControllerAccessCoverageTest`: нижняя граница 180 → 160. Тест защищает от
  «тихого» зануления скана с запасом ~10%; фактических routed-контроллеров стало
  180 (было 189), прежняя граница 180 стала недостижимой. Запас сохранён.
- `ARCHITECTURE.md`: убраны строка Entity и блок enum.
- `docs/migration/legacy-quarantine-*.md`: страница помечена удалённой (аудиторские
  документы — записи не стираются, а отмечаются).

## Проверки

| Проверка | Результат |
|---|---|
| `make site-stan` | ✅ No errors (запуск напрямую через `vendor/bin/phpstan`: `make site-stan` упал OOM — exit 137, параллелизм на 2.2 ГБ свободной памяти) |
| `make site-cs-check` | ✅ 0 of 2506 |
| `make site-cs-strict-types` | ✅ 0 of 2506 |
| `make site-test-unit` | ✅ 2543 теста |
| `debug:router \| grep reconcil` | ✅ остались только `marketplace_month_close_reconcile`, `ingestion_verification_reconciliation`, `api_ingestion_verification_reconciliation` |
| `yarn build` | ✅ `reconciliation_page` исчез, `ingestion_verification_reconciliation_page` собран |
