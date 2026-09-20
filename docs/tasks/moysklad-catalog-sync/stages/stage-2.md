### Stage 2: полный импорт и возобновление — DONE

**Risk:** HIGH-LOCAL

**Stage base commit:** `0152da06`

**Work items:** 2.1, 2.2, 2.3

#### What was done

- Добавлен полный обход `product` и `variant` с двумя архивными выборками, проверкой размера, порядка UUID и постраничным атомарным upsert.
- Отдельные `MoySkladSyncRun` и `MoySkladSyncCursor` для каждого типа; сбой модификаций не откатывает завершённый проход товаров. Статус сбоя записывается безопасной категорией.
- Добавлены `SyncCatalogMessage`/handler на `async_sync` с ограниченным retry для 429 и временных ошибок; HTTP-клиент использует gzip и не передаёт ответ или токен в журнал.

#### Definition of Done

- [x] Первичная загрузка, повтор, страницы, сбой, 429, отсутствующий родитель, неверный порядок и tenant isolation покрыты тестами.
- [x] Успешный курсор обновляется только по окончании своего типа; частично обработанная страница не считается завершённым проходом.

#### Checks

- baseline: `make site-test-unit` — 2945 tests, 16289 assertions, 4 existing deprecations.
- Action integration RED (отсутствовал класс), затем 8 tests, 64 assertions.
- API unit gzip RED (отсутствовал заголовок), затем 5 tests, 14 assertions.
- `php bin/phpunit tests/Integration/MoySklad tests/Unit/MoySklad` — 150 tests, 665 assertions after review fixes.
- focused PHPStan — no errors после исправления типов массивов и ветвления парсера.

#### Internal review

- Iteration 1: BLOCKER 0, IMPORTANT 0; проверены курсоры при сбое, классификация ошибок, блокировка, tenant scope и отсутствие payload в журнале. Внешний review выявил пропущенные случаи распаковки gzip, дрейфа страниц и безопасной ошибки дешифрования; исправлены регрессионными тестами. Iteration 2: open BLOCKER 0, IMPORTANT 0.

#### External review

- HIGH-LOCAL diff >500 lines: 2 rounds. Round 1: BLOCKER 1 (явный gzip отключал распаковку), IMPORTANT 1 (дрейф размера и родителя без retry), MINOR 1 (слабое утверждение Retry-After). Round 2: IMPORTANT 1 (нарушение порядка без retry), MINOR 3 (сбой дешифрования без статуса, UUID маршрута, подпись повторного запуска). Всё подтверждено и исправлено тестами RED→GREEN; итог «fixed without re-run» по правилу §7.2, поскольку после round 2 BLOCKER не осталось.

#### Risks / reviewer focus

- У `variant` порядок `id,asc` не гарантирован документацией. Проверка порядка выявляет нарушение, но живой тест с непустыми модификациями ещё нужен.

#### Next

- Continue to Stage 3 automatically.
