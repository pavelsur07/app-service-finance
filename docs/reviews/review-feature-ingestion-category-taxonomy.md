# Проверенное ревью: `feature/ingestion-category-taxonomy` / PR #2042

**Дата:** 2026-06-25  
**Ветка:** `feature/ingestion-category-taxonomy`  
**PR:** #2042 `[codex] Add ingestion category taxonomy` -> `master`  
**Статус PR на момент первичной проверки:** открыт, draft, merge state `CLEAN`  
**Первичный объем diff:** 27 файлов, +2424 / -17

## Статус после исправлений

После первичного ревью в этой ветке исправлены блокирующие и P2-замечания:

- discover больше не создает duplicate taxonomy identity в одном запуске;
- dry-run refresh metadata не записывает unknown taxonomy categories;
- zero-amount fee строки не попадают в discovery queue;
- resolver использует bulk-cache active mappings на один preview run;
- legacy строки financial summary снова используют fallback на `description/type`;
- mapping-based категории сохраняют static `parentLabel`;
- Admin list показывает `new` категории перед `mapped` до применения `LIMIT`;
- failed refresh row очищает Doctrine UnitOfWork после rollback.

Покрытие добавлено в integration/unit checks, актуальный список проверок см. в финальном PR update/comment.

## Итог

Идея PR правильная: вынести категории затрат маркетплейсов в глобальную taxonomy Ingestion и дать Admin workflow вместо постоянной ручной правки кода и prod-команд.

Первичная версия требовала исправлений P1/P2 ниже. Эти findings оставлены в отчете как история ревью; актуальная ветка содержит фиксы по ним.

Проверка была review-only: код не менял, тесты не запускал.

## Findings

### P1: Discover может создать дубликаты `ExternalCategory` в одном запуске

**Файлы:**

- `site/src/Ingestion/Application/Action/DiscoverExternalCategoriesAction.php:48`
- `site/src/Ingestion/Application/Action/DiscoverExternalCategoriesAction.php:62`
- `site/src/Ingestion/Application/Action/DiscoverExternalCategoriesAction.php:69`
- `site/src/Ingestion/Application/Action/DiscoverExternalCategoriesAction.php:107`
- `site/migrations/Version20260625110000.php:27`

`DiscoverExternalCategoriesAction` группирует строки по `type_id`, `label`, `component`, но сохраняет категорию по более грубой identity:

```text
source + resource_type + scope + normalized_key
```

`scopeFromComponent()` схлопывает разные компоненты в один scope, например все `delivery:*` -> `delivery`. `normalized_key` для type_id будет одинаковым, например `type:32`.

Если в одном discover run попадут две строки с одинаковым type_id/scope, но разными label/component, обе могут пройти `findByIdentity()` как отсутствующие, потому что первая новая entity еще не `flush()`-нута. На финальном `flush()` сработает уникальный индекс `uniq_ingest_ext_category_identity`.

**Последствие:** Admin нажимает Discover, операция падает целиком, новые категории не появляются.

**Как исправить:** добавить in-memory cache по identity внутри `DiscoverExternalCategoriesAction`, аналогично `recordedUnknowns` в `OzonAccrualCategoryTaxonomyResolver`, или flush/clear по батчам с безопасной обработкой duplicate race.

### P1: Dry-run refresh metadata не является side-effect-free

**Файлы:**

- `site/src/Ingestion/Application/Action/RefreshOzonAccrualCategoryMetadataAction.php:147`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualByDayMapper.php:45`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategoryTaxonomyResolver.php:201`

В `RefreshOzonAccrualCategoryMetadataAction::refresh()` флаг `dryRun` защищает транзакцию, обновление транзакций и `flush()`. Но в dry-run все равно вызывается:

```php
$mappedTransactions = $this->mapper->map($rawRecord, $rows);
```

А `OzonAccrualByDayMapper::map()` вызывает preview с `recordUnknownCategories: true`. Это может привести к `entityManager->persist($category)` в resolver.

