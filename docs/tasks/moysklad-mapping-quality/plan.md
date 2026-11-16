# moysklad-mapping-quality: технический план и приёмка

Спецификация: [TASK.md](TASK.md), утверждённый владельцем план. Class: Large.
База: origin/master `7423a1562`. Ветка: `feat/moysklad-mapping-quality`.
Baseline unit: `docker exec vf-mapping-quality-php php bin/phpunit tests/Unit/MoySklad` — 222 tests, 727 assertions, OK.
Изолированное тестовое окружение: контейнер vf-mapping-quality-php, отдельная БД mapping_quality_test; production не используется.

## Stage 1: хранение и импорт
Risk: HIGH-LOCAL. stage_base_commit: 7423a1562.
DoD / приёмка:
- [ ] Строковые штрихкоды товаров/модификаций: null = не загружены, [] = загружены пустые; повтор импорта идемпотентен.
- [ ] Scoped batch MarketplaceFacade по <=100 ID сохраняет все штрихкоды и дубли между листингами.
- [ ] Аддитивная миграция: настройка WB, отдельные подтверждения, отчёты/строки диагностики; существующие связи сохраняются.
Work items: 1.1 тесты/импорт штрихкодов; 1.2 batch facade и scoped storage.
Checks: unit MoySklad; integration SyncCatalogAction, storage/facade.
Review focus: company/connection/marketplace scope, leading zeros, schema consistency.

## Stage 2: правила и защищённая запись
Risk: HIGH-LOCAL. stage_base_commit: d66582791.
DoD / приёмка:
- [ ] Объединение точного артикула/родителя+размера/кода/алиаса/штрихкода; разные кандидаты блокируют автомаппинг.
- [ ] WB: удаление одного конечного W/w только при включении scoped пресета.
- [ ] Количества шт/шт./штук/штуки/штука; неизвестные не домысливаются, неоднозначные и разные запрещают автомаппинг.
- [ ] Ручная спорная связь требует подтверждения с ревизией и отпечатками; изменения инвалидируют его.
- [ ] Writer повторно сверяет настройки, источники и кандидатов; CAS/idempotency/manual-clear сохраняются.
Work items: 2.1 policy RED→GREEN; 2.2 preview/writer/confirmations.
Checks: unit policy; integration preview/writer/concurrency.
Review focus: конфликты правил, размеры, архив, stale preview/confirmation, lock order.

## Stage 3: диагностика всего кабинета
Risk: HIGH-LOCAL. stage_base_commit: 58c4f2c65.
DoD / приёмка:
- [ ] async_pipeline, <=100 листингов в пакете; общая policy с preview.
- [ ] Durable progress/counters/rows/time/settings fingerprint; last completed сохраняется при запуске/сбое.
- [ ] Retry без дублей и ложного completed; изменения источников/настроек/назначений делают отчёт stale.
Work items: 3.1 lifecycle storage/message/handler; 3.2 общий evaluator и query фильтров.
Checks: integration batching/retry/failure/stale/scoping.
Review focus: transactions, queue delivery, snapshot invalidation, bounded memory.

## Stage 4: legacy UI
Risk: HIGH-LOCAL (существующая legacy UI). stage_base_commit: 8372aa8f9.
DoD / приёмка:
- [ ] Сводка/фильтры состояния и ручного разбора, основания/сравниваемые значения/конкуренты.
- [ ] WB preset, refresh diagnosis, ручное подтверждение; CSRF и права.
- [ ] Empty/running/stale/error states, время проверки; preview/apply по 25.
Work items: 4.1 HTTP/form tests; 4.2 Twig/Tabler и контроллеры.
Checks: functional MoySklad, twigcs/lint:twig.
Review focus: company isolation, CSRF, errors/empty/responsive, no implicit write from diagnosis.

## Stage 5: проверка и поставка
Risk: HIGH-LOCAL. stage_base_commit: перед началом.
DoD / приёмка:
- [ ] Полные гейты stan/cs/strict-types/unit/integration/functional/Twig.
- [ ] Независимое внутреннее review полного diff и внешнее Claude CLI (fallback по правилам проекта).
- [ ] Один PR base master; handoff с миграцией; Ready; merge/deploy только после отдельного согласования владельцем.
Work items: 5.1 gates/reviews/fixes; 5.2 handoff/PR ready.
Review focus: все обязательные регрессии TASK.md; числа 35/45/26 — оценки, не обещание покрытия.

## Решения интеграции
- Stage 1 → 2: nullable barcode arrays; batch listingId→list<string>, а не barcode→single listing.
- Stage 2 → 3/4: общий evaluator выдаёт причины и кандидатов; диагностика не авторизует запись.
- Stage 2 → writer: fingerprint включает значимые исходные данные и настройки; повторная проверка внутри блокировок.
- Утверждённый план не переоткрывается; workflow документов/checkpoint из AGENTS имеет приоритет над временными ledger-скриптами навыка.
