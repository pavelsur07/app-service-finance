## Stage 1: Обработка реализации Ozon — detached-прокси, потеря данных и немой отказ — DONE

**Риск:** 🟠 HIGH-LOCAL (правка в пути обработки финансовых данных + транзакционная семантика; всё внутри task-ветки)
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP, ждать решения Владельца по Release Gate

### Scope Stage
- Stage base commit: `1646e0e8a6294700dfa1c9356df4f94786885ce0`
- Work items completed: `1.1` (батч-кэш), `1.2` (транзакция переобработки), `1.3` (логирование), `1.4` (регрессионные тесты)

### Исходный дефект (PROD)

```
Ошибка обработки реализации: Unable to find
"Proxies\__CG__\App\Marketplace\Entity\MarketplaceListing"
entity identifier associated with the UnitOfWork
```

Три независимых дефекта в одном пути:

1. **Расхождение батч-блоков.** `ProcessOzonRealizationAction::process()` имел два скопированных блока сброса батча. Блок в ветке «только возврат» после `em->clear()` не восстанавливал `$listingsCache`, блок в обычной ветке восстанавливал. `$counter` инкрементируется в обеих ветках, а проверка `% 250` стояла в каждой отдельно — какая ветка попадала на границу батча, та и решала. Строка 500 «только возврат» отцепляла прокси, строка 501 получала detached-прокси, финальный `flush()` падал.
2. **Потеря данных.** `reprocess()` выполнял `DELETE` через DBAL вне транзакции (автокоммит), затем пересоздание. Падение пересоздания оставляло период пустым: строки реализации и связи `pl_document_id` терялись безвозвратно.
3. **Немой отказ.** `MarketplaceController` ловил `\Exception` и делал только `addFlash`. `sentry.yaml` выставляет `register_error_listener: false` и `register_error_handler: false`, поэтому в GlitchTip уходят только ERROR-записи Monolog. Плюс prod-хендлер `main` — `fingers_crossed` с `action_level: error`, из-за чего и `info`-след действия буферизовался и выбрасывался. Единственным следом инцидента была красная плашка в браузере.

### Что сделано

- **1.1** Кэшируем ID листингов вместо сущностей — `em->clear()` больше нечего ломать, инвариант «каждый clear обязан восстановить кэш» исчез. Оба батч-блока свёрнуты в один private `flushBatch(string $rawDocId): MarketplaceRawDocument`. Удалено мёртвое присваивание `$company` и inline-FQN `\App\Marketplace\Entity\MarketplaceListing`.
- **1.2** Вызов `reprocess()` обёрнут в `$em->wrapInTransaction()` на месте вызова в `__invoke()` (тело метода не переезжало по отступу). `EntityManagerInterface::transactional()` в ORM 3 удалён — используется `wrapInTransaction()` с интерфейса.
- **1.3** `AppLogger::error($msg, $e, $ctx)` перед `addFlash` в `processRealization` (:505) и `reprocess` (:611). Исключение передаётся объектом, а не строкой — иначе в GlitchTip не будет стектрейса.
- **1.4** Два регрессионных теста, оба доказаны красными на старом коде.

**Первичный `process()` намеренно НЕ обёрнут в транзакцию:** он только вставляет; частичный отказ оставляет частичные строки, из-за которых `existsForPeriod()` станет true и следующий запуск пойдёт уже транзакционным путём переобработки — самолечение.

### Затронутые файлы

- `site/src/Marketplace/Application/ProcessOzonRealizationAction.php` — modified
- `site/src/Marketplace/Controller/MarketplaceController.php` — modified
- `site/tests/Integration/Marketplace/Application/ProcessOzonRealizationRegressionTest.php` — new
- `site/tests/Unit/Marketplace/Controller/MarketplaceControllerRealizationLoggingTest.php` — new
- `site/tests/Unit/Marketplace/Controller/MarketplaceControllerCreateConnectionTest.php` — modified (17-й аргумент конструктора контроллера)

