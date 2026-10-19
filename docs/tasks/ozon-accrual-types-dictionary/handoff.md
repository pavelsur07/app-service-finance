# ozon-accrual-types-dictionary — handoff

**Branch:** `feat/ozon-accrual-types-dictionary` · **PR:** после push · **CI:** после push

## Summary of stages
- Stage 0 — разведка прода: справочник `accrual/types` у 4 кабинетов одинаков (12
  версий, 126 типов), группа есть только в xlsx кабинета, связка xlsx ↔ by-day — по
  дате, SKU и сумме (`stages/stage-0.md`).
- Stage 1 — справочник в таблице `ingest_ozon_accrual_types`: `SyncOzonAccrualTypesAction`
  после нормализации сырой записи `accrual_types`, команда
  `app:ingestion:ozon-accrual:sync-types`.
- Stage 2 — тип вне каталога получает статью из справочника (`ozon_` + snake_case
  `name`), всегда `known = false` и запасной тип транзакции: ключ транзакции не меняется.
- Stage 3 — закрытый список групп `ingest_category_groups` и правила подсказки
  `ingest_category_group_rules` (сид: 10 групп, 7 правил).
- Stage 3b — точная группа Ozon из отчёта «Детализация начислений»:
  `ImportOzonAccrualDetailsAction`, таблица `ingest_ozon_accrual_type_groups`, споры групп.
- Stage 4 — админка `/admin/ingestion/external-categories`: очередь «Новые типы Ozon»,
  «Подтвердить», споры, загрузка отчёта; `OzonAccrualTypesAdminFacadeInterface`;
  группа маппинга только из закрытого списка, актор обязателен.
- Stage 5 — ночной `daily-maintenance`: метрика `newOzonTypesAwaitingConfirmation`,
  WARNING после 3 дней, ERROR после 14; runbook §10a.

## Files changed
- Ingestion: Entity `OzonAccrualType`, `CategoryGroup`, `CategoryGroupRule`,
  `OzonAccrualTypeGroup`; Action `SyncOzonAccrualTypes`, `ImportOzonAccrualDetails`,
  `ConfirmOzonAccrualType`, `ResolveOzonAccrualTypeGroupDispute`; изменены
  `UpdateExternalCategoryMappingAction`, `OzonAccrualCategoryTaxonomyResolver`,
  `NormalizeRawRecordHandler`, `OzonAccrualDailyMaintenanceCommand`; Facade
  `OzonAccrualTypesAdminFacade(Interface)`; Query, репозитории, читатель xlsx, 2 команды.
- Admin: `IngestionExternalCategoriesController`, шаблон страницы.
- `site/migrations/Version20261018140000`, `…160000`, `…180000`; `phpstan.dist.neon`
  (scanFiles), `phpstan-baseline.neon` (2 → 1), `DbReset`.
- Документация: `ARCHITECTURE.md` (Facade, Entity, Changelog 2.22–2.27),
  `docs/ingestion/ozon-mapping.md`, `docs/ingestion/runbook.md` §10a,
  `docs/workflow/health-gates.md`.

Marketplace и ОПиУ не менялись (ограничение Владельца).

## Migrations
Все три создают только новые таблицы и сид с фиксированными UUID; существующие таблицы
не трогают. **Обратимы** (`down()` — DROP), бэкап не нужен. Проверено up → down → up ×2
на локальной БД.
- `Version20261018140000` — `ingest_ozon_accrual_types`.
- `Version20261018160000` — `ingest_category_groups` (10) и `ingest_category_group_rules` (7).
- `Version20261018180000` — `ingest_ozon_accrual_type_groups`.

**Порядок обязателен: миграции — до деплоя.** Старый код новые таблицы не читает, а
новый код без таблиц положит нормализацию by-day: резолвер читает их на каждой
сырой записи.

## Rollout (после одобрения)
1. Миграции dispatch → деплой.
2. Первичное заполнение справочника (запись — через Владельца):
   `app:ingestion:ozon-accrual:sync-types --dry-run`, затем без `--dry-run`. Само оно
   заполнится только при новой версии справочника у Ozon: одинаковая версия
   дедуплицируется по hash.
3. Группы Ozon: 4 отчёта «Детализация начислений» кабинета 3f4d87cc — в админке, блок
   загрузки, сначала Dry-run, затем загрузка (через Владельца: файлы с данными кабинета).
4. Сверка: `daily-maintenance --dry-run` (метрика очереди, отсутствие orphaned),
   `RebuildMarketplaceCategoryIdentitiesAction --dry-run` — строки с меткой из
   справочника теперь удерживают категорию `type:N` от deprecate; ожидаемо, проверить
   число.