Да, сам `RefreshOzonAccrualCategoryMetadataAction` в dry-run не вызывает `flush()`. Но новая entity остается в UnitOfWork. Любой последующий `flush()` в том же request/worker может записать эту категорию в БД.

**Последствие:** dry-run может неявно мутировать taxonomy.

**Как исправить:** прокинуть флаг записи unknown categories в mapper/preview и для dry-run передавать `false`. Дополнительно можно сделать dedicated preview path без side effects.

### P2: `FinancialSummaryQuery` схлопывает legacy-строки без category label

**Файл:** `site/src/Ingestion/Infrastructure/Query/FinancialSummaryQuery.php:171`

Сейчас `category_name` считается так:

```sql
COALESCE(NULLIF(ft.source_data->>'_ozon_category_label', ''), 'Неклассифицированная категория Ozon')
```

До изменения fallback был более детальный: `description` / `type`. Поэтому старые транзакции, где еще нет `_ozon_category_label`, теперь попадают в одну строку UI-отчета.

**Последствие:** в финансовой сводке может исчезнуть детализация по старым accrual-транзакциям до refresh metadata. Это как раз похоже на проблему, которую видели в UI: много технических или обобщенных строк.

**Как исправить:** оставить новый taxonomy label для новых/обновленных строк, но для legacy-строк без label использовать безопасный fallback, который не схлопывает все в одну строку. Например:

```sql
COALESCE(NULLIF(_ozon_category_label, ''), NULLIF(ft.description, ''), ft.type)
```

Либо отдельно фильтровать только технические `Ozon accrual ...` descriptions и заменять их на понятный taxonomy fallback.

### P2: Zero-amount fee строки записывают unknown categories

**Файлы:**

- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php:267`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php:275`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php:436`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php:444`

В `collectDeliveryServices()` и `collectTypedFee()` категория резолвится до проверки суммы на `0`.

**Последствие:** Ozon может прислать неизвестный type_id с `accrued = 0`. Транзакция не создается, но неизвестная категория попадает в taxonomy/admin queue. Это добавит шум без финансового эффекта.

**Как исправить:** сначала посчитать amount и выйти при `0`, потом резолвить/записывать категорию.

### P2: Resolver делает N+1 запросы на hot path нормализации

**Файлы:**

- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategoryTaxonomyResolver.php:54`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategoryTaxonomyResolver.php:60`
- `site/src/Ingestion/Repository/ExternalCategoryMappingRepository.php:44`

Для каждой typed fee строки resolver проверяет mapping по нескольким candidate keys:

- scope + `type:<id>`
- `any` + `type:<id>`
- scope + `name:<name>`
- `any` + `name:<name>`

Каждая проверка идет через repository query. На raw record с тысячами fee строк это может дать тысячи SQL-запросов только на маппинг категорий.

**Последствие:** нормализация accrual by-day станет тяжелее и может просесть на больших периодах.

**Как исправить:** загрузить active mappings для `(source=ozon, resource=accrual_by_day)` один раз в in-memory map и резолвить по ключу без запроса на каждую строку. Cache должен быть request/message-scoped или invalidation-aware, чтобы не повторить прошлую проблему со stale resolver dictionary.

### P3: `forField()` и seed не покрывают `SCOPE_FIELD`

**Файлы:**

- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategoryTaxonomyResolver.php:37`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategoryTaxonomyResolver.php:44`
- `site/src/Ingestion/Application/Action/SeedExternalCategoryMappingsAction.php:44`
- `site/src/Ingestion/Application/Action/SeedExternalCategoryMappingsAction.php:98`

`forField()` принимает `recordUnknown`, но не использует его. Seed action создает только `SCOPE_ANY` identities с `type:` / `name:` keys, но не создает `SCOPE_FIELD` / `field:` mappings.

**Последствие:** admin override через taxonomy работает для typed fee, но не работает из коробки для field-derived категорий (`sale_amount`, `commission`, `bonus`). Сейчас они фактически остаются на static fallback.

