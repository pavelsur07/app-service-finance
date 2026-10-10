# cost-pl-mapping-autosync: базовый маппинг затрат в ОПиУ поддерживается сам

Бриф Владельца (чат 10.10.2026): новые категории затрат появляются регулярно,
маппинг на статьи ОПиУ по шаблону должен поддерживаться постоянно, без ручного
запуска. Согласованная схема:

1. Ночной прогон базового маппинга по всем активным SELLER-подключениям до
   пересборки предварительного ОПиУ (04:45). Режим «частичный»: правила, для
   которых у компании есть статья ОПиУ, применяются; отсутствующая статья
   блокирует только своё правило, а не весь набор. Ручные и отключённые правила
   не трогаются (как сейчас).
2. Утренний read-only гейт «затраты вне ОПиУ»: затраты текущего и прошлого
   месяца без привязки к документу ОПиУ, у категории которых нет правила или
   правило без статьи. Один агрегированный `error`, ненулевой exit code.
3. Гвард «каждый код каталога есть в шаблоне» — уже существует
   (`DefaultMappingConfigTest::testEveryOzonCatalogCodeHasRule`,
   `testEveryWildberriesCatalogCodeHasRule`), в задачу не входит.

Кнопка UI и ручная консольная команда сохраняют прежнее «всё или ничего».

Baseline: `phpunit --filter 'DefaultCostMapping|DefaultMappingConfigTest'` —
OK, 27 tests, 508 assertions; test DB на последней миграции 20261009100000.

## Stage 1: частичное применение и ночной прогон
Risk: HIGH-LOCAL (запись правил маппинга ОПиУ, cron)
stage_base_commit: 07073d9a
Definition of Done:
- `ApplyDefaultCostMappingCommand` получает флаг `partial` (по умолчанию false);
  в частичном режиме MISSING_PL_CATEGORY / INVALID_TARGET_CATEGORY не бросают,
  а попадают в `blocked`; остальное — как раньше.
- Новая команда `app:marketplace:cost-pl-mapping:sync-default`: обходит
  ActiveSellerConnectionsQuery (ozon, wildberries), применяет частично, сбой
  одной компании не роняет остальные; LockableTrait; INFO старт/финиш со
  счётчиками; один агрегированный `error` + FAILURE при сбоях.
- Тесты: частичный режим (создаёт доступное, блокирует недоступное, не
  бросает); команда — happy path + изоляция ошибок/неподдерживаемый маркетплейс.
- Исключено: изменение шаблона, UI, существующих правил.
Work items:
- 1.1 — partial-режим в Action + тест
- 1.2 — команда sync-default + тест
Stage checks:
- phpunit --filter DefaultCostMapping, PHPStan по изменённым файлам, cs-check
Reviewer focus:
- ручные правила и `include_in_pl=false` не перезаписываются; идемпотентность;
  компания вне scope не затрагивается

## Stage 2: гейт «затраты вне ОПиУ», cron, документация
Risk: MEDIUM
Definition of Done:
- Команда `app:marketplace:cost-pl-mapping:unmapped-check` (read-only): окно с
  1-го числа прошлого месяца (МСК), только активные SELLER-подключения, только
  строки без `document_id`; Ozon — только распознанные коды
  (PreliminaryCostFilter, иначе гейт краснел бы на том, что маппинг не чинит);
  исключены правила с `include_in_pl=false`; печатает покрытие.
- Cron: sync-default 04:35 (после ads 04:30, до rebuild 04:45), гейт 06:50.
- ARCHITECTURE.md «Cron-задачи», docs/workflow/health-gates.md — пример гейта.
- Тесты: красный кейс, зелёные кейсы (правило есть / отключено / вне окна /
  неактивное подключение).
Work items:
- 2.1 — query + команда + тест
- 2.2 — cron + документация
Stage checks:
- phpunit по новым тестам, PHPStan, cs-check
Reviewer focus:
- охват гейта = охват починки (health-gates.md); один агрегированный error
