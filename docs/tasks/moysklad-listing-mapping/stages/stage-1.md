### Stage 1: DTO-контракт листингов — DONE

**Risk:** MEDIUM
**Stage base commit:** `e0b5ac0d276ccb58d38c069722a2fad90358edda`
**Work items:** 1.1, 1.2, 1.3

#### What was done
- Существующий MarketplaceFacade отдаёт bounded ListingMetaPageDTO всех листингов,
  включая неактивные и связанные с Catalog, с tenant/provider/search фильтрами.
- ListingMetaDTO включает isActive/marketplaceVariantId; размер нормализуется
  общей ListingMetaQuery. Различные размеры WB сохраняют отдельные UUID.
- Старая пагинация и семантика Catalog не изменены.

#### Files changed
- Marketplace Facade, ListingMetaDTO/PageDTO, ListingMetaQuery и FacadeTest.
- ARCHITECTURE.md; TASK/plan/checkpoint текущей реализации.

#### Definition of Done
- [x] Только read API и DTO на границе модулей.
- [x] companyId/provider, literal search, invalid filters, stable pages и inactive.
- [x] Страница за концом пуста, totalItems сохранён.
- [x] Совместимость существующих DTO consumers проверена.

#### Checks
- Baseline unit: 4807 tests / 26743 assertions, PASS.
- Baseline scoped integration: 112 tests / 514 assertions, PASS.
- RED: 8 новых cases, отсутствующий метод Facade (3 errors / 5 failures).
- GREEN Stage: Facade/query + UnitExtendedQuery — 64 tests / 293 assertions, PASS.
- Focused PHPStan — no errors. Project CS fixer только 5 task files, PASS.
- git diff --check — PASS.
- Окружение: изолированный Compose vf-moysklad-mapping, пустая test DB,
  существующие 318 миграций применены, общие dev/production данные не использованы.

#### Internal review
- Полный Stage diff проверен по checklist; BLOCKER/IMPORTANT: 0/0.
- Nullable/boolean/variant metadata, tenant constraints, ordering и обратная
  совместимость проверены; новых financial/auth/write paths нет.

#### External review
- required: no — MEDIUM Stage; будет на handoff реализации.

#### Decisions
- Для out-of-range Facade сначала получает page=1/count и проверяет nbPages,
  вместо исключения после недопустимого page; результат контракта тот же,
  без повторного count после исключения.
- Шаблон этапов AGENTS/checkpoint используется вместо skill task scripts,
  рассчитанных на заголовки Task, отсутствующие в принятом Stage-плане.

#### Next
- Автоматически Stage 2: схема ревизий и tenant storage tests.