## Public API / contract changes
- Новый Facade `OzonAccrualTypesAdminFacadeInterface` (`ARCHITECTURE.md`, раздел Ingestion).
- `UpdateExternalCategoryMappingAction`: группа — только из `ingest_category_groups`,
  «Требует классификации» запрещена при `known = true`; в админке ручная правка требует
  актора. Прод-маппинг с группой «Другие услуги и⏎штрафы» нормализуется при следующем
  сохранении.

## Checks
- `php-cs-fixer` (весь проект, без кеша) — 0 из 3155; strict_types — 0 из 3155
- PHPStan level 8 (весь проект, baseline) — No errors; baseline сократился (2 → 1)
- регрессии внешнего ревью проверены красными на старом коде (stash исправления)
- полный `phpunit` — OK на `7274c8114` (6791 тест, 38 722 проверки); на финальном
  коммите `a763d74c9` — OK, 6793 теста, 38 728 проверок
- миграции up → down → up ×2 — OK

## Reviews
- internal по этапам: итоги в `stages/stage-1.md` … `stage-4.md` (в т. ч. BLOCKER Stage 4
  — тип транзакции по scope вместо MODE; Stage 3b — споры вместо перезаписи
  подтверждённой группы).
- internal финальное (свежий агент): 0 BLOCKER / 4 IMPORTANT — все исправлены:
  сигнал health-check не доходил до человека (cron с `--quiet`) → ERROR после 14 дней;
  двойной скан и N+1 очереди → один расчёт, категории одним запросом; Builder'ы
  `CategoryGroup`/`CategoryGroupRule`; stage-5 закрыт. MINOR исправлены: сигнатура
  Facade и группа статьи из справочника в документации; повторное подтверждение той же
  группы больше не снимает открытый спор; ограничение «код меняется при переименовании
  неподтверждённого типа» записано в plan.md; runbook про тип «ждёт refresh» старше окна.
- external (Codex): 6 раундов, BLOCKER 0 / IMPORTANT 8, все исправлены, регрессии красные
  на старом коде:
  1. продажа без `type_id` на том же ключе (дата, SKU, сумма) голосовала за тип услуги →
     такой ключ неоднозначен;
  2. «Принять новую» по устаревшему спору → решение несёт показанную пару групп,
     проверка под блокировкой импорта;
  3. справочник A → B → A у одной компании не восстанавливался → время версии —
     `last_seen_at`, загрузчик отдаёт DONE-запись справочника на синхронизацию;
  4. код/название из справочника длиннее колонок маппинга (HTTP 500 при подтверждении) →
     усечение с хэшем, проверка длины;
  5. кэш UnitOfWork скрывал запись другого воркера под блокировкой → `HINT_REFRESH`;
  6. маппинг `known = false` выдавал тип за «ждёт refresh» и убирал из мониторинга;
  7. погашенные (`_ingestion_voided`) строки в очереди и в проверке типа операции;
  8. override компании `known = false` — то же, что 6 (исправлено без повторного раунда).
- метрики: внутренние BLOCKER 1 (Stage 4) / IMPORTANT — по этапам и 4 в финальном;
  внешние BLOCKER 0 / IMPORTANT 8.

## Risks, limitations, follow-ups
- Подтверждённую группу Ozon ставит отчёт одной компании (≥2 однозначных голоса, ≥80%);
  она перекрывает группу каталога у известных типов глобально. Меняется только группа,
  не код и не тип. Отзыв — SQL; FOLLOW-UP: блок «группы Ozon, расходящиеся с каталогом».
- `withGroup()` оставляет `parentLabel` каталога (подгруппа может оказаться под другой
  группой Ozon) — FOLLOW-UP.
- `NormalizeRawRecordHandler`: сбой синхронизации справочника пишет `error` и
  пробрасывает исключение — как соседняя ветка нормализации; ретраи дадут повторные
  события. Не меняли ради единообразия.
- Импорт из админки держит до 300 тыс. строк в памяти; при упоре в `memory_limit`
  php-fpm — консольная команда.
- FOLLOW-UP: тип 17 Drop-Off Agent размечен в каталоге не той группой (Stage 0);
  Marketplace/ОПиУ для новых услуг (`OzonCostCategory`); частичный индекс для очереди
  (скан `ingest_financial_transactions` на открытии страницы и в ночном прогоне);
  perf refresh (`docs/tasks/ozon-accrual-refresh-perf/plan.md`); `type = other` (#2440).

## Owner decision
Ready: PR — 3 миграции (новые таблицы и сид, обратимы, бэкап не нужен; **до деплоя**) +
деплой; затем `sync-types` и загрузка отчётов 3f4d87cc через Владельца.
Reply: "run the migration and deploy #N"
