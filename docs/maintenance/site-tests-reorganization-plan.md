# План реорганизации `site/tests`

**Дата анализа:** 2026-07-14
**Статус:** план реализован и проверен; готов к owner review
**Область:** восстановление тестового baseline, затем структура тестов, тестовая конфигурация и связанная документация

## 1. Цель

Привести `site/tests` к принятой в проекте пирамиде тестов:

- `Unit` — изолированная логика на `PHPUnit\Framework\TestCase`;
- `Integration` — Symfony kernel, Doctrine, PostgreSQL, файловая система или несколько реальных компонентов;
- `Functional` — HTTP/контроллеры на `WebTestCase`/`WebTestCaseBase`;
- builders — `tests/Builders/{Module}`;
- общие данные и техническая поддержка — `tests/Fixtures` и `tests/Support`.

Реорганизация не должна менять бизнес-логику, финансовые расчёты, HTTP-контракты или production-конфигурацию.

До структурных перемещений был отдельно выполнен подготовительный этап: прогон всех зарегистрированных suites и минимальные исправления тестовой инфраструктуры, устаревших тестовых данных и подтверждённых production-дефектов LOW/MEDIUM-риска. Эти изменения не являются частью будущих rename/delete-стадий и должны ревьюиться отдельно от них.

## 1.1. Статус исполнения на 2026-07-14

| Набор | Исходный результат | Результат после реорганизации |
|---|---|---|
| `unit` | 1322 теста, green с warnings/deprecations | `OK (1427 tests, 8580 assertions)`, без warnings/deprecations |
| `integration` | 760 тестов, 4 failures | `OK (694 tests, 3326 assertions)` |
| `functional` | 86 тестов: 51 errors, 1 failure | `OK (216 tests, 1399 assertions)` после review-fix |
| Telegram smoke | 27 тестов, 16 errors | `OK (31 tests, 106 assertions)` по путям новых suites |

Изменение числа тестов внутри suites ожидаемо: ранее отдельные модульные и orphan-наборы включены в три стандартных suite, а 21 HTTP-тест перенесён из Integration в Functional. После review добавлен regression-тест UUID requirement; на уровне файлов сохранено 457 действующих `*Test.php`.

Одобренные владельцем HIGH-действия выполнены:

1. UUID requirement маршрута `cash_transaction_show` исправлен.
2. В статическую Ozon taxonomy mapping добавлен fallback по provider/type name.
3. Удалены два теста отсутствующих production-классов: `OzonOrderSyncServiceTest` и `ResetCostMappingActionTest`.
4. Rename/delete-стадии реорганизации выполнены.

Для полного прогона в Makefile задан `COMPOSER_PROCESS_TIMEOUT=0`. Загрузка fixtures исключает тестовую служебную таблицу `test_money_holder` из purge.

## 2. Использованные правила проекта

- `PATTERNS.md`, §16: Domain → Unit, Application/Facade/Query → Integration, Controller → Functional.
- `PATTERNS.md`, §17 и `CLAUDE.md`: builders находятся в `tests/Builders/{Module}`.
- `README.md`: обычные integration/functional тесты работают через test-БД и DAMA; PostgreSQL-сценарии с реальными commit/lock/schema checks наследуются от `PostgresResetTestCase`.
- `site/phpunit.xml`: основными suites объявлены `unit`, `integration`, `functional`, но рядом с ними сейчас существуют отдельные модульные suites.

## 3. Текущее состояние

### 3.1. Инвентаризация

- 535 файлов под `site/tests`, отслеживаемых Git.
- 458 файлов `*Test.php`.
- 510 PHP-файлов, включая builders, fixtures и support-классы.
- Стандартные каталоги содержат только 410 тестовых файлов:

| Каталог | Файлов `*Test.php` | Наблюдение |
|---|---:|---|
| `Unit` | 206 | Все тесты изолированы от Symfony kernel по статической проверке |
| `Integration` | 166 | Внутри ошибочно находятся 21 `WebTestCase`; один тест является чистым unit |
| `Functional` | 38 | Все наследуются от `WebTestCaseBase` |

Оставшиеся 48 тестовых файлов распределены по верхнеуровневым каталогам:

