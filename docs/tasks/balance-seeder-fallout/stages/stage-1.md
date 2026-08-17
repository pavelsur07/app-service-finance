## Stage 1: починить 500 на `/finance/report/preview` и `/company/new` — DONE

**Риск:** 🟠 HIGH-LOCAL (legacy-контроллер Company, удаление кода, транзакции)
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP, ждать решения Владельца по Draft PR

### Scope Stage
- Stage base commit: `377c75d5`
- Work items completed: `1.1` (AccountBootstrapper), `1.2` (BalanceFacade + CompanyController), `1.3` (flush/транзакция в SeedBalanceStructureAction), `1.4` (регрессионные тесты)

### Что сделано

Причина: коммит `15dabd37 refactor(balance)` удалил `App\Balance\Service\BalanceStructureSeeder`,
заменив его на `SeedBalanceStructureAction`, но не обновил двух потребителей в модуле Company.
Symfony делает такой сервис «errored» и бросает исключение в момент запроса, поэтому падали обе
страницы, которым он нужен.

- `AccountBootstrapper`: удалён мёртвый `bootstrapForUser()` (вызовов в `src/` и `tests/` нет) вместе
  с `seedCashflow`/`ensureCashflow`/`seedAccounts`/`ensureAccount`/`createCompanyFor` и пятью
  зависимостями, которые использовались только им. Добавлен отсутствовавший `use Ramsey\Uuid\Uuid` —
  без него живой `ensurePlSeeded()` фаталил на первой же созданной категории ОПиУ.
- `BalanceFacade::seedDefaultStructure(string $companyId): bool` — новый публичный метод-делегат к
  `SeedBalanceStructureAction`; `CompanyController::new` ходит в модуль Balance через него, а не
  через `Application/` напрямую.
- `SeedBalanceStructureAction`: `flush()` после `persist()` категории (`LinkBalanceCategoryAction`
  ищет категорию через `findOneBy` → SQL, без flush линк падал `BalanceCategoryNotFoundException`,
  а последние две категории вообще не сохранялись) и `wrapInTransaction` вокруг всего `__invoke`.
- `CompanyController::new`: создание компании, cashflow и баланса — в одной `wrapInTransaction`.

### Затронутые файлы
- `site/src/Company/Application/Service/AccountBootstrapper.php` — modified (−135 строк мёртвого кода)
- `site/src/Company/Controller/CompanyController.php` — modified
- `site/src/Balance/Facade/BalanceFacade.php` — modified
- `site/src/Balance/Application/SeedBalanceStructureAction.php` — modified
- `site/tests/Functional/Finance/PlReportPreviewControllerTest.php` — new
- `site/tests/Functional/Company/CompanyCreateFlowTest.php` — modified
- `ARCHITECTURE.md` — modified (`BalanceFacade` не был описан вообще)

Миграций нет.

### Self-review
- [x] Scope compliance — только починка регрессии `15dabd37` и её тесты
- [x] Patterns / naming — межмодульный вызов через Facade, `wrapInTransaction` как в остальных Action
- [x] Forbidden actions — none
- [x] Security — `companyId` только от только что созданной компании владельца; новых IDOR-путей нет
- [x] CS-Fixer точечно / PHPUnit — green (PHPStan в проекте не установлен)
- [x] ARCHITECTURE.md updated

### Регрессия доказана красным
На коде до правки (`git stash push -- site/src`) те же два теста:
- `PlReportPreviewControllerTest` — Error, ровно исходный
  `RuntimeException: ... requires the "$accountBootstrapper" argument that could not be resolved`;
- `CompanyCreateFlowTest::testCreateCompanyAddsOwnerCompanyMember` — Failure.

После правки — `OK (3 tests, 23 assertions)`.

### External review
- Reviewer: Codex CLI 0.147.0 (`codex exec -s read-only --ephemeral`, дифф и контекст через stdin)
- Iterations: 3
- Result: REVIEW_GREEN
- Confirmed findings fixed:
  - IMPORTANT — создание компании перестало быть атомарным → `wrapInTransaction` в контроллере;
  - IMPORTANT — seeding неатомарен при вызове мимо контроллера → `wrapInTransaction` внутри
    `SeedBalanceStructureAction`;
  - MINOR — тест допускал неполную структуру → проверяются три корня, обе дочерние категории
    «Активов» и идемпотентный повтор.
- Rejected findings with reason:
  - тест с искусственным сбоем после первого линка — требует подмены сервиса в контейнере ради
    проверки штатной семантики `wrapInTransaction`; ревьюер в итерации 3 снял требование.
  - FOLLOW-UP (не блокирует): два одновременных seeding для одной пустой компании не сериализованы;
    в текущем маршруте companyId всегда новый.
- Ограничения ревьюера: без доступа к шеллу и БД; факты о снятом FK
  `balance_categories.company_id`, о прогонах тестов и о красном CS-baseline переданы в промпте.

### Команды для проверки
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite functional --filter "PlReportPreviewControllerTest|CompanyCreateFlowTest"`
- `make site-test`
- `docker compose run --rm site-php-cli php bin/console lint:container`

### Риски / на что обратить внимание ревьюеру
- Удаление `bootstrapForUser()` необратимо ломает внешний вызов, если такой появится вне репозитория;
  в репозитории вызовов нет.
- Вложенные `wrapInTransaction` (контроллер → action) полагаются на штатное поведение DBAL; проверено
  прогоном functional-тестов, в том числе под `dama/doctrine-test-bundle`.
- `make site-cs-check` красный до задачи; на изменённых файлах остались 3 нарушения, все существовали
  до правки и не на изменённых строках.

### Открытые вопросы
- `php bin/console lint:container` ловит ровно этот класс поломок на сборке. В CI его нет — вынесено
  в follow-up, за scope этой задачи.
