# Baseline подготовки — 2026-11-06

Исходный master: `cfb6728d2b95f4938c19996ef7d44214c9066648`.
Тесты выполнены в основном каталоге на `9b128fe069a210798afdf8ed7323665979551e61`; `git diff HEAD origin/master -- site` пустой, код приложения идентичен master. Production не использовался.

## Команда

```bash
docker compose run --rm --no-deps -e XDEBUG_MODE=off site-php-cli php bin/phpunit --filter 'CashFileRowNormalizerTest|CashFileImportIdempotencyTest|CashFileImportHandlerFlowTest|CashFileImportBatchBoundaryTest'
```

Первый зафиксированный результат: exit 2, 32 tests, 37 assertions, 21 errors. Причина: устаревшая тестовая схема, отсутствует `companies.business_profile`; интеграционные тесты падали при создании fixtures.

Исправление окружения без изменения кода:

```bash
docker compose up -d site-postgres site-redis
docker compose run --rm --no-deps -e XDEBUG_MODE=off site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Существующие миграции применены только к тестовой БД: 29 migrations, 173 SQL queries, exit 0, версия `Version20261106110000`.

После исправления та же тестовая команда: **exit 0, OK (32 tests, 116 assertions), 7.481 s**. PHP 8.4.27, PHPUnit 11.5.55.

## Регрессия — спецификация, ещё не исполняемый тест

1. Создать компанию и счёт через CashFileImportTestCase.
2. Импортировать одну строку: дата 2026-10-01, расход 100.00 RUB, документ 101, описание «Оплата по счёту».
3. Зафиксировать ID и полный снимок бизнес-полей, число операций, остатки и распределения.
4. Загрузить ту же строку с описанием «Оплата по счёту — уточнение».
5. Ожидать предложение обновления и отсутствие мутаций на preview.
6. Подтвердить; ожидать тот же ID, одну операцию, новый текст, прежний финансовый снимок, одну audit-запись и отсутствие автоправил.
7. Повторить задание и новый файл; затем старый файл без подтверждения обратной замены. Число операций и описание неизменны.

По чтению текущего makeDedupeHash/planExistingMatches изменённый текст меняет ключ; это объясняет риск новой записи. **Красный регрессионный тест не заявляется:** написание тестового кода отложено до реализации по ограничению «перед кодом». Первое действие Stage 1 после baseline — добавить и доказать красную регрессию.

Полные gates, внешнее ревью приложения и нагрузочные замеры не запускались: изменения только документальные. Существующий зелёный baseline не доказывает работу новой возможности.

## Stage 1 — красная регрессия, 2026-11-06

Временный диагностический `CashFileDescriptionDiagnosticTest::testLegacyPathMustNotDuplicateChangedDescription` использовал существующий `CashFileImportTestCase` и старый путь заданий без description_plan. Две загрузки одинаковой даты 01.10.2026 и суммы 100.00 с разными описаниями: ожидание 1 операция, фактически 2.

Команда внутри CLI-контейнера: `php bin/phpunit tests/Integration/Cash/Service/Import/File/CashFileDescriptionDiagnosticTest.php`. Результат: **FAILURES (1 test, 1 assertion, 1 failure), 0.620 s**. Диагностический файл удалён, постоянно падающий тест не коммитится. Наблюдаемая регрессия нового подтверждённого пути покрывается `CashFileDescriptionApplyTest`; его зелёный результат фиксируется после реализации.

## Stage 2 — зелёная регрессия и измерение плана

`CashFileDescriptionApplyTest`: 14 сценариев (выбранное обновление, неизменность финансового снимка и audit с автором, невыбранное, stale/deleted, повторы старого/нового файла, смешанный импорт, дополнительный одинаковый платёж через границу 200, испорченный fingerprint, повтор подтверждения, банк, восстановление после commit, rollback до commit и блокировка другим соединением). Вместе с четырьмя временными измерениями: **18 tests, 98 assertions, OK, 5.243 s**. Временный benchmark удалён после прогона.

Измерен `CashFileDescriptionPlan::build` на синтетических CSV с одним существующим кандидатом в БД; режим duplicate — весь вход содержит одинаковые платежи. Это измерение анализа/пакетной выборки, не нагрузочный тест 10 000 существующих кандидатов и не обещание SLA.

| Строк | Режим | Время build, ms | Peak процесса, MiB | SQL запросов build | Размер JSON, bytes |
|---|---|---|---|---|---|
| 1 000 | distinct | 29.12 | 68.50 | 1 | 361469 |
| 10 000 | distinct | 362.88 | 92.50 | 1 | 3638475 |
| 1 000 | duplicate | 31.53 | 90.50 | 1 | 359576 |
| 10 000 | duplicate | 316.38 | 96.50 | 1 | 3608580 |

Peak — память всего PHPUnit-процесса, не дельта выделения planner. SQL count получен из doctrine.debug_data_holder после reset перед build. Число запросов не зависит от числа строк; план в текущем варианте хранится целиком, память линейна размеру входа. В реализации не введён новый лимит файла.

Миграция `Version20261106120000`: на тестовой БД UP → DOWN → UP выполнены успешно, по одному SQL на шаг. Добавляется BOOLEAN default false; тексты, суммы и источники существующих операций не переписываются. Rollback удаляет только маркер защиты; выполнять после возврата совместимого кода.

Усиление приёмки: расширенный `CashFileDescriptionApplyTest` — **17 tests / 105 assertions, OK**; отдельно добавленный XLSX full-path — **1 test / 5 assertions, OK**. Финансовый снимок дополнен company/project/responsibility/category/VAT/transfer, splits, documents и operations, ежедневными остатками. В selected-update fixture реально созданы категория, split IMPORT, документ с операцией и allocated_amount=100.00; после усиления fixture targeted selected-update — **1 test / 17 assertions, OK**. Реальное правило UPDATE с условием по новому описанию подтверждено match()->hasWinners(); категория и количество сообщений транспорта при description-only не изменились. Также зелёные stale amount и подмена bytes исходного файла под прежним storage key: запись отклонена до изменения операции.

## Финальные проверки реализации

- `make site-test-unit`: 4468 tests, OK.
- `make site-stan`: 3723 файла, No errors. Удалены только 4 устаревшие suppression-записи (AdvertisingOtherMetrics3, MarketplaceListingBuilder1), baseline не увеличен.
- `make site-cs-check`, `make site-cs-strict-types`: 3759 файлов, 0 исправлений, exit0. Канонический `.php-cs-fixer.php` применён; ранние узкие запуски с dist-конфигом не заменяют эти gates.
- Первый full на старой app_test: 8227 / 46443, 4 failures. Два — один пропущенный runtime POST write gate (исправлен); два — старый schema drift (частичный Ingestion index и имя существующего FK), не относящийся к задаче.
- Создана отдельная app_test_cash_description, исходная app_test не пересоздавалась: 306 migrations / 1873 SQL, exit0. Новые параметры только во временном local Compose override, не в репозитории.
- Повтор семи schema/permission тестов на свежей БД: 7 / 39, OK. Повтор full: **8226 tests / 46454 assertions, OK**, 6m41.528s. Временные benchmark-тесты удалены; в отличие от первого full они не включены в последний набор.
- После небольших финальных изменений: Cash module **850 / 3345, OK**; дополнительная company/account/year изоляция **1 / 4, OK**; focused PHPStan основных изменённых файлов и Twig3 — exit0. Финальные счётчики/двухфазная загрузка контрагентов проверены отдельными регрессиями.
- Unicode-space regression RED: NBSP на краях ошибочно предлагал update, 1test/1failure; helper расширен на Unicode separator/whitespace только на краях, внутреннее форматирование не меняется. Последующий matcher suite GREEN (см. acceptance-results.md).

## Реалистичная нагрузка и ограничение памяти

10 000 существующих операций +10 000 строк; после fixtures EM очищен и входной fixture-array освобождён. Счётчик SQL не удерживает параметры/backtraces, используется отдельный test Kernel cache.

- Preview: 525.95ms, 2SQL (включая lazy account), UOW4, peak процесса226MiB.
- Whole handler после scalar projection и release нового графа: 11636.66ms, 101 lock queries (100пачек+job), UOW4, peak процесса350MiB. Peak включает подготовку fixture, не отдельный чистый HTTP baseline.
- До освобождения нового графа при том же scalar query: 63721.88ms, UOW10005. Изменение проверено постоянным UOW-bound тестом на201 строке.
- После этого замера найден/устранён N+1 контрагентов: в каждой пачке теперь отдельный lock-ID query и один joined read; самостоятельных CP SELECT нет (25CP regression red→green). Добавляется константный read на пачку, а не запрос на строку.
- Это локальный benchmark, не production SLA. Production данные/команды/API не использовались.

## Browser smoke

Actual local app +Chromium/Playwright в отдельном временном контейнере. Desktop1440×1000/mobile390×844; upload/mapping/preview, empty filter, invalid messages, long/HTML text, keyboard selection/count1, queued result. JS errors0/dialogs0. Screenshots в screenshots/. Fixtures и локальный server удалены/остановлены. Новых зависимостей приложения нет.

## Поздние регрессии и release review

- Relative date: approved/fresh/actual normalize могут оказаться в разных календарных днях. RED на actual-create, GREEN после строгого сравнения общего InputRow до entity/counterparty creation. Смена оригинальных bytes также проверяется до записи.
- Пустая строка при двух разных исходных описаниях и конкурирующем изменении: RED неверного назначения кандидата, GREEN обе перестановки; matcher18tests/93assertions.
-1024 Unicode limit: invalid row не обрезается, другая корректная строка применяется; targeted1test/15assertions GREEN.
- External handoff round3: точный REVIEW_GREEN; безопасные MINOR UUID/encoding в финальном исправлении. Review ledger — review-findings.md.
- Independently confirmed release BLOCKER: старый worker может импортировать новый description_plan как legacy. Подготовлен consumer-first CI patch, git apply --check GREEN; активный CI/CD не меняли без named approval по §3.3.

## CI parity и восстановление baseline

На commit107da28b1 CI PHPStan нашёл4 ошибки вне задачи: AdvertisingOtherMetrics (3), MarketplaceListingBuilder (1). Ранее удалённые как локально unmatched suppressions оказались нужными в чистом CI. Baseline восстановлен **точно как origin/master**, без роста; сторонний код не меняли. Локальный повтор дополнительно показал11 ignore.unmatched в других файлах после master integration; для проверки очищен только task-local PHPStan result cache и запущен новый анализ. Это исправление не меняет финансовое поведение. Ошибки и финальный результат не скрываются; новый CI run обязателен.
