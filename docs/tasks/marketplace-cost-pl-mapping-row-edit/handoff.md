# marketplace-cost-pl-mapping-row-edit — handoff

**Branch:** `refactor/marketplace-cost-pl-mapping-row-edit` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2516

## Summary
Страница маппинга затрат в ОПиУ: таблица только для чтения, строка правится в модалке
через JSON-эндпоинт; дерево ОПиУ одним запросом (ускоряет всех вызывающих).
Подробности — `stages/stage-1.md`.

## Migrations
- нет

## Public API / contract changes
- новый маршрут `POST /marketplace/cost-pl-mapping/{uuid}` (внутренний JSON для страницы)
- удалён `POST /marketplace/cost-pl-mapping/bulk-save`

## Checks
- `make site-test` OK (5256), `make site-stan` OK, `make site-cs-check` OK, `make site-cs-strict-types` OK

## Reviews
- internal: 1 итерация, IMPORTANT 1 исправлен
- external: 1 раунд, fixed without re-run; 1 IMPORTANT отклонён (файл вне PR)

## Risks, limitations, follow-ups
- страница остаётся на классах Tabler — перевод на UI Kit отдельной задачей
- пагинации нет (≤ ~110 строк на компанию)
- одновременное первое создание одного маппинга → unique violation → 500, редко
- у Marketplace нет ExceptionListener: исключения мапятся в контроллере, как в соседних Api-контроллерах