| Каталог | Количество | Фактический тип |
|---|---:|---|
| `Cash` | 1 | Integration |
| `Controller` | 6 | Functional |
| `Entity` | 2 | Unit |
| `Marketplace` | 8 | 2 Unit, 2 Integration, 4 Functional |
| `MarketplaceAnalytics` | 10 | Unit |
| `MessageHandler` | 1 | Unit |
| `Service` | 14 | 7 Unit, 6 Integration, 1 мёртвый legacy-тест |
| `Telegram` | 5 | 2 Unit, 3 Functional |
| `LoginControllerTest.php` | 1 | Functional |

### 3.2. Критические проблемы

1. **24 теста не входят ни в один PHPUnit suite.**
   `Controller`, `Entity`, `MessageHandler`, `Service` и корневой `LoginControllerTest.php` не перечислены в `site/phpunit.xml`. Обычный `composer test` их не запускает.

2. **Один из пропущенных тестов уже не может быть загружен.**
   `site/tests/Service/OzonOrderSyncServiceTest.php` импортирует десять классов из отсутствующего `App\Marketplace\Ozon\*`. Переносить его в `Unit` нельзя: после подтверждения удаления старого модуля тест следует удалить отдельным согласованным действием.

3. **Стандартные профили запуска неполны.**
   Ещё 24 теста запускаются только через модульные suites `cash`, `marketplace`, `marketplace-analytics`, `telegram`. Поэтому `composer test:unit`, `test:integration` и `test:functional` не соответствуют своим названиям.

4. **21 HTTP-тест лежит в `Integration`.**
   Это:
   - 3 теста `Integration/Catalog/Product*`;
   - 1 тест `Integration/Inventory/Controller/SnapshotRequestControllerTest.php`;
   - 2 теста контроллеров `Integration/Marketplace`;
   - 15 тестов контроллеров `Integration/MarketplaceAds`.

5. **`Integration/Finance/PlReportCalculatorTest.php` является unit-тестом.**
   Он наследуется от `TestCase`, использует mocks/in-memory fixture и не поднимает kernel, БД или внешнюю инфраструктуру. `Integration/Shared/Storage/StorageServiceIntegrationTest.php`, напротив, остаётся integration: он проверяет реальную файловую систему.

### 3.3. Дополнительный структурный долг

- Пять namespace не соответствуют PSR-4 пути:
  - `Functional/Company/MoneyAccountCreateAccessTest.php` объявлен как `App\Tests\Functional\Shared`;
  - четыре unit-теста в `Finance/Engine`, `Finance/Formula`, `Inventory/Enum` используют `Tests\...` без префикса `App`.
- CSV fixtures для `OzonAdClientTest` лежат внутри `Unit/MarketplaceAds/fixtures`, хотя остальные данные централизованы в `tests/Fixtures`.
- `Functional/Shared/README.md` и `Integration/Shared/README.md` отправляют helpers в несуществующий `tests/_support`; фактический и используемый каталог — `tests/Support`.
- `tests/Fund/Factory/*` не используются и дублируют builders из `tests/Builders/Cash`.
- `tests/Builders/_BuilderTemplate.php` импортирует отсутствующий `App\Entity\Entity` и дублирует пример из `PATTERNS.md`/`RULES_CREATED_BULDER.md`.
- `RULES_CREATED_BULDER.md` дублирует канонический `PATTERNS.md`, а `InventoryPipelineCoverageReport.md` является отчётом, а не тестовым ресурсом.
- Присутствуют пустые каталоги и лишний `Unit/Cash/.gitkeep`.

## 4. Целевая структура

```text
site/tests/
├── Unit/
│   └── {Module}/{Layer}/...Test.php
├── Integration/
│   └── {Module}/{Layer}/...Test.php
├── Functional/
│   └── {Module}/{Layer}/...Test.php
├── Builders/
│   └── {Module}/{Entity}Builder.php
├── Fixtures/
│   └── {Module}/...
├── Support/
│   ├── Db/
│   └── Kernel/
└── bootstrap.php
```

Правила размещения:

1. Первый уровень теста всегда задаёт его технический тип, второй — модуль приложения.
2. Внутри модуля сохраняется слой исходного кода, когда он помогает навигации (`Controller`, `Application`, `Domain`, `Infrastructure`, `MessageHandler`, `Service`). Не выполнять массовое выравнивание уже понятных путей только ради симметрии.
3. Тип определяется реальными зависимостями теста, а не названием тестируемого класса:
   - pure PHP + mocks → `Unit`;
   - kernel/Doctrine/PostgreSQL/реальная FS → `Integration`;
   - HTTP client/controller boundary → `Functional`.