**Как исправить:** либо убрать неиспользуемый `recordUnknown` и явно документировать, что field categories статические, либо добавить seed/discovery для `SCOPE_FIELD`.

### P3: Non-active mapping semantics надо явно определить

**Файлы:**

- `site/src/Ingestion/Application/Action/DiscoverExternalCategoriesAction.php:86`
- `site/src/Ingestion/Application/Action/DiscoverExternalCategoriesAction.php:87`
- `site/src/Ingestion/Repository/ExternalCategoryMappingRepository.php:35`
- `site/src/Ingestion/Repository/ExternalCategoryMappingRepository.php:44`

Исходное ревью утверждало, что категория "застревает навсегда", если есть non-active mapping. Я это понижаю: в Admin есть update mapping action, который может поменять статус существующего mapping.

Но поведение все равно нужно зафиксировать. Discover использует `findByCategory()` без фильтра по статусу и поэтому не auto-create-ит ACTIVE mapping, если уже есть `DISABLED` или `NEEDS_REVIEW`. Runtime resolver читает только ACTIVE mappings через `findActiveByIdentity()`.

**Последствие:** если это намеренно, Admin UI/status должен показывать такие категории как требующие ручного решения. Если не намеренно, discover будет считать категорию обработанной, а runtime продолжит видеть ее как unmapped.

**Как исправить:** выбрать контракт:

- non-active mapping блокирует auto-map намеренно: добавить явный статус/счетчик в Admin и не считать как auto-mapped;
- non-active mapping не должен блокировать auto-map: использовать `findActiveByCategory()` или переактивировать mapping по static default.

### P3: Дублируются SQL predicate и normalization logic

**Файлы:**

- `site/src/Ingestion/Application/Action/DiscoverExternalCategoriesAction.php:129`
- `site/src/Ingestion/Infrastructure/Query/ExternalCategoryAdminQuery.php:106`
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategoryTaxonomyResolver.php:97`

Predicate "unclassified Ozon accrual" продублирован в discover action и admin query. Он завязан на человекочитаемые строки:

- `Неизвестные категории Ozon`
- `Требует классификации`
- `Без группы Ozon`
- `LIKE 'Неизвест%'`
- `LIKE 'Ozon accrual%'`

Также normalization key logic частично живет в resolver и рядом со static category logic.

**Последствие:** при следующем переименовании группы или изменении формата description один экран может считать строку unclassified, а другой уже нет.

**Как исправить:** вынести predicate в один query helper/specification или, лучше, опираться на стабильные поля (`_ozon_category_known`, `_ingestion_resource`, `_ingestion_type_id`) вместо текстовых labels.

## Refuted / adjusted

- **"Non-active mapping stuck forever"**: частично подтверждено, но формулировка завышена. Admin может изменить существующий mapping. Проблема не в невозможности исправить, а в неявном контракте между discover и runtime resolver.
- **`known` checkbox parsing**: не подтверждено. Controller сравнивает с `'1'`, template передает `value="1"`, это корректно.
- **Control sum path records unknown categories**: не подтверждено для текущего кода. `controlSumForRawRecord()` вызывает preview без `recordUnknownCategories: true`.

## Рекомендуемый порядок исправлений

1. Исправить duplicate identity в `DiscoverExternalCategoriesAction`.
2. Сделать dry-run refresh действительно read-only.
3. Вернуть безопасный fallback в `FinancialSummaryQuery`, чтобы legacy rows не схлопывались.
4. Перенести zero-amount check до category resolve.
5. Добавить request/message-scoped cache активных mappings в resolver.
6. Решить контракт для `SCOPE_FIELD` и non-active mappings.
7. Вынести unclassified predicate в одно место.

## Проверки ревью

Выполнено:

- `pwd && git status --short && git branch --show-current`
- `git diff --stat master...HEAD`
- `gh pr view 2042 --json number,title,state,isDraft,headRefName,baseRefName,mergeStateStatus,url`
- ручная проверка релевантных файлов с line numbers

Не выполнялось:

- unit/integration tests, потому что задача была review report без изменения production code.
