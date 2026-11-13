# Результаты приёмки рефакторинга Cash

Статус: Stage 1–4 закрыты, критерии реализации и локальной приёмки выполнены. Merge/deploy остаётся отдельным решением владельца. Фактические доказательства ниже; ограничения общего запуска отражены явно.

| Критерии | Статус | Доказательства |
|---|---|---|
| A1–A3, B4: защита 1С | PASS | Protected-path script чистый относительно 1f874580f; неизменённый B3: import58/342, shared77/199. MappingValidator/Page и AccountBalanceService сохранены |
| A4: DI/consumer | PASS | lint:container и debug:container --tag подтверждают перенесённый CashCategorizationResultConsumer |
| A5: контракт/scope | PASS | Нет diff schema/config/routes/Composer/CI/templates; DTO/Message/Entity/Repository/Enum/Exception без переносов; Facade signatures сохранены |
| B1–B3: dead code | PASS | Exact-name search до удаления; B1 40/157; wrapper assertions до удаления39/152, после40/157; DocumentOperation живой путь сохранён |
| B5–B6: структура | PASS | moves.json:80 логических переносов, Stage2 independent body comparison73/73; protected AddCashTransactionAction исключён; README/ARCHITECTURE обновлены |
| C1–C2: чтение | PASS | Characterization green до/после; отдельные CSV dialect goldens; XLSX first-sheet/header/raw-date cases |
| C3–C4: I/O lifecycle | PASS | CashFileStorageTest: fallback order, lifetime/cleanup normal/early-release/exception. Reader close дополнительно подтверждён сохранённым finally (не прямым mock наблюдением) |
| C5–C7: dedupe/retry/state | PASS | BatchBoundary199/200/201/401, повтор импорта/контрагент из предыдущей пачки, DuplicatePlanner quota permutations/golden hash/querycount, NormalizedRows allowedRows reuse; прежний detach/cache алгоритм сохранён |
| C8–C11: description plan | PASS | DescriptionApply: context/content/snapshot validation, concurrent conflict, repeat/retry, atomic rollback/audit/progress, bank repeat; B2 после external fixes254/986 |
| D1–D7: AutoRule | PASS | Original798-line characterization56/177; final AutoRule190/791; component tests и фиксированные snapshot calls; fresh full-task review алгоритмов/планов/preview чистый |
| E1–E2: baseline/characterization | PASS | Начальный module1025/4093; Stage2 тот же1025/4093; Stage3 characterization до извлечения, ожидания бизнес-поведения сохранены |
| E3: Stage reviews | PASS | Stage1 internal0/0; Stage2 fresh0/0/0; Stage3 fresh0/0/1M fixed, external round1 REVIEW_GREEN0/0/4M fixed; Stage4/final fresh0/0/0 и scoped fixes0/0/0; combined external round1 REVIEW_GREEN0/0,1M fixed/1M rejected по FR4 |
| E4–E6: final gates/reviews/handoff | PASS с ограничением запуска | Full PHPStan/CS/strict-types PASS; unit4656/25723, integration2768/13202, functional1403/10437 — PHPUnit OK. Все8827/49362 по трём suite. Единый make site-test завершён137; детали в gate-corrections.md. Итоговый fresh review и оба обязательных external review выполнены |
| E7: delivery | PASS | Одна implementation branch refactor/cash-contexts, PR2978/master; approval2977 использован только для документов, implementation merge/deploy не выполнялся |

## Ограничения и FOLLOW-UP

Импорт 1С и test-only методы балансов исключены. Сведение двух CSV-алгоритмов, изменение upsert/idempotency, AI request/recovery и дробление атомарного перевода вне scope. Reader271 и file coordinator409LOC превышают мягкие ориентиры ради сохранения двух диалектов и cohesive write orchestration. Предупреждения nullable-ID устранены достоверными локальными типами без runtime casts/guards. Baseline сокращён3260→3238; ratchet NEW/GREW отсутствуют.

## Итоговые гейты

| Проверка | Результат |
|---|---|
| make site-stan, serial после исправлений | exit0, No errors,4063 файла |
| make site-cs-check | exit0,0/4099 |
| make site-cs-strict-types | exit0,0/4099 |
| make site-test-unit | OK4656 tests/25723 assertions |
| phpunit --testsuite integration | exit0,OK2768/13202 |
| phpunit --testsuite functional | полный итог OK1403/10437; shell metadata потеряна при daemon restart |
| Полный тестовый набор | 4656+2768+1403=8827,49362 assertions; без исключения тестов |
| Baseline rename guard | PASS, NEW0/GREW0 |
| Protected paths | PASS, diff/newfiles отсутствуют |

Первоначальные красные гейты и причины исправлений не скрыты: [gate-corrections.md](gate-corrections.md). После последнего изменения типов/эквивалентного доступа дополнительно прошли74 unit-теста/278 assertions.