Миграций нет. Новых Entity / Facade / Enum нет → `ARCHITECTURE.md` не трогали.

### Доказательство «красным» (обязательное для bug fix)

| Тест | На старом коде | На новом |
|---|---|---|
| `testReturnOnlyRowOnBatchBoundaryKeepsListingLink` | `EntityNotFoundException: Unable to find "Proxies\__CG__\...\MarketplaceListing" entity identifier associated with the UnitOfWork` на `ProcessOzonRealizationAction.php:269` — **дословно прод-ошибка, та же строка** | OK, created = 501, ни одной строки с `listing_id IS NULL` |
| `testFailedReprocessKeepsExistingPeriodIntact` | `Failed asserting that 0 is identical to 2` — DELETE закоммичен, период уничтожен | OK, 2 строки на месте, `pl_document_id` цел |
| `testFailedRealizationProcessingIsLoggedAsErrorWithException` | `Method was expected to be called 1 time, actually called 0 times` (при удалённом `appLogger->error`) | OK |
| `testFailedPeriodReprocessIsLoggedAsErrorWithException` | то же для ветки `reprocess` | OK |

Каждая ветка логирования доказана красной **по отдельности**: при удалении одного вызова падает ровно её тест, второй остаётся зелёным.

Раскладка теста A воспроизводит прод дословно: 501 строка на одном SKU, строка 500 — «только возврат», то есть 250-я попадает в обычную ветку (кэш → прокси), 500-я в ветку возврата (прокси отцеплены, кэш не восстановлен), 501-я падает.

### Self-review

- [x] Scope compliance — только целевые файлы, посторонние untracked-файлы репозитория не тронуты
- [x] Patterns / naming — `final class`, `declare(strict_types=1)`, constructor injection
- [x] Forbidden actions — none (нет `dump()`/`dd()`, нет `new Service()`, нет `flush()` в Repository)
- [x] Security — репозиторные вызовы и tenant-scoping не изменены; IDOR-guard `reprocess()` на месте; в логах только companyId / rawDocumentId / период / тип, без секретов и PII
- [x] Уровень логирования — `error`, а не `warning`: `Sentry\Monolog\Handler` слушает строго `Monolog\Logger::ERROR`, и это реальный инцидент, а не ожидаемое состояние
- [x] Тесты — регрессионные, доказаны красными, не «приглажены» под код
- [x] ARCHITECTURE.md — N/A, новых контрактов нет

### CS-Fixer (baseline репозитория красный — сверка по изменённым файлам)

Статический анализ недоступен: `make stan` в проекте не существует. Счётчик строк diff'а фиксера по файлу:

| Файл | baseline (HEAD) | после правки |
|---|---|---|
| `ProcessOzonRealizationAction.php` | 107 | **105** (улучшение) |
| `MarketplaceController.php` | 240 | **240** (без изменений) |
| `MarketplaceControllerCreateConnectionTest.php` | 30 | 31 |
| `ProcessOzonRealizationRegressionTest.php` (new) | — | **0** |
| `MarketplaceControllerRealizationLoggingTest.php` (new) | — | 1 |

`composer cs:fix` на этих файлах намеренно не запускался: `ProcessOzonRealizationAction.php` и `MarketplaceController.php` уже нарушают `binary_operator_spaces` по всей длине (выровненные присваивания и промоушен в конструкторе), фиксер переформатировал бы сотни строк и утопил правку.

Оба «+1» — один и тот же pre-existing hunk: `@Symfony` хочет схлопнуть многострочный `new class(...)` в одну строку (~900 символов). Мой добавленный аргумент лишь удлиняет уже помеченный блок, новых типов нарушений не вносит. Все собственные новые строки приведены к `@Symfony` (одинарные пробелы вокруг `=>` и после `:`).

### External review

