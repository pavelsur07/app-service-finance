# Stage 1: домен и хранилище снимков

stage_base_commit: 89e0a8f9
Risk: HIGH-LOCAL (миграция)

## Планирование
Решения:
- Enum в `src/Marketplace/Enum/` по соседству: `OzonReconciliationBlock`, `OzonReconciliationCheck`, `OzonReconciliationStatus`.
- Правило «код категории → блок» — `Ozon/Domain/Reconciliation/OzonReconciliationBlockMap` поверх `OzonCostCategory::$xlsxGroup`
  и `recognizedCodes()`; код вне распознанных (в т.ч. `ozon_other_service`, `ozon_unknown_*`) → UNRECOGNIZED.
  Новый справочник не заводим, группа xlsx — единственный источник.
- Допуск — `OzonReconciliationTolerance` на `Money`: delta = target − source, знаковая; 0 → MATCHED; |delta| ≤ 1.00 → WITHIN_TOLERANCE; иначе MISMATCH; null на любой стороне → NO_DATA.
- Entity `OzonReconciliationRun` (один актуальный снимок на компанию+период, unique) и `OzonReconciliationLine`
  (суммы bigint minor + валюта на Run; `Money` — в домене и DTO). История прогонов — вне охвата (FOLLOW-UP).
- Репозитории: каждый метод с `string $companyId`; `flush()` нет.
- Миграция аддитивная: 2 таблицы + индексы; down — DROP.
Тесты: unit на политику (все ветки, границы 1.00/1.01, знак), на маппинг (каждый код каталога → ровно один блок, детерминированно), на Run/Line инварианты.
Вне охвата: Query, Action, UI.

## Work items
- 1.1 enum + BlockMap
- 1.2 Tolerance
- 1.3 Entity/Repository/Builder
- 1.4 миграция + ARCHITECTURE.md