4. PHP test doubles, используемые одним integration-модулем, могут оставаться в `Integration/{Module}/Fixtures`; общие файлы данных хранятся в `Fixtures/{Module}`.
5. Новые модульные suites верхнего уровня не создавать. Точечный запуск модуля выполняется путём к каталогу или `--filter`.

`PATTERNS.md` требует integration-покрытие для Actions и functional-покрытие для Controllers. Это требование к минимальному уровню покрытия, а не основание механически переносить любой существующий mock-based `TestCase`: такой перенос не сделает его integration/functional тестом. Проверка отсутствующих boundary-сценариев должна быть отдельным coverage-аудитом. В этой задаче чистые дополнительные unit-тесты остаются в `Unit`, а доказанные web/kernel тесты раскладываются по фактическому типу.

После согласованного удаления двух мёртвых legacy-тестов и добавления regression-теста UUID requirement:

| Suite | Ожидаемое количество файлов |
|---|---:|
| Unit | 228 |
| Integration | 155 |
| Functional | 74 |
| **Всего** | **457** |

Если `OzonOrderSyncServiceTest` должен быть сохранён, сначала требуется отдельная задача на восстановление отсутствующего production-модуля; это не входит в реорганизацию тестов.

## 5. План выполнения

### Stage 0. Зафиксировать исполняемый baseline

**Риск:** LOW
**Статус исполнения:** DONE
**Результат:** известен зелёный/красный статус каждого текущего набора до перемещений.

1. Запустить отдельно `unit`, `integration`, `functional` и четыре модульных suites.
2. Запустить каждый не подключённый каталог отдельно, чтобы не скрыть независимые ошибки за первым fatal error.
3. Для `Service/Import` отдельно проверить SQLite test harness и путь Doctrine metadata; production-код не менять ради старого тестового harness.
4. Сохранить список исходно красных тестов. Не объявлять структурную стадию зелёной за счёт skip/group/исключения.

**Проверки:**

```bash
make site-test-unit
make site-test-integration
docker compose run --rm site-php-cli php bin/phpunit --testsuite functional
docker compose run --rm site-php-cli php bin/phpunit --testsuite telegram
docker compose run --rm site-php-cli php bin/phpunit --testsuite marketplace
docker compose run --rm site-php-cli php bin/phpunit --testsuite marketplace-analytics
docker compose run --rm site-php-cli php bin/phpunit --testsuite cash
```

### Stage 1. Вернуть в запуск 23 действующих orphan-теста

**Риск:** HIGH — перемещения удаляют старые пути и меняют состав стандартных suites.
**Статус исполнения:** DONE после подтверждения владельца
**После stage:** STOP, owner review required.

Переместить с обновлением namespace:

- `Controller/Cash/*` → `Functional/Cash/Controller/*`;
- остальные finance/report controller tests → `Functional/Finance/Controller/*`;
- `LoginControllerTest.php` → `Functional/Company/Controller/LoginControllerTest.php`;
- `Entity/CashTransactionAutoRuleConditionTest.php` → `Unit/Cash/Entity/Transaction/*`;
- `Entity/DocumentTest.php` → `Unit/Finance/Entity/*`;
- `MessageHandler/SendRegistrationEmailHandlerTest.php` → `Unit/Company/MessageHandler/*`;
- чистые `Service` tests → `Unit/{Cash|Finance}/{соответствующий слой}/*`;
- `AccountBalanceProviderTest.php` → `Integration/Cash/Service/Accounts/*`;
- `Service/Import/*` вместе с базовым test case → `Integration/Cash/Service/Import/*`.

Отдельное обязательное решение владельца:

- удалить `Service/OzonOrderSyncServiceTest.php` как тест отсутствующего модуля; либо остановить stage и завести отдельную задачу на восстановление модуля.

Не переписывать assertions и production-код без отдельного дефекта, подтверждённого baseline.

### Stage 2. Свернуть модульные suites в стандартные

**Риск:** HIGH — перемещения файлов и изменение PHPUnit-конфигурации.
**Статус исполнения:** DONE после подтверждения владельца
**После stage:** STOP, owner review required.