- Reviewer: Codex CLI 0.146.0 (`codex exec -s read-only --ephemeral`, дифф передан через stdin)
- Iterations: 3
- Result: **REVIEW_GREEN** (итоговый прогон — ноль находок любой категории)
- Confirmed findings fixed:
  - итерация 1, MINOR: новые ERROR-пути не покрыты тестами → добавлен `MarketplaceControllerRealizationLoggingTest` (ветка `processRealization`)
  - итерация 2, MINOR: ветка `reprocess()` (:611) осталась без покрытия → добавлен второй тест, покрывающий её симметрично
- Rejected findings with reason: нет
- Ограничения ревьюера: read-only, без шелла и без доступа к прод-схеме — факты окружения (сигнатуры ORM 3 `wrapInTransaction`, `use_savepoints: true`, типы колонок `NUMERIC(12,2)`, отсутствие FK у `pl_document_id`, результаты прогонов) переданы в промпте. Первый запуск упирался в `bwrap` внутри изолированного окружения — дифф передан через stdin по протоколу `AGENTS.md`.

### Команды для проверки

- `docker compose run --rm site-php-cli php bin/phpunit -c phpunit.xml --testsuite=integration --filter ProcessOzonRealizationRegressionTest`
- `docker compose run --rm site-php-cli php bin/phpunit -c phpunit.xml --testsuite=unit --filter MarketplaceControllerRealizationLoggingTest`
- `make site-test-integration` → **OK (935 tests, 4272 assertions)**
- `make site-test-unit` → **OK (1810 tests, 10015 assertions)**
- functional suite → **OK (408 tests, 2393 assertions)**
- `php bin/console lint:container --env=test` → OK (проверка автовайринга нового `AppLogger`)

### Риски / на что обратить внимание ревьюеру

1. **Длина транзакции** — единственная реальная цена фикса 2. Месячный документ реализации может нести десятки тысяч строк; переобработка теперь держит одну транзакцию записи вместо коммита каждые 250 строк. Читателей Postgres это не блокирует, `clear()` по-прежнему ограничивает память PHP. Следствия: отложенный vacuum по `marketplace_ozon_realizations` и полный откат при таймауте HTTP-запроса — что строго лучше сегодняшнего полуудалённого периода.
2. **`use_savepoints: true` (`config/packages/doctrine.yaml`) становится load-bearing.** Без него упавший `flush()` пометил бы внешнюю транзакцию `isRollbackOnly`, и проверки теста B под DAMA сломались бы. Настройку нельзя выключать.
3. **`ReprocessMarketplacePeriodAction` получает закрытый EM посреди цикла** по документам: отказ на документе N закрывает EM и обрывает цикл, документы 1..N-1 сохраняют свои коммиты. Это желаемое поведение. Слушателей `kernel.response`/`kernel.terminate` в `src/` нет, поэтому закрытый EM до ответа никто не флашит.
4. **Воркер этот путь не трогает:** `SyncOzonRealizationHandler` только скачивает и сохраняет raw-документ, действие не вызывает.

### Открытые вопросы / follow-ups (вне scope, по решению Владельца)

- Ещё два немых catch в `MarketplaceController` — `:304` и `:380` (ошибки синхронизации с маркетплейсом): другая доменная область, Владелец явно оставил вне scope. Тот же анти-паттерн есть примерно в 14 файлах по проекту (~28 мест).
- `site/src/Company/Application/Service/AccountBootstrapper.php:42` вызывает `$this->em->transactional(...)`, которого в ORM 3 больше нет — вероятный мёртвый или падающий путь. Найдено попутно, отдельная задача.
- `reprocess()` восстанавливает `pl_document_id` по одному только SKU: при дублях SKU внутри документа `UPDATE ... WHERE sku = :sku` проставит документ ОПиУ всем строкам этого SKU, а не только исходно закрытой. Предсуществующее поведение, данной правкой не затронуто.
