# marketplace-pl-preview-by-tags: предпросмотр ОПиУ маркетплейсов по тегам товара

Постановка, решения Владельца и разрезы аналитики — `docs/plan/marketplace-pl-preview-by-tags.md`.
Класс задачи — Large (финансовая логика, межмодульный контракт, новый экран). Этапов семь, а не
2–5: так поручил Владелец («простые самостоятельные этапы»). Каждый этап начинается с детального
плана в `stages/stage-N.md` (решения, конечный результат, чек-лист), в конце того же файла —
Stage Report. Этапы закрываются локальными коммитами, push и PR — одним шагом на handoff.

Baseline (2026-10-17, ветка от `4cb242719`):
`phpunit tests/Integration/Marketplace/{UnprocessedSalesQueryOzonMultiItemTest,CloseMonthStageActionMarkProcessedScopeTest,UnprocessedCostsQuerySymmetryTest}.php`
— OK, 26 tests / 95 assertions. Локальный стек `app-service-finance` остановлен; тесты идут на своей
паре `asf9-pg` / `asf9-redis` в сети `asf9-net`.

## Ключевое решение по знаку (из чтения кода, до этапов)

Реальный ОПиУ для `marketplace_pl` считается так (`FinanceFacade::createPLDocument` →
`PLRegisterUpdater`): сумма строки со знаком (`is_negative` → «−»), затем по статье складываются
суммы со знаком, от итога берётся модуль, природа статьи (`PLCategory::nature()`, flow) решает,
доход это или расход; факт статьи = доход − расход. Предпросмотр повторяет это правило в Finance
(этап 2), а Marketplace отдаёт суммы со знаком (этапы 3–5). Отличие: регистр берёт модуль в
корзине «день документа × проект», предпросмотр — за весь период по колонке; на одном месяце и
одном проекте результат совпадает.

## Stage 1: общие формулы сумм продаж и возвратов
Risk: MEDIUM (финансовые запросы проведения, поведение не меняется)
Подробно — `stages/stage-1.md`.

## Stage 2: расчёт дерева ОПиУ по колонкам (Finance Facade)
Risk: MEDIUM

## Stage 3: факты размеченных данных по листингам
Risk: MEDIUM

## Stage 4: не размечено, исключено, сверка
Risk: MEDIUM

## Stage 5: сборка отчёта по тегам
Risk: MEDIUM

## Stage 6: экран Twig
Risk: MEDIUM

## Stage 7: выгрузка xlsx
Risk: LOW

Handoff: полные гейты, финальное внутреннее ревью в свежей сессии, внешнее ревью Codex (Large),
`handoff.md`, push + PR, вопрос «merge and deploy».