1. После согласованного удаления legacy-теста разложить 23 действующих теста из `Cash`, `Marketplace`, `MarketplaceAnalytics`, `Telegram`:
   - 13 → `Unit/{Module}`;
   - 3 → `Integration/{Module}`;
   - 7 → `Functional/{Module}`.
2. Обновить namespace по новому пути.
3. Удалить suites `cash`, `marketplace`, `marketplace-analytics`, `telegram` из `site/phpunit.xml`.
4. Сохранить точечный Telegram-запуск без отдельного suite:
   - `make site-test-telegram` запускает `tests/Unit/Telegram` и `tests/Functional/Telegram` по путям;
   - `composer test:smoke` использует те же пути либо удаляется после проверки реальных вызовов скрипта.
5. Не добавлять PHPUnit groups только ради замены каталогов.

Отдельное обязательное решение владельца:

- удалить `MarketplaceAnalytics/Application/ResetCostMappingActionTest.php`: оба сценария тестируют отсутствующий production-класс `ResetCostMappingAction`, а действующие механизмы mapping покрываются другими тестами набора.

### Stage 3. Исправить доказанную ошибочную классификацию

**Риск:** HIGH — перемещения файлов между suites.
**Статус исполнения:** DONE после подтверждения владельца
**После stage:** STOP, owner review required.

1. Перенести 21 `WebTestCaseBase` из `Integration` в соответствующие `Functional/{Module}`.
2. Перенести `PlReportCalculatorTest` и его `MiniTreeFactory` в `Unit/Finance/Report`/локальный unit fixture-каталог.
3. Оставить `StorageServiceIntegrationTest` в `Integration/Shared/Storage`.
4. Исправить пять известных namespace/path-расхождений.
5. Статически проверить инварианты:
   - в `Unit` нет `KernelTestCase`, `WebTestCase`, `IntegrationTestCase`, `bootKernel()` и `createClient()`;
   - в `Integration` нет `WebTestCase`/`WebTestCaseBase`;
   - каждый `Functional/*Test.php` использует web test base;
   - namespace каждого теста соответствует PSR-4 пути.

### Stage 4. Упорядочить fixtures, support и документацию

**Риск:** HIGH — удаление/перемещение устаревших файлов.
**Статус исполнения:** DONE после подтверждения владельца
**После stage:** STOP, owner review required.

1. Перенести CSV из `Unit/MarketplaceAds/fixtures/ozon` в `Fixtures/MarketplaceAds/Ozon` и обновить один helper загрузки в `OzonAdClientTest`.
2. Оставить `Support` каноническим именем и исправить ссылки на `tests/_support` в двух README.
3. После повторного `rg` удалить неиспользуемые `Fund/Factory/*`; использовать существующие `Builders/Cash/MoneyFund*Builder`.
4. Удалить невалидный `_BuilderTemplate.php`; канонический пример уже находится в `PATTERNS.md` §17.
5. Перенести `InventoryPipelineCoverageReport.md` в `docs/reviews/`.
6. Сверить уникальные правила из `RULES_CREATED_BULDER.md` с `PATTERNS.md`; перенести только отсутствующие правила, затем удалить дублирующий файл.
7. Удалить пустые каталоги и ненужный `.gitkeep`.

Не добавлять новый architecture test или dependency для контроля структуры. Сначала достаточно `phpunit.xml`, документированного правила и review checklist; автоматический guard нужен только при повторном дрейфе.

### Phase Final. Полная проверка и handoff

**Риск:** LOW
**Статус исполнения:** DONE
**После phase:** STOP, final owner review required.

1. Проверить, что все оставшиеся 457 `*Test.php` входят ровно в один из трёх suites.
2. Запустить каждый suite отдельно и полный набор.
3. Запустить code style для изменённых PHP-файлов.
4. Проверить `git diff --summary`, чтобы все удаления являлись ожидаемыми rename/delete из утверждённого списка.
5. Подготовить handoff с исходными baseline failures отдельно от регрессий реорганизации.

**Финальные команды:**

```bash
make site-test-unit
make site-test-integration
docker compose run --rm site-php-cli php bin/phpunit --testsuite functional
make site-test
docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=intersection tests
git status --short
git diff --stat
git diff --summary
```

## 6. Ожидаемые области изменений

