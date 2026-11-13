# Cash context refactor — handoff

**Branch:** refactor/cash-contexts · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2978 · **Base:** master (task base1f874580f).

## Результат

- Stage1: удалены неиспользуемый CashTransactionExternalIdGenerator, createOperationFromTransaction и test-only wrapper findMatchingRule; проверки wrapper сохранены через match()->rule.
- Stage2:73 механических переноса Action/service по контекстам; AddCashTransactionAction сохранён для protected consumer.
- Stage3: файловый импорт разделён на storage, reader, normalized rows, duplicate planner и coordinator. Coordinator711→409LOC.
- Stage4: автоправила разделены на Matcher, ApplicationPlanner, PreviewBuilder и coordinator807→132LOC (798 после Stage1). Один путь решений для preview/apply; batch snapshots сохранены.
- Всего80 логических переносов: [moves.json](moves.json). Обновлены Cash README и размещение классов в ARCHITECTURE.

Импорт1С, его tests/fixtures, общие защищённые зависимости, MappingValidator/Page и балансовые методы неизменны. Нет новых migrations, dependencies, DTO/Facade API, message payload/routing, UI/route/CI-config изменений. Границы транзакций, locks/events/commit callbacks и финансовые формулы сохранены.

## Проверки

| Проверка | Итог |
|---|---|
| make site-stan (финальный serial) | No errors, exit0,4063 файла |
| make site-cs-check | exit0,0/4099 |
| make site-cs-strict-types | exit0,0/4099 |
| make site-test-unit | OK4656 tests/25723 assertions |
| phpunit --testsuite integration | exit0,OK2768/13202 |
| phpunit --testsuite functional | полный итог OK1403/10437; shell metadata потеряна при daemon restart |
| Полный набор | все8827 тестов,49362 assertions по трём suite |
| Baseline guard | NEW0/GREW0, occurrences3260→3247 |
| Protected-path check | пустой diff, новых файлов внутри защиты нет |

Единый `make site-test` завершился137 после7300+ тестов без assertion failure в выводе; причина SIGKILL не подтверждена. Полный набор завершён раздельно в последовательных PHP-процессах без исключения тестов. Начальные ошибки static/style и их исправления — [gate-corrections.md](gate-corrections.md); матрица A–E — [acceptance-results.md](acceptance-results.md).

## Review

- Stage2 fresh internal0 BLOCKER/IMPORTANT/MINOR.
- Stage3 fresh internal0B/0I/1M fixed; external round1 REVIEW_GREEN,0B/0I/4M fixed without re-run.
- Final internal в свежей сессии только spec + full diff + checklist:0B/0I/0M. Повтор scoped gate fixes:0B/0I/0M, семантика сохранена.
- Combined Stage4/handoff external round1 REVIEW_GREEN:0B/0I, executable-bit MINOR fixed; normalizedRows deletion rejected по FR4 (сигнатура явно сохраняется ТЗ).
- Review metrics implementation: internal BLOCKER0/IMPORTANT0; external BLOCKER0/IMPORTANT0,2 rounds total, открытых0. Safe MINOR исправлены, одно предложение вне согласованного scope отклонено с причиной.

## Ограничения и FOLLOW-UP

1С не рефакторился. Test-only методы балансов, согласование двух legacy CSV-алгоритмов, upsert/idempotency изменения AddAction/Facade, AI recovery и дробление атомарного перевода исключены. normalizedRows оставлен как согласованная совместимая сигнатура. Reader271, file coordinator409, Matcher267 и Planner253LOC имеют пояснённые мягкие size exceptions. Существующий baseline долг других модулей не исправлялся в их исходниках.

## Delivery

Локальная реализация и приёмка завершены. GitHub checks соответствующего head проверяются в PR после push; локальные результаты не объявляются их заменой. Production merge/deploy этой реализации ещё не выполнялся; approval #2977 использован только для документации.

Ready: PR #2978 "refactor(cash): organize contexts without changing 1C import" — merge into master with automatic production deploy?
Reply: "merge and deploy #2978"
