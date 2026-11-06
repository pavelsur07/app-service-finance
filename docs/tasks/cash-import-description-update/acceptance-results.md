# Результаты приёмки

Проверена реализация в ветке `feat/cash-import-description-update`, PR [#2894](https://github.com/pavelsur07/app-service-finance/pull/2894), база `cfb6728d2b95f4938c19996ef7d44214c9066648`. Только локальные синтетические данные; production не использовался. Критерии — [acceptance.md](acceptance.md), команды/диагностика — [baseline.md](baseline.md). Итоговые commit и CI — в PR; результаты ниже относятся к реализации, включая финальные исправления ревью.

## Сопоставление

| ID | Результат и доказательство |
|---|---|
| A01–A02 | PASS. `CashFileDescriptionMatcherTest` и `CashFileDescriptionApplyTest::testSelectedUpdatePreservesIdentityAndMoneyAndAuditsActorExactlyOnce`: create/unchanged/update, тот же ID, old/new. |
| A03–A04 | PASS. Matcher: пустой текст, Unicode-пробелы по краям, CRLF/CR/LF, регистр/пунктуация/внутреннее форматирование. Пустые строки при конкурирующем изменении и нескольких разных исходных текстах не расходуют случайного кандидата; обе перестановки проверены. |
| A05 | PASS. `testExtraIdenticalOperationIsCreatedOnceAcrossBatchBoundary`: количественное соответствие, законная дополнительная операция и повтор через границу 200. |
| A06–A08 | PASS. Matcher: неоднозначность, конфликтующие тексты, точные реквизиты перед wildcard, перестановки документов/контрагентов, отличающиеся финансовые поля. Ошибки назначения кандидата воспроизведены RED, исправления GREEN. |
| A09 | PASS. `testPreviewExcludesOtherCompanyAccountAndYear`. |
| A10 | PASS. Apply + `ClientBank1CDescriptionOverrideTest`: FILE/BANK/1C; `testDeletedCandidateBecomesConflictWithoutRestoration`. |
| A11 | PASS. `testWholeFileSelectionSurvivesPaging`, chunk201, XLSX-тест и CSV; opaque rowId основан на физической строке. UI страницы по50 (в пределах требования ≤200). |
| A12 | PASS. Проверки preview до apply: без новых операций/аудита; scalar SQL не загружает ORM-граф. Полный план публикуется одной immutable storage-записью. |

## Применение

| ID | Результат и доказательство |
|---|---|
| B01 | PASS. Полный populated financial snapshot в `testSelectedUpdatePreservesIdentityAndMoneyAndAuditsActorExactlyOnce`: splits, документы/операции, суммы, даты, связи, остатки, rawData/externalId/source; ровно один audit с инициатором. |
| B02 | PASS. `testUnselectedUpdateIsSkippedWithoutCreatingAnotherTransaction`; явный пустой выбор и смешанные новые строки. |
| B03–B04 | PASS. `testOldAndNewFileRepetitionDoNotCreateDuplicatesOrRevertText`: старый/новый файл не дублирует операцию, обратная замена требует нового выбора. |
| B05 | PASS. `testRepeatedConfirmationReturnsSameJobAndRejectsDifferentSelection` и HTTP repeated confirmation: canonical selection, один jobId, другой выбор не переиспользует согласие. |
| B06–B07 | PASS. Chunk201/repeated handler, rollback и `testSecondChunkFailureKeepsCommittedProgressAndResumesWithFreshManager`: 100 committed,101 неизменны, fresh EM resume →201 изменений и201 audit. |
| B08 | PASS. Stale description/amount/deleted тесты: чужая правка сохраняется, конфликт не становится create. |
| B09 | PASS для account advisory lock и SQL row lock двумя настоящими соединениями, плюс stale snapshot. `testLockedCommittedTransactionRejectsAnotherConnectionUpdateUntilRollback`: independently committed fixture, SQLSTATE55P03 при concurrent UPDATE, после rollback тот же UPDATE проходит; нет ложного ожидания uncommitted INSERT. `testDescriptionJobDefersWhileAnotherConnectionOwnsAccountLock` проверяет recoverable retry. Ручная правка до apply покрыта stale-тестом; HTTP-сценарий с двумя одновременно работающими браузерами отдельно не запускался. |
| B10 | PASS. Финансовый snapshot update-only и `testSelectedUpdateWithNewOperationCreatesOnlyNewOperation`; legacy batch/recalculate suites зелёные. |
| B11 | PASS. `testMatchingAutoRuleIsNotDispatchedOnDescriptionReplacement`, actor audit и rollback: реальное совпадающее правило не меняет категорию и не отправляет автокатегоризацию. |
| B12–B13 | PASS. Bank repeat и реальный 1С overwrite: сохранены подтверждённый текст, идентичность и исходные данные; число операций стабильно. Поведение банковской корректировки суммы не меняли. |
| B14 | PASS. Старые handler/idempotency/batch тесты в полном наборе; job без description_plan идёт прежним путём. Совместимость при rolling deploy имеет отдельное требование к порядку версий, см. handoff. |
| B15 | PASS. Concurrent creation, corrupt plan, source bytes replacement, relative date drift между preview/fresh-plan/фактическим create. В actual-create сравнивается тот же InputRow, который одобрен; изменившаяся нормализация не создаёт операцию. |

## UI и безопасность

| ID | Результат и доказательство |
|---|---|
| C01–C02 | PASS. HTTP + browser: old/new, реальные реквизиты существующей операции даже при пустом документе файла, источник, literal0, без предвыбора; выбор всего файла/между страницами, clear. |
| C03 | PASS. Применение одинакового профиля и сохранение одинакового mapping сбрасывает план/выбор/confirmation; изменение контекста связано fingerprint. |
| C04–C05 | PASS. Functional + existing permission gates: FINANCE_READ/WRITE, active company, scoped job/account/plan, foreign row, CSRF, экранирование HTML; браузер не исполнил script. UUID результата ограничен route requirement. |
| C06–C07 | PASS. HTTP committed marker/repeat/changed selection, unsaved checkbox, очередь/результат; отсутствующий/повреждённый план обрабатывается flash, результат сохраняется без детализации. Worker statuses и stale summary проверены integration. |
| C08–C09 | PASS. Реальный Chromium: пустой фильтр, ошибки строк, длинный/многострочный/HTML текст, keyboard Space, count1, desktop1440×1000/mobile390×844, horizontal scroll. JS errors0/dialogs0. [Desktop](screenshots/preview-desktop.png), [mobile](screenshots/preview-mobile.png), [полный текст](screenshots/preview-mobile-text.png). Browser fixtures удалены и server остановлен. |

## Файлы, нагрузка и схема

| ID | Результат и доказательство |
|---|---|
| F01 | PASS с ограничениями измерения. Локальные 1000/10000 строк; реалистичный10k existing+10k input: preview≈0.526s/2SQL/UOW4, handler≈11.637s/UOW4. После измерения устранён CP N+1: один joined read на пачку; 25CP regression GREEN. Peak226/350MiB включает подготовку fixtures, это не чистая стоимость запроса и не SLA. Память плана остаётся O(строки+кандидаты), ORM-граф ограничен пачкой. До освобождения графа≈63.722s/UOW10005. |
| F02 | PASS. XLSX/CSV, invalid дата/сумма/mapping, ошибки reader существующими suites; превышение1024 Unicode-символов — invalid без обрезки, другая допустимая строка применяется. Существующий XLS converter сохранён, нового parser/dependency нет. Некорректная UTF8 при JSON сохранении плана даёт контролируемую ошибку. |
| F03 | PASS. Stored plan digest/context/version, чужой context, source bytes hash и повреждение/недоступность плана проверены. Storage RuntimeException преобразуется в ожидаемую ObjectStorageException; публикация плана после полной сериализации. |
| F04 | PASS локально. Version20261106120000 ADD boolean DEFAULT FALSE NOT NULL; UP→DOWN→UP, без финансового backfill. Production migration не запускалась; dispatch/rollback описаны в handoff. |

## Проверки и ограничения

Полные handoff gates выполнены: unit4468 и full8226/46454 GREEN на новой тестовой БД из306 миграций. Исходная общая тестовая БД имела два несвязанных schema drift failure; они диагностированы и не скрыты изменением бизнес-кода. Две ошибки write-gate нашего POST исправлены. Финальные дополнительные изменения покрыты targeted/module rerun, static/style/Twig и независимым ревью; Финальный unit после master integration4470/25248 и canonical CS/strict3760 GREEN; окончательные full/static результаты и SHA — в описании PR и checks.

Новых npm/Vite/React assets нет; npm build/lint не запускались. Inline progressive script проверен реальным браузером. Проверка browser/sync использовала локальные fixtures и существующий parser; живые банковские API не вызывались.

Финальный review всего diff — fresh internal и Claude read-only. Открытых проблем корректности кода BLOCKER/IMPORTANT нет; production выкладка требует согласования безопасного порядка worker/web, указанного в handoff.