- `site/tests/**` — перемещения, namespace и пути fixtures;
- `site/phpunit.xml` — только три стандартных suites;
- `site/composer.json` — scripts, зависящие от удаляемых suites;
- `Makefile` — точечный Telegram target;
- `PATTERNS.md` — только если в отдельном builder-документе найдутся уникальные правила;
- `docs/reviews/` и `docs/maintenance/` — отчёт и этот план.

## 7. Ограничения области и одобренные исключения

- `site/src/**`, бизнес-логику и финансовые формулы, кроме двух подтверждённых дефектов ниже;
- маршруты, публичный API, auth/RBAC/voters, кроме UUID requirement существующего маршрута `cash_transaction_show`;
- Doctrine entities, migrations и схему БД;
- Messenger transports, workers, cron и production-конфигурацию;
- зависимости Composer/npm;
- смысл assertions и набор сценариев, кроме отдельного согласованного удаления мёртвого legacy-теста;
- существующие тесты только ради косметического единообразия внутренних подпапок.

Владелец отдельно подтвердил HIGH-стадию, включающую UUID route requirement, Ozon taxonomy fallback и согласованные удаления/перемещения. Других исключений из ограничений не было.

## 8. Критерии приёмки

- [x] В `site/phpunit.xml` остались suites `unit`, `integration`, `functional`.
- [x] Каждый действующий `*Test.php` входит ровно в один suite.
- [x] Стандартные профильные команды больше не пропускают модульные тесты.
- [x] В `Integration` нет HTTP/WebTestCase-тестов.
- [x] В `Unit` нет kernel/web зависимостей.
- [x] Все namespace соответствуют `App\Tests\...` и пути файла.
- [x] Builders находятся только в `tests/Builders/{Module}`.
- [x] Общие файлы данных находятся в `tests/Fixtures/{Module}`.
- [x] `Support` является единственным каталогом общей тестовой инфраструктуры.
- [x] Все baseline-green тесты остались зелёными.
- [x] Нет миграций, новых API-контрактов или изменений финансовых формул; production-изменения ограничены двумя одобренными исправлениями.

## 9. Риски и reviewer focus

- Главный риск — не само перемещение, а включение в обычный прогон ранее пропущенных тестов и обнаружение уже существующих дефектов.
- Старые SQLite harness в перенесённых orphan-тестах заменены существующим `IntegrationTestCase` и проектной PostgreSQL test-БД.
- Удаление `OzonOrderSyncServiceTest` допустимо только после подтверждения, что старый `App\Marketplace\Ozon` модуль удалён намеренно.
- Изменение suite membership может увеличить время `unit/integration/functional`; фактическое время нужно зафиксировать в Stage Reports.

## 10. Проверки подготовительного этапа

- `pwd`, `git status --short`, `git branch --show-current` — репозиторий корректный, ветка `master`; до работы существовал только неотслеживаемый файл этого плана.
- Статические проверки через `find`/`rg` — инвентаризация, базовые классы, namespace, PHPUnit suites и отсутствующие imports проверены.
- Все зарегистрированные suites запускались отдельно контейнерной командой PHPUnit; подтверждённые результаты приведены в §1.1.
- `make site-test-fixtures` — fixtures загружаются успешно после исправления порядка инициализации системных категорий и purge exclusion.
- `make site-test-db-rebuild` — migrations выполняются; исходная fixture-ошибка после migrations исправлена и повторно проверена отдельным `make site-test-fixtures`.
- 24 orphan-теста проанализированы отдельно: 23 действующих подключены к стандартным suites, один legacy-тест удалён после подтверждения владельца.
- `make site-test-telegram` — `OK (31 tests, 106 assertions)`.
- `php bin/phpunit --testsuite functional` — `OK (216 tests, 1399 assertions)` после review-fix.
- `php bin/phpunit --testsuite unit` — `OK (1427 tests, 8581 assertions)` после review-fix.
- `php bin/phpunit --testsuite integration` — `OK (694 tests, 3326 assertions)`.
- `make site-test` — `OK (2336 tests, 13304 assertions)` после штатной подготовки test-БД.
- Первый финальный `make site-test` обнаружил рассинхронизацию локальной `app_test`: колонка `bot_links.updated_at` существовала без отметки соответствующей миграции. `make site-test-db-rebuild` пересоздал только test-БД; повторная проверка migration history и полный прогон успешны.
