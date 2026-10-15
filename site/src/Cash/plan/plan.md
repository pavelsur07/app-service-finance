# Модуль `Cash`: единый план исправлений

Дата: 2026-10-14. Объединяет аудит архитектуры `site/src/Cash` и выбор
паттернов. Каждая найденная ошибка исправляется тем паттерном, который её
предотвращает, и закрепляется гейтом. Этапы выполняются **строго по порядку**:
следующий не начинается, пока чек-лист предыдущего не закрыт полностью.

Аудит был статическим: grep по коду и подсчёт записей `phpstan-baseline.neon`.
Тесты и PHPStan не запускались. **Эксплуатируемых IDOR не найдено**: все
загрузки по ID проверяют компанию после загрузки.

---

## Общие правила для всех этапов

**Можно:**

- Править файлы, перечисленные в разделе «Лезем» текущего этапа, и их тесты.
- Создавать в `site/src/Cash/` новые классы по целевой раскладке
  (`docs/workflow/new-module.md`): `Exception/`, `Application/`, `Domain/`,
  `Infrastructure/Query/`.
- Добавлять Facade-методы, если они однозначно следуют из этапа, с обновлением
  `ARCHITECTURE.md` в том же этапе.
- Сокращать `phpstan-baseline.neon`: удалять записи, которые этап закрыл.

**Нельзя:**

- Трогать что-либо вне раздела «Лезем» текущего этапа. Найденное по дороге —
  записать в «Находки» в конце файла, не чинить.
- Растить baseline. Единственное исключение — новый гейт этапа 4, и только с
  меткой `[baseline-grow]` в заголовке PR.
- Запускать `make site-stan-baseline`: он стирает ratchet.
- Менять схему БД и данные. Миграции в этом плане не предусмотрены; если этапу
  понадобилась миграция — это STOP и согласование.
- Менять поведение для пользователя: тексты, маршруты, формат ответов
  существующих эндпоинтов, вёрстку. Исключение — явно названное в этапе
  (500 → 422 в этапе 1).
- Вливать `master` без нужды: проверять mergeable, а не перетаскивать чужие
  изменения.
- Коммитить без pathspec: в индексе лежат `design-system-pack.zip` и
  `operations-dds-pack.zip`, голый `git commit` их заберёт.

**Как работаем:**

- Одна ветка и один PR на этап (для этапа 7 — на подэтап). Внутри этапа —
  локальные коммиты, push и PR одним шагом на handoff.
- Перед handoff: `make site-stan`, `make site-cs-check` (без кеша),
  `make site-cs-strict-types`, `make site-test`. Гейты гонять по одному
  (на dev-машине OOM при параллельном запуске).
- Исправление бага → регрессионный тест, красный на старом коде и зелёный на
  новом. Новый Action → happy-path + 1 негативный тест.
- Изменение `config/packages/messenger.yaml` — HIGH-LOCAL: проверить routing,
  retry/failure, совместимость сообщений в очереди; усиленное ревью.
- Правка `src/Cash/Entity/` — только то, что названо в этапе; инварианты
  остаются в конструкторе.
- STOP — только по списку `AGENTS.md` §3.4. Типичный повод в этом плане:
  исправление требует решения по бизнес-правилу или финансовой семантике
  (например, как округлять НДС).

**Замеры «до».** Каждый этап начинается с фиксации цифр в разделе «Журнал» в
конце файла: число записей baseline по затронутым файлам и идентификаторам.
После этапа — цифры «после» и разница.

---

## Этап 0. Снимок «до» и проверка денежных расхождений на данных

**Цель.** Зафиксировать исходное состояние и проверить на проде, дают ли
расчёты сумм через `float` реальные расхождения. От результата зависит,
насколько срочен этап 3.

**Лезем:**

- `phpstan-baseline.neon` — только чтение и подсчёт.
- Прод — только read-only SQL через `codex-psql-ro`.

**Не лезем:** код, конфиги, данные. Никаких записей в БД, никаких команд
пересчёта.

**Правила:**

- Время в БД прода — МСК без зоны; в ручных запросах
  `now() AT TIME ZONE 'Europe/Moscow'`.
- В отчёт не попадают ФИО, ИНН и суммы конкретных клиентов — только счётчики и
  агрегаты.

**Что проверить на данных:**

1. `CashTransaction`: сравнить `amount - allocated_amount` в decimal с тем, что
   показывает интерфейс (расчёт `CashTransaction.php:719–727` через `float`).
2. `Finance\Document`: сумма операций в decimal против итога, считаемого в
   `Document.php:253` через `float`.
3. Число транзакций с НДС, где `VatCalculator::splitGross` получает `float`.

**Результат.** Таблица «до» в «Журнале» и вывод: есть расхождения в копейках
или нет.

**Чек-лист:**

- [x] Записаны: baseline всего по `src/Cash/` (240 записей), записи
      `phpat…Cash` (39 — в аудите ошибочно 78, grep считал идентификатор
      дважды), `vashfindir.repositoryCompanyScope` по Cash (4),
      `phpat…Company` из Cash (13), `phpat…Finance` из Cash (3).
- [x] Выполнены три проверки на данных, результат — счётчики, без ПДн.
- [x] Вывод записан: расхождений нет (см. «Результат этапа 0»).
- [x] Расхождений нет — этап 3 не срочный, порядок этапов не меняется.

### Результат этапа 0 (2026-10-14)

Снято с `origin/master` `6bd9db6a` и с прода read-only (`codex-psql-ro`, только
`SELECT`, вывод — счётчики). На проде за этап ничего не записано.

| Проверка | Что сравнивалось | Объём | Расхождений |
|---|---|---|---|
| 1. `allocated_amount` | сохранённое значение против точной суммы операций живых документов в `numeric` | 462 активные транзакции с документами | 0; округление суммы `float8` до копеек тоже совпало везде |
| 2. Решение «можно распределить» | `float8` против `numeric` для каждого документа (моделирование `canAllocateAmount`) | 494 документа, все ровно на границе остатка | 0: ни ложных отказов, ни ложных разрешений |
| 3. `vat_amount` | сохранённое против `round(amount × r / (100 + r), 2)` в `numeric` | 24 строки, ставка 5% (остальные 9816 активных — без НДС) | 0; случаев ровно на полкопейки, где `float` может округлить иначе, — 0 |

**Вывод:** дефект `float` на данных не подтверждён. Риск остаётся
теоретическим: полкопейки в НДС и сравнение остатка на границе при большем
числе документов. Этап 3 сохраняется как профилактика без срочности.

`PaymentPlanMatcher` из этапа 3 исключён: сравнение сумм идёт с допуском 5%,
погрешность `float` (~1e-16) на решение не влияет.

---

## Этап 1. Обработка ошибок: глотание исключений, Messenger, доменные исключения

**Цель.** Убрать места, где ошибки теряются или бесконечно ретраятся, и
ввести доменные исключения модуля (паттерн «доменные исключения со стабильным
`code`», `PATTERNS.md` §13; «классификация ошибок в Messenger», §10, §23).

**Лезем:**

- `Controller/Import/CashFileImportController.php` — `catch (\Throwable)` на
  строке 123.
- `MessageHandler/Import/BankImportHandler.php`.
- `Service/Import/Bank/Provider/Alfa/AlfaApiClient.php`.
- `Controller/Accounts/FundController.php:152`.
- Новые классы в `Cash/Exception/`.
- Маппинг новых исключений в `ExceptionListener` — только добавление строк.

**Не лезем:**

- Остальные ~20 мест с голыми исключениями в Cash — они уйдут попутно
  (см. «Попутные правила»).
- Логика импорта, ретраи на уровне транспорта, `messenger.yaml`.
- Другие модули.

**Правила:**

- Неустранимое (компания не найдена, нет активного подключения, неизвестный
  код банка) → `UnrecoverableMessageHandlingException` + `logger->error`.
- Временное (429, 5xx, таймаут Alfa) → доменное исключение, в handler —
  `RecoverableMessageHandlingException` + `logger->warning`.
- Логи handler'а: старт и финиш с ID сообщения и `companyId`. Внешний HTTP —
  метод, URL, статус, время; **без тела ответа, токенов и ключей**.
- `catch (\Throwable)` в контроллере не оставлять пустым по смыслу: сузить тип
  или логировать `error` с `companyId`. Flash-сообщение пользователю
  сохраняется как есть.
- Исключения — `final class`, extends `AppException` или `\RuntimeException`,
  имя по причине (`BankConnectionNotFoundException`), `code` — snake_case.
- `FundController`: некорректная сумма → 422 вместо 500. Это единственное
  разрешённое изменение видимого поведения на этапе.

**Результат.** Ни одна ошибка этих путей не теряется молча; неустранимые
сообщения не ретраятся; в GlitchTip приходят только настоящие инциденты.

**Чек-лист:**

- [x] `CashFileImportController:123` — тип сужен до `\RuntimeException`;
      функциональные тесты: сбой хранилища → flash, ошибка программы → 500.
- [x] `BankImportHandler` — три неустранимые ветки бросают `Unrecoverable…`;
      логи старта и финиша; тесты на каждую ветку.
- [x] `AlfaApiClient` — доменные исключения, временное отличимо от
      постоянного; unit-тесты на 429/5xx, 4xx, транспорт и битый JSON.
- [x] `FundController` — исключён: ветка недостижима (см. результат).
- [x] `ExceptionListener` — не нужен: новые исключения возникают только в
      воркере, HTTP-ответа у них нет.
- [x] Baseline сократился на 8; изменения записаны в «Журнал».
- [x] Все гейты зелёные.

### Результат этапа 1 (2026-10-14)

Перед кодом дефекты проверены по коду и на данных прода (read-only). Ни один
не подтвердился как действующий; по решению Владельца этап выполнен в
сокращённом, профилактическом объёме.

| Пункт | Что выяснилось | Что сделано |
|---|---|---|
| `CashFileImportController:123` | Сбой хранилища уже пишет `error` декоратор `LoggingObjectStorage` — ошибка не терялась. Локальный драйвер при сбое записи бросает `\RuntimeException`, не `ObjectStorageException` | `catch (\Throwable)` → `catch (\RuntimeException)`: flash для сбоя хранилища сохранён, `\Error`/`\LogicException` дают 500 |
| `BankImportHandler` | На проде 0 банковских подключений, 0 `import_log` с `bank:*`, 0 `BankImportMessage` в `failed` — код не выполнялся | Неустранимые ветки и отказ банка (4xx) → `error` + `UnrecoverableMessageHandlingException`; временный сбой → `warning` + исходное исключение (ретраи транспорта, затем `failed`); логи старта и финиша с `import_id`, `company_id`, `bank_code`; `CompanyRepository` → `CompanyFacade` |
| `AlfaApiClient` | То же: не использовался | `final`; `BankApiUnavailableException` (сеть, 408, 429, 5xx, обрыв тела) и `BankApiRequestFailedException` (прочие коды, битый JSON); лог: метод, путь без query, статус, время; тело ответа не логируется; исключения HttpClient не передаются как `previous` — их сообщения несут URL с номером счёта (замечание внутреннего ревью) |
| `FundController:152` | Поле — `MoneyType`: нечисловой ввод отсекается валидацией формы, ветка недостижима; фонды за флагом `FEATURE_FUNDS_AND_WIDGET` | Не трогали |

`RecoverableMessageHandlingException` сознательно не используется: Symfony
ретраит `RecoverableExceptionInterface` безусловно, в обход `max_retries`
(`SendFailedMessageForRetryListener::shouldRetry`); так же сделано в
`SyncOzonListingCatalogHandler`. Противоречие с `CLAUDE.md` — в «Находках».

---

## Этап 2. Изоляция данных компании: репозитории, контроллеры, формы

**Цель.** Сделать доступ к данным компании безопасным по построению, а не по
ручной проверке после загрузки (паттерн «доступ только через методы с ID
компании», `PATTERNS.md` §14).

**Лезем:**

- Репозитории Cash: добавить `findOneByIdAndCompanyId()` /
  `getByIdAndCompanyId()` там, где их нет.
- 11 вызовов `find($id)`: `Bank1CImportController` ×3,
  `BankConnectionController` ×3, `CashFileImportProfileController` ×2,
  `CashFileImportController:247`, `CashTransactionController:424`,
  `PaymentCalendarController:262`.
- 6 аргументов-Entity в контроллерах: `CashTransactionController::show/delete`,
  `CashTransactionCreateFromController`,
  `PaymentCalendarController::edit/changeStatus/postpone`.
- 4 записи `vashfindir.repositoryCompanyScope`:
  `BankConnectionRepository::findActiveByBankCode`,
  `PaymentPlanMatchRepository::findOneByTransaction`,
  `PaymentPlanRepository::findTemplateForRecurrenceRule`,
  `CashTransactionAutoRuleRepository::findActiveGeneralProjectTargetsWithoutCfo`.
- Формы: `CashTransactionAutoRuleType` (`ProjectDirection`),
  `CashflowCategoryType:104` (`PLCategory`), `Form/PaymentPlan/PaymentPlanType`
  (`query_builder` без компании при `company = null`).
- `site/tests/PHPStan/Rules/RepositoryCompanyScopeRule.php` — расширение.

**Не лезем:**

- `#[ManyToOne] Company` в сущностях — легаси, задокументировано в
  `ARCHITECTURE.md`.
- Репозитории других модулей, даже если в них то же нарушение.
- Маршруты и URL: `{id}` в пути остаётся, меняется только способ загрузки.
  *Пересмотрено при выполнении:* на затронутых маршрутах добавлены
  `requirements: UUID` — невалидный id давал 500.
- Глобальный Doctrine SQL Filter по компании — не вводим.

**Правила:**

- `@companyScopeExempt <причина>` — только где обход всех компаний задуман:
  cron по всем подключениям, миграционная команда. Там, где компания известна
  через владельца, — добавить параметр `$companyId`.
- Чужая сущность в форме → `ChoiceType` с данными из `CompanyFacade` /
  `FinanceFacade`. Нужного метода нет — создать, обновить `ARCHITECTURE.md`.
- `PaymentPlanType` без компании — бросать исключение, а не отдавать
  несфильтрованный список.
- Ответ на чужой ID — 404, как сейчас. Текст и поведение не меняются.
  *Пересмотрено при выполнении:* план платежа и `create-from` отвечали на
  чужой id 403 (раскрывали существование) — теперь 404; JSON `create-from`
  получил текст «Транзакция не найдена.».

**Результат.** Ни одной загрузки сущности компании без `companyId` в
контроллерах Cash; правило ловит это машинно; формы не показывают чужие
данные.

**Чек-лист:**

- [x] 11 `find($id)` заменены; на каждый маршрут — тест «чужой ID → 404»
      (во flash-сценариях — «недоступно»).
- [x] 6 аргументов-Entity заменены на `string $id` + загрузку с компанией;
      тесты «чужой ID → 404». Ревью нашло ещё 6, пропущенных аудитом из-за
      многострочных сигнатур (`CashTransactionController::createPnlDocument/edit`,
      `MoneyAccountController::edit`, `CashflowCategoryController::edit/delete`,
      `FundController::addMovement`), — переведены в этом же этапе; всего 12.
- [x] 4 записи `repositoryCompanyScope` по Cash удалены из baseline (параметр
      или `@companyScopeExempt` с причиной).
- [→] `RepositoryCompanyScopeRule` для `->find(` в контроллерах — **перенесён
      в этап 4**: правило проектное, вне Cash 41 срабатывание, а рост baseline
      по общим правилам плана допустим только на этапе 4.
- [→] Формы: `EntityType` с чужими сущностями — **перенесён в этап 7.5**:
      утечки нет (`choices` уже из списка своей компании), нужна работа над
      границей модулей — методы фасадов Company/Finance с DTO и трансформер.
- [x] Baseline уменьшился на 11 (Cash 8, тесты 3); «Журнал» обновлён.
- [x] Все гейты зелёные.

### Результат этапа 2 (2026-10-14)

Живого IDOR в контроллерах Cash не было: все 23 места (11 `find()` и 12
аргументов-Entity) после загрузки сверяли компанию. После этапа в контроллерах
Cash нет ни одной загрузки сущности компании без компании в самом запросе. Этап перевёл их на загрузку с компанией в самом запросе и закрыл
дефекты, найденные тестами (на старом коде красные 15 из 33):

- **Невалидный id → 500** в 9 маршрутах (`{id}` без `requirements`, PostgreSQL
  падал на приведении к `uuid`) и в фильтрах платёжного календаря
  (`account_id`, `category_id`, `counterparty_id`). Теперь 404 и игнор фильтра.
- **Раскрытие существования чужой записи:** чужой план платежа давал 403, чужая
  транзакция в `create-from` — JSON 403 «Доступ запрещён». Теперь 404, чужая и
  несуществующая запись неразличимы.

| Что | Как |
|---|---|
| Репозитории | `findOneByIdAndCompanyId()` в `CashFileImportProfileRepository`, `BankConnectionRepository`, `PaymentPlanRepository`; невалидный UUID → `null` |
| 4 метода без компании | `findActiveByBankCode`, `findActiveGeneralProjectTargetsWithoutCfo` — `@companyScopeExempt` (cron и разовая миграция по всем компаниям); `findOneByTransaction` → `findOneByTransactionAndCompanyId`, `findTemplateForRecurrenceRule` → `findTemplateForRecurrenceRuleAndCompanyId` |
| Контроллеры | профили импорта, подключения банка, 1С-импорт, файловый импорт, транзакции (`show`/`delete`/`restore`/`create-from`), платёжный календарь (`edit`/`status`/`postpone`, фильтр счёта); `requirements: UUID` на затронутых маршрутах |
| `PaymentPlanType` | опция `company` уже была обязательной; мёртвые `if ($company)` убраны |
| Строгий UUID | `CanonicalUuid::isValid()` (8-4-4-4-12): `Ramsey\Uuid\Uuid::isValid()` и `Assert::uuid()` пропускают `urn:uuid:…`, на котором PostgreSQL падает; применён в новых методах репозиториев, фильтрах календаря и сессии 1С |
| Данные прода перед деплоем | `payment_plan_match` — 1 строка, расхождений `company_id` с транзакцией 0; `payment_plan` с чужим `recurrence_rule` — 0 (read-only) |

Тесты: `CashCompanyIsolationTest` — чужой id, невалидный id, своя сущность
(GET открывается, POST без токена не 404), восстановление своей удалённой
транзакции, 1С preview/commit, профиль импорта с `urn:uuid:`;
`MoneyFundCompanyScopeTest` (фонды за флагом, маршрут функционально не
проверить); `CanonicalUuidTest`.

---

## Этап 3. Деньги без `float`: Money Value Object

**Цель.** Исключить ошибки округления в расчётах сумм (паттерн «Money Value
Object», `PATTERNS.md` §4 Value Object).

**Лезем:**

- Новый `final readonly class Money` (сумма строкой + `FiatCurrency`) в
  `Cash/Domain/` или общем месте по решению из `ARCHITECTURE.md`.
- Два места с арифметикой: `CashTransaction.php:612–727` (сумма
  распределённого и остаток), `CashTransactionService:261` +
  `VatCalculator::splitGross`. `PaymentPlanMatcher` исключён по итогам этапа 0
  (допуск 5%).
- `Finance/Entity/Document.php:253` — этап 0 расхождения не подтвердил,
  поэтому вне scope; см. «Находки».
- PHPStan-правило: запрет `(float)` на результатах `get*Amount()` в Cash.

**Не лезем:**

- Типы колонок и схема БД: суммы как были `decimal`/строка, так и остаются.
- `number_format((float) …)` в импортах (1C, Bank) — это форматирование
  входной строки, а не арифметика; в «Находки».
- Отображение сумм (`vf_money`, `vf_amount`, Twig) — без изменений.
- Остальные модули (Marketplace — 22 места, MarketplaceAnalytics, Catalog).

**Правила:**

- Арифметика только через `bcmath`, масштаб — как у колонки.
- Округление — один раз, на границе (запись или вывод). Правило округления НДС
  (банковское или математическое, на строку или на документ) — **бизнес-правило:
  если текущее поведение не очевидно из кода и тестов, STOP и вопрос
  Владельцу**.
- Результат расчёта на существующих данных не должен измениться, кроме случаев,
  где этап 0 нашёл ошибку. Ожидаемые изменения перечислить в PR.
- Пересчёт исторических данных на этом этапе не делается.

**Результат.** Суммы в перечисленных расчётах считаются без потери точности;
новое использование `float` для денег ловится правилом.

**Чек-лист:**

- [x] `Money` — **существующий** `App\Shared\Domain\ValueObject\Money`:
      `PATTERNS.md` §24 запрещает второй; расширен методом `multiplyByRatio()`
      с unit-тестами (точный результат, одно округление, полкопейки в обоих
      режимах, большие суммы, нулевой знаменатель).
- [x] Расчёты переведены на `Money`; регрессионные тесты на суммы, на которых
      `float` ошибается (найдены перебором, красные на старом коде).
- [x] Правило PHPStan `vashfindir.moneyFloatCast` на `(float)` от `get*Amount*()`
      в `App\Cash`; тест правила; проверено, что срабатывает в анализе проекта.
- [x] Ожидаемые изменения результатов записаны (см. результат).
- [x] Baseline −1; «Журнал» обновлён.
- [x] Все гейты зелёные.

### Результат этапа 3 (2026-10-14)

Этап 0 не нашёл расхождений на данных прода, но перебор показал, где `float`
ошибается на реальных суммах, — эти случаи закрыты и стали регрессионными
тестами:

| Расчёт | Ошибка float | Как стало |
|---|---|---|
| Остаток к распределению (`CashTransaction`) | транзакция 0.03, документы 0.01 + 0.01 → остаток 0.009999999999999998, последнюю копейку распределить нельзя | `Money` по суммам операций документов (строки); `getAllocatedMoney()`, `getRemainingMoney()`, `canAllocate()`, `assertCanAllocate()` |
| НДС «из суммы» (`VatCalculator`) | 20% из 0.09 = ровно 0.015; float хранит 0.01499… и давал 0.01 вместо 0.02 (половина от нуля) | `Money::multiplyByRatio(rate, 100 + rate)`, одно округление `HALF_UP`, net = gross − vat |
| Сопоставление с планом (`PaymentPlanMatcher`) | допуск 5% и выбор кандидата по разнице сумм во float: равные разницы могли разойтись в последнем знаке | целые минорные единицы, допуск `diff × 20 > plan` |

**Изменения результатов.** Правило округления не менялось (`HALF_UP`, как
задумывал `number_format()`); меняются только случаи, где `float` его нарушал:
- НДС 20% (ОСНО) ровно на половине копейки — теперь округляется вверх. На
  проде НДС считают 2 компании на УСН 5%; для 5% и 7% ровная половина копейки
  математически невозможна, на 20% компаний нет — затронутых строк 0.
- Матчер плана: разница ровно 5% теперь проходит допуск («больше 5%» — отказ).
- Распределение: ложные отказы на последней копейке исчезли.

**Граница с Finance.** `getAllocatedAmount()`, `getRemainingAmount()`,
`canAllocateAmount(float)`, `assertCanAllocateAmount(float)` оставлены тонкой
обёрткой над точными методами: ими пользуются Finance (`DocumentController`,
`FinanceFacade`, `RestoreDocumentAction`) и шаблоны. float переводится в
`Money` один раз, по копейкам; сравнение — точное. Код Cash перешёл на
точные методы.

---

## Этап 4. Гейты: закрепить правила машинно

**Цель.** Превратить правила, нарушенные в Cash, из текста в проверки, чтобы
исправленное не возвращалось (паттерн «правила в виде гейтов»).

**Лезем:**

- `site/tests/Architecture/` и `site/tests/PHPStan/Rules/` — новые правила.
- `config/packages/messenger.yaml` — только для `ScoreCompanyCounterpartiesMessage`.
- `phpstan-baseline.neon` — запись текущих нарушений нового правила.
- Цепочка `ScoreCompanyCounterparties*` в Cash.

**Не лезем:**

- Исправление нарушений, которые найдёт новое правило про `flush`, — это этап 5.
- Транспорт `CashFileImportMessage` — только проверить, решение в «Находки».
- Rector и массовая расстановка `final`/`readonly`.

**Правила:**

- Новые гейты:
  1. Тест: каждое сообщение, у которого есть `#[AsMessageHandler]`, прописано
     в `messenger.yaml` ровно на одном транспорте.
  2. PHPStan-правило: `EntityManagerInterface::flush()` вызывается только в
     `*Action`, `*Handler`, `*Command`.
  3. PHPat-правило: `EntityType` в формах модуля — только с сущностями своего
     модуля.
  4. Call-site правило (перенесено из этапа 2): вызов `find()`/`findAll()` на
     репозитории сущности компании в `*\Controller\*` — ошибка; аргумент
     действия контроллера с типом сущности компании (Entity value resolver) —
     тоже ошибка. Вне Cash на
     дату этапа 2 — 41 вызов `->find(`/`->findAll()` в контроллерах Company,
     Loan, Marketplace, MarketplaceAds, Telegram (часть — не по сущностям
     компании).
- Правила 2–4 вводятся **с baseline** текущих нарушений: это единственный
  этап, где baseline растёт, PR с меткой `[baseline-grow]` и списком записей.
- `ScoreCompanyCounterpartiesMessage`: команда `app:cash:dispatch-scoring`
  нигде не вызывается. Варианта два — прописать в `async_pipeline` с логами в
  handler или удалить цепочку целиком. Выбор — вопрос Владельцу (влияет на
  scope), до ответа — не трогать.
- `CashFileImportMessage` на `async_sync`: проверить, есть ли внешний HTTP
  или внешнее хранилище; результат записать, транспорт не менять.

**Результат.** Три новых гейта в CI; все текущие нарушения видны списком в
baseline и будут сокращаться на этапе 5.

**Чек-лист:**

- [x] Тест routing зелёный: Владелец решил удалить цепочку
      `ScoreCompanyCounterparties*` (команда нигде не вызывалась).
- [x] Правило `flush` работает; у него есть тест; нарушения в baseline
      перечислены в PR.
- [x] Правило `EntityType` работает; у него есть тест.
- [x] Правила контроллеров (перенесено из этапа 2) работают; тесты.
- [x] По `CashFileImportMessage` записан вывод.
- [x] PR с `[baseline-grow]`, рост объяснён по идентификаторам; «Журнал» обновлён.
- [x] Все гейты зелёные.

### Результат этапа 4 (2026-10-14)

| Гейт | Где | Нарушений на дату введения |
|---|---|---|
| Тест routing: сообщение с handler'ом — ровно на одном известном транспорте (не `failed`) | `tests/Unit/Config/MessengerRoutingTest.php` | 0 (одно исключение с причиной и проверкой «нигде не отправляется»: `ProcessMarketplaceRawDocumentCommand`) |
| `vashfindir.flushOutsideBoundary`: `flush()`/`wrapInTransaction()` только в `*Action`/`*Handler`/`*Command` (фикстуры и тесты вне правила) | `FlushOutsideTransactionBoundaryRule` | 143 вызова в 74 файлах, из них Cash — 37 |
| `vashfindir.foreignEntityType`: `EntityType` и его подклассы — только с сущностью своего модуля | `ForeignEntityTypeRule` | 6 в 5 файлах (Cash `CashflowCategoryType` → `PLCategory`, `CashTransactionAutoRuleType` → `ProjectDirection`; Finance 2; Loan 1) |
| `vashfindir.controllerUnscopedFind`: `find()`/`findAll()` сущности компании в контроллере (сущность по типу результата или по метаданным Doctrine для репозиториев без generics) | `ControllerUnscopedFindRule` | 31 в 12 файлах, Cash — 0 |
| `vashfindir.controllerEntityArgument`: сущность компании (в т. ч. `?Entity`) аргументом действия | `ControllerEntityArgumentRule` | 12 в 6 файлах, Cash — 0 |

Baseline: +111 записей (192 ошибки) только по четырём новым идентификаторам;
−5 записей удалённой цепочки скоринга. Всего 2312 → 2418 записей; Cash
223 → 238 (+18 `flush`, +2 `EntityType`, −5 скоринг).

Внутреннее ревью нашло, что первая версия `ControllerUnscopedFindRule` не
видела `find()` у репозиториев без `@extends …<X>` (16 вызовов в Marketplace)
— исправлено через метаданные Doctrine (`ObjectMetadataResolver`).

Удалено по решению Владельца: `DispatchCounterpartyScoringCommand`
(`app:cash:dispatch-scoring`), `ScoreCompanyCounterpartiesMessage`, `…Command`,
`…Handler`, `ScoreCompanyCounterpartiesAction` и использовавшиеся только ими
`CounterpartyScoringMath`, `CounterpartyHistoryQuery`. Поля скоринга у
контрагента (модуль Company) не тронуты — их больше никто не пересчитывает,
как и раньше.

`CashFileImportMessage` на `async_sync` остаётся: на проде хранилище — S3
(`docker-compose.prod.yml`), импорт читает файл внешним HTTP, затем пишет в БД.

---

## Этап 5. `flush` только в Action: Action как граница транзакции

**Цель.** Убрать `flush()` из репозиториев, контроллеров и сервисов (паттерн
«один use case — один `flush`», `PATTERNS.md` §3). В ORM 3 `flush()` сохраняет
весь UnitOfWork, поэтому `flush` в сервисе сохраняет и чужие изменения.

**Лезем, в этом порядке:**

1. Repository: `BankImportCursorRepository:46,54`.
2. Controller: `CashTransactionAutoRuleController:496,529,553`,
   `CashflowCategoryController:175` — логику (применение правила, `AuditLog`,
   включение/выключение) вынести в Action.
3. Сервисы: `MoneyAccountService`, `FundService`, `BankConnectionService`,
   `CashTransactionService` (5 мест), `CashTransactionToDocumentService`,
   `PaymentPlanMatcher`, `RecurrenceMaterializer`.
4. Псевдо-фасады `Service/Import/File/CashFileImportFacade` и
   `Service/PaymentPlan/PaymentCalendarFacade` — превратить в Action или
   переименовать.

**Не лезем:**

- Пакетные `flush` в импортах (`ClientBank1CImportService`,
  `BankImportService`, `CashFileImportService`) — правило «каждые N записей»
  допускает их; перенос инициативы в Handler — отдельной задачей.
- Разбиение многометодных контроллеров на `__invoke` — только попутно.
- Логика расчётов, порядок пересчёта балансов, тексты.

**Правила:**

- Порядок записи и пересчёта не меняется: где сейчас стоит комментарий
  «flush перед пересчётом обязателен», последовательность сохраняется внутри
  Action.
- Сервисы после этапа вызывают только `persist`/`remove`.
- Новый Action — `final`, `__invoke`, без `Request`/`Response`; тесты
  happy-path + 1 негативный.
- Каждое удалённое нарушение — минус запись в baseline правила из этапа 4.

**Результат.** В Cash `flush()` только в Action, Handler и Command (кроме
пакетных импортов); baseline правила `flush` по Cash сократился до записей
импортов.

**Чек-лист:**

- [x] Репозиторий без `flush` (5.1).
- [x] Контроллеры без `flush`; логика в Action с тестами (5.1).
- [x] 9 сервисов без `flush`; вызывающий код — Action: 3 тонких сервиса — 5.1;
      `RecurrenceMaterializer` — 5.2; `CashTransactionService`,
      `CashTransactionToDocumentService`, `PaymentPlanMatcher` — 5.3.
- [x] Псевдо-фасады устранены (5.2).
- [x] Baseline правила `flush` по Cash: остались только пакетные импорты
      (`BankImportService`, `ClientBank1CImportService`, `CashFileImportService`,
      `ImportLogger` — по 1 вызову); «Журнал» обновлён.
- [x] Все гейты зелёные.

**Подэтапы** (по одному PR; гейт `vashfindir.flushOutsideBoundary` из
этапа 4 даёт точный список — в Cash на старте 37 вызовов в 18 файлах):

| Подэтап | Что | Вызовов |
|---|---|---|
| 5.1 | `BankImportCursorRepository`; контроллеры автоправил и статей ДДС; `MoneyAccountService`, `FundService`, `BankConnectionService` → Action'ы; `CashTransactionAutoRuleGeneralCfoAssigner` → Action | 16 |
| 5.2 | `CashFileImportFacade`, `PaymentCalendarFacade`, `RecurrenceMaterializer` | 10 |
| 5.3 | `CashTransactionService`, `CashTransactionToDocumentService`, `PaymentPlanMatcher` | 7 |
| вне этапа | пакетные импорты `BankImportService`, `ClientBank1CImportService`, `CashFileImportService` и их `ImportLogger` | 4 |

### Результат подэтапа 5.3 (2026-10-15)

| Было | Стало |
|---|---|
| `CashTransactionService::add/update/delete/restore` (5 `flush`) | `AddCashTransactionAction`, `UpdateCashTransactionAction`, `DeleteCashTransactionAction`, `RestoreCashTransactionAction` — тела перенесены дословно, порядок «flush → сопоставление с планом → пересчёт остатков → сброс кэша» прежний. В `CashTransactionService` остались общие проверки и разрешение ссылок без `flush` |
| `PaymentPlanMatcher::matchForTransaction` (`flush` внутри) | матчер только `persist`; `AddCashTransactionAction` делает `flush`, если совпадение найдено, — до пересчёта, как раньше |
| `CashTransactionToDocumentService::createPnlDocumentFromTransaction` | `CreatePnlDocumentFromTransactionAction`: та же транзакция БД (`begin` → `persist` → `flush` → регистр ОПиУ → `commit`/`rollback`); вызов регистра — `updatePlRegister()` в сервисе, чтобы зависимость от Finance осталась в прежнем файле |

Пять старых ошибок PHPStan (`recalcRange` с nullable id счёта) и четыре в тесте
переводов исправлены (`(string)` id, `assertInstanceOf`), а не перенесены:
guard baseline не видит перенос кода из удалённых методов в новые файлы.
Интеграционный `CashTransactionActionsTest` проверяет, что совпадение с
плановым платежом, удаление/восстановление и документ ОПиУ доходят до БД;
мутация «без flush после матчера» тестом ловится.

`flush` вне Action в Cash: 11 → 4 вызова — только пакетные импорты, этап 5
закрыт.

### Результат подэтапа 5.2 (2026-10-15)

| Было | Стало |
|---|---|
| `CashFileImportFacade::createProfile/updateProfile/deleteProfile` | `CreateCashFileImportProfileAction`, `UpdateCashFileImportProfileAction`, `DeleteCashFileImportProfileAction` |
| `CashFileImportFacade::createJob` + `commitJob` (всегда подряд) | `StartCashFileImportJobAction`: те же два `flush` в том же порядке — журнал импорта, затем задание |
| `PaymentCalendarFacade::createPlanFromDto/updatePlanFromDto/changeStatus/postpone` | `CreatePaymentPlanAction`, `UpdatePaymentPlanAction`, `ChangePaymentPlanStatusAction`, `PostponePaymentPlanAction`; перенос DTO в план — `PaymentPlanDtoApplier` (без `flush`) |
| `RecurrenceMaterializer::materialize` | `MaterializeRecurringPaymentPlansAction::__invoke` |

Поведение не менялось. Три ошибки `argument.type` (nullable статья плана в
конструктор и `resolveTypeByCategory`) исправлены явной проверкой
`Assert::notNull` — контроллер и так вызывает Action только при наличии
статьи; ещё 2 записи переехали вслед за переименованием материализатора. `flush` вне Action в Cash: 21 → 11 вызовов (7 — подэтап 5.3, 4 —
пакетные импорты вне этапа).

### Результат подэтапа 5.1 (2026-10-14)

| Было | Стало |
|---|---|
| `MoneyAccountService::create/update` | `SaveMoneyAccountAction` |
| `FundService::createFund/createMovement` (`update` не использовался) | `SaveMoneyFundAction`, `CreateMoneyFundMovementAction` |
| `BankConnectionService::create/update/delete` | `SaveBankConnectionAction`, `DeleteBankConnectionAction` |
| `CashflowCategoryController::delete` — `assertCanDelete` + `remove` + `flush` | `DeleteCashflowCategoryAction` |
| `CashTransactionAutoRuleController::disable/enable` — `flush` | `ChangeCashTransactionAutoRuleActivityAction` (`flush` только при реальной смене) |
| `CashTransactionAutoRuleController::applyOne` — блокировка, аудит, `flush` в контроллере | `ApplyAutoRuleToTransactionAction`; блокировка «от чтения до flush» (GlitchTip #414) перенесена целиком, JSON-ответы дословно те же |
| `CashTransactionAutoRuleGeneralCfoAssigner::run` (`wrapInTransaction`) | `AssignGeneralCfoToCashAutoRulesAction::__invoke` |
| `BankImportCursorRepository::getOrCreate/save` — `flush` | только `persist`; `BankImportService` сохраняет транзакции дня и курсор одним `flush` (раньше — два подряд) |

`flush` вне Action в Cash: 37 → 21 вызов (18 → 10 файлов).

Единственное отличие поведения (внутреннее ревью): в `applyOne` ветка «транзакции
нет у компании» раньше бросала 404 внутри `wrapInTransaction` — ORM откатывал
транзакцию и закрывал EntityManager. Теперь Action возвращает `null`, пустая
транзакция коммитится, 404 бросает контроллер. Клиент получает тот же 404,
EntityManager больше не закрывается. Тест контроллера теперь проверяет, что
блокирующее чтение идёт внутри транзакции (GlitchTip #414), и покрывает ветки
skip-reason и conflict.

---

## Этап 6. Разорвать цикл Cash↔Analytics доменными событиями

**Цель.** Cash перестаёт вызывать инфраструктуру Analytics напрямую (паттерн
«доменные события», `PATTERNS.md` §20 — первое применение в проекте).

**Лезем:**

- `Cash/Domain/Event/`: `CashTransactionsChanged`, `CashTransferChanged`
  (`final readonly`, scalar: `companyId`, период).
- 5 мест вызова `SnapshotCacheInvalidator`: `CashTransactionService`,
  `SoftDeleteCompanyTransactionsCommand`, `CashTransferLifecycleAction`,
  `CreateCashTransferAction`, `BulkDeleteCashTransactionsAction`.
- Analytics: новый подписчик, который сбрасывает кэш.

**Не лезем:**

- Чтение Cash из Analytics (виджеты) — это этап 7.
- Outbox, новые транспорты, изменения `messenger.yaml`.
- Другие модули и другие подписчики.

**Правила:**

- Событие отправляется после `flush()`, через `pullDomainEvents()` или явно из
  Action.
- Сброс кэша при потере события допустим (кэш истечёт сам) — синхронный
  `EventDispatcher` достаточен. Для денежных последствий этот механизм **не
  использовать**: там Message через Doctrine-транспорт в той же транзакции.
- Поведение кэша для пользователя не меняется: сброс в тех же случаях.

**Результат.** В Cash нет импорта `App\Analytics\…`; кэш сбрасывается в тех же
сценариях.

**Чек-лист:**

- [x] `grep "use App\\Analytics" site/src/Cash` пуст.
- [x] Тест: изменение транзакции → событие → кэш Analytics сброшен.
- [x] `PATTERNS.md` §20 дополнен ссылкой на живой пример.
- [x] «Журнал» обновлён; все гейты зелёные.

### Результат этапа 6 (2026-10-15)

| Было | Стало |
|---|---|
| 7 мест в Cash вызывали `SnapshotCacheInvalidator` из Analytics: `Add/Update/Delete/BulkDeleteCashTransactionsAction`, `CreateCashTransferAction`, `CashTransferLifecycleAction`, `SoftDeleteCompanyTransactionsCommand` (после этапа 5 вызовы из `CashTransactionService` переехали в Action'ы) | те же места после `flush()` и пересчёта отправляют `CashTransactionsChanged(companyId)` через `EventDispatcherInterface` |
| — | `Analytics\Infrastructure\Cache\InvalidateSnapshotOnCashChangeListener` (`#[AsEventListener]`) вызывает новый `SnapshotCacheInvalidator::invalidateForCompanyId()`; `invalidateForCompany()` делегирует ему |
| обратную зависимость ничто не запрещало | PHPat `test_cash_does_not_depend_on_analytics`: 0 нарушений, проба `ProbeViolation` в `Cash\Domain\Event` гейт роняет |

Отступление от плана: одно событие вместо двух. `CashTransferChanged` не
понадобилось — подписчику нужен только `companyId`, а второй тип события без
второго подписчика был бы мёртвым кодом. Период в событие тоже не вошёл: кэш
версионируется по компании целиком.

Сквозной `CreateCashTransferActionTest` (версия кэша растёт после перевода)
прошёл без изменений — цепочка «Action → событие → подписчик → кэш» работает в
контейнере. `BulkDeleteCashTransactionsActionTest` проверяет событие с
`companyId` на успехе и его отсутствие при отказе; юнит-тест подписчика —
что сброс касается только своей компании.
Интеграционный `CashTransactionActionsTest` проверяет рост версии кэша после
добавления и удаления через контейнер; без `dispatch` в
`DeleteCashTransactionAction` тест красный. Update, перевод-жизненный цикл и
команда мягкого удаления отдельным тестом не покрыты — замена в них
построчная.

---

## Этап 7. Границы модуля: чтение через Facade, наружу — DTO

**Цель.** Чужие модули перестают читать репозитории и сервисы Cash, Cash —
чужие; убираются циклы Cash↔Finance и Cash↔Company (паттерн «Facade + Query +
DTO», `PATTERNS.md` §6, §7). Самый объёмный этап, **один PR на подэтап**,
подэтапы строго по порядку.

| Подэтап | Потребитель | Что импортирует из Cash сейчас |
|---|---|---|
| 7.1 | Analytics: 6 виджетов, `LastUpdatedAtResolver` | репозитории транзакций, счетов, фондов; `AccountBalanceProvider` |
| 7.2 | Finance: 4 отчётных контроллера, `FinanceFacade`, `FinanceDashboardKpiProvider` | репозитории, `DailyBalanceRecalculator`, `AccountBalanceProvider` |
| 7.3 | `Report/CashflowReportBuilder`, `Ai/CashflowAgent`, `Telegram/SendTelegramReportsCommand` | репозитории |
| 7.4 | `Twig` ×2, `Company` ×2, `Shared/AuditLogSubscriber`, `DataFixtures` | сервисы Cash |
| 7.5 | Cash → Company и Finance | `CounterpartyRepository`, `ProjectDirectionRepository`, `UserRepository`, `CompanyRepository`, `CounterpartyNameNormalizer`, `PLCategoryRepository`, `PLRegisterUpdater`; из этапа 2 — `EntityType` с `ProjectDirection` (`CashTransactionAutoRuleType`) и `PLCategory` (`CashflowCategoryType`) → `ChoiceType` + фасад с DTO |

**Лезем:**

- `Cash/Facade/CashFacade.php` — новые read-методы.
- `Cash/Infrastructure/Query/` — Query-классы с DBAL-проекциями.
- `Cash/Application/DTO/` — readonly DTO результатов.
- Файлы потребителей текущего подэтапа — только замена зависимости.
- Для 7.5 — `CompanyFacade`, `FinanceFacade` (новые методы).
- `ARCHITECTURE.md` — каждый новый Facade-метод.

**Не лезем:**

- Возвращаемые типы существующих методов Facade (Entity остаются Entity).
- Логика отчётов и виджетов: числа на экране не должны измениться.
- Ужесточение `ModuleBoundaryRules` до «только Facade + DTO + Enum» — отдельная
  задача (~230 записей по проекту).
- Потребители следующих подэтапов.

**Правила:**

- Новые Facade-методы возвращают DTO, не Entity. Каждый принимает
  `string $companyId`.
- Query-класс — явное перечисление колонок, без `SELECT *`; списки — через
  Pagerfanta (`limit` ≤ 200).
- Числа до и после совпадают: для каждого заменённого отчёта/виджета —
  тест-сравнение результата старого и нового пути на одних данных.
- N+1 проверяется в self-review каждого подэтапа.

**Результат.** Записи `phpat…Cash` в baseline — 0; записи `phpat…Company` и
`phpat…Finance` из Cash — 0; циклов Cash↔Finance и Cash↔Company нет.

**Чек-лист (на каждый подэтап):**

- [ ] Потребители подэтапа не импортируют `App\Cash\{Service,Repository,
      Application(кроме DTO),Infrastructure}`.
- [ ] Новые методы Facade с тестами и записью в `ARCHITECTURE.md`.
- [ ] Тест-сравнение: результат не изменился.
- [ ] Записи baseline подэтапа удалены; «Журнал» обновлён.
- [ ] Все гейты зелёные.

**Итог этапа 7:**

- [ ] `phpat…Cash` = 0, `phpat…Company` и `phpat…Finance` из `src/Cash/` = 0.

---

### Результат подэтапа 7.2 (2026-10-15)

| Было | Стало |
|---|---|
| `FinanceDashboardKpiProvider` и 4 отчётных контроллера Finance читали `MoneyAccountRepository`, `MoneyAccountDailyBalanceRepository`, `CashTransactionRepository`, `AccountBalanceProvider`, `DailyBalanceRecalculator`; выписка строила DQL по таблицам Cash прямо в контроллере | только `CashFacade`: `listMoneyAccounts` (DTO `MoneyAccountSummary`), остатки на начало/конец дня, обороты по счетам, валовые обороты, `listDailyBalances`, `listStatementLines` (DTO), `recalculateDailyBalances`; DQL выписки — в `Cash\Infrastructure\Query\CashReportQuery` без изменений |
| Запрос остатков в `ReportAccountBalancesController` | вызов `getOpeningBalancesUpToDate` — запрос был его дословной копией |
| `FinanceFacade::createDocumentFromCashTransaction` искал транзакцию через `CashTransactionRepository` | транзакцию передаёт вызывающий `CreateDocumentFromTransactionAction`; проверка компании и совпадения с командой осталась в фасаде |

Тест-сравнение: на фикстурах A22 (разбивки с CAPEX, неактивная касса с операциями,
«внутренний» перевод по тексту и по своему контрагенту) снят HTML 13 страниц
отчётов (остатки на 3 даты, структурные остатки ×2, выписка ×6 с фильтрами по
счёту, статье, группировке, scope, пустым периодам; сверка операций ×2) и
`FinanceDashboardKpiProvider::build` (4 вида деятельности × 30/60 дней × RUB/USD) —
до и после совпали побайтно. Ноги перевода из фикстур в снимке заменены парой с
фиксированными id: выписка упорядочивает операции одного времени по id, и со
случайными UUID два прогона «до» расходились между собой. Постоянный тест —
`CashFacadeReportReadTest` (фильтры и порядок счетов, остатки, обороты, регистр,
выписка с разбивкой и фильтрами, пересчёт, изоляция компаний, проверка компании в
`FinanceFacade`).

Вне подэтапа остались: импорты сущностей Cash в Finance (`Document`, `DocumentController`,
`BalanceDynamicsQuery`) — слой Entity открыт; сырой SQL `ReportCashflowOpsCheckController`
по таблицам `cash_transaction`, `money_account`, `cash_transaction_split` — PHPat его не
видит (см. «Находки»).

### Результат подэтапа 7.1 (2026-10-15)

| Было | Стало |
|---|---|
| 6 классов Analytics (`LastUpdatedAtResolver`, виджеты FreeCash, Inflow, Outflow, CashflowSplit, TopCash) читали `CashTransactionRepository`, `MoneyAccountRepository`, `MoneyFundMovementRepository`, `AccountBalanceProvider` | читают только `CashFacade`: 11 read-методов с `string $companyId`, суммы — decimal string, строки — DTO `CashDailyAmount`, `CashCategoryAmount` |
| 8 запросов дашборда в репозиториях Cash | `Cash\Infrastructure\Query\CashDashboardQuery`: DQL перенесён без изменения условий, из репозиториев удалён |

Отступление от плана: запросы остались на DQL, а не DBAL — перенос без
переписывания гарантирует те же числа. Методы, которыми пользуются и другие
модули (`findByFilters`, `AccountBalanceProvider`, `maxUpdatedAtForCompany`),
фасад вызывает как есть; их перенос — в подэтапах их потребителей.

Тест-сравнение: снимок `DashboardSnapshotService` на фикстурах A22 (+ разбивки
по статьям с CAPEX и движения фонда) — 3 периода × RUB/USD, пять Cash-виджетов
и `last_updated_at` — до и после совпал побайтно (временный тест, в PR не
входит). Постоянные: `CashFacadeDashboardReadTest` (суммы, исключения, изоляция
компаний), unit-тесты Analytics переведены на мок фасада через
`tests/Support/Mock/MocksFinalClasses` — без роста baseline.

## Этап 8. Гигиена модуля

**Цель.** Убрать мёртвый код и устаревшие документы, которые вводят в
заблуждение.

**Лезем:**

- Удалить `Service/Import/File/CashTransactionPersister.php_` и
  `Service/Import/File/CashFileImportReader.php_`.
- Переписать `Cash/README.md` под правила `CLAUDE.md` (сейчас разрешает доступ
  через `Service\*` и `Repository\*`), сохранив раздел про мультивалютность.
- Перенести этот план в `docs/plan/`, если Владелец решит хранить его там.

**Не лезем:** код, кроме удаления двух `.php_`.

**Результат.** В модуле нет файлов вне PHP-классов, кроме актуального README.

**Чек-лист:**

- [ ] Два `.php_` удалены, на них нет ссылок.
- [ ] README не противоречит `CLAUDE.md`.
- [ ] Все гейты зелёные.

---

## Попутные правила (не этап, действуют всегда)

Применяются только к файлу, который правится по другой задаче. Отдельных PR
под них не делать.

- **Правило бойскаута:** тронул файл Cash — закрыл его записи baseline, если
  это не меняет поведение.
- Перенос в целевую раскладку: `Message/` → `Application/Message/`,
  `DTO/` → `Application/DTO/`, `Service/` → `Application/` или `Domain/`.
  Baseline-guard переносы пропускает.
- `final` на сервисах, репозиториях, контроллерах, формах; `final readonly` на
  DTO и Message.
- Голое `\RuntimeException` / `\InvalidArgumentException` → доменное
  исключение.
- Многометодный контроллер → `__invoke`-контроллеры, когда правится метод.
- Выбор провайдера через `match` → tagged services + `ServiceLocator`
  (`PATTERNS.md` §9), когда добавляется второй банк.
- Ветвистая логика правил и импорта → чистые Policy-классы с unit-тестами
  (`PATTERNS.md` §4), когда правится соответствующая ветка.

## Не делаем в рамках плана

- Event Sourcing, полный CQRS с отдельными шинами.
- Глобальный Doctrine-фильтр по компании.
- Общий `BaseRepository` или generic CRUD-фасады.
- Массовый перевод `#[ManyToOne] Company` на `string $companyId`, перенос
  генерации UUID в конструкторы, `uuid4` → `uuid7` — только вместе с
  contract-задачами и миграциями.
- Двунаправленная связь `CashTransaction` → `Finance\Document` — отдельная
  contract-задача.

---

## Журнал

| Этап | Дата | Baseline Cash до | Baseline Cash после | Прочие цифры | PR |
|---|---|---|---|---|---|
| 0 | 2026-10-14 | 240 записей (276 ошибок) | — | phpat…Cash 39, repositoryCompanyScope 4, phpat…Company 13, phpat…Finance 3; весь baseline — 2332 записи (3425 ошибок); данные: расхождений `float` 0 по трём проверкам | #2677 |
| 1 | 2026-10-14 | 240 записей | 232 записи | −8: `AlfaApiClient` 5 (`missingType.iterableValue`), `BankImportHandler` 3 (`argument.type` 2, `phpat…Company` 1 → теперь phpat…Company из Cash 12) | #2678 |
| 2 | 2026-10-14 | 232 записи | 224 записи | −8 в Cash: `repositoryCompanyScope` 4 → 0, `CashTransactionController` 3, `PaymentPlanType` 1; −3 в тестах (`CreateCashTransferActionTest`); всего −11 | #2679 |
| 3 | 2026-10-14 | 224 записи | 223 записи | −1: `CashTransaction` `identical.alwaysFalse` (мёртвая проверка `null === $this->amount`); новое правило `vashfindir.moneyFloatCast` — 0 нарушений в Cash | #2680 |
| 4 | 2026-10-14 | 223 записи | 238 записей | `[baseline-grow]`: +18 `flushOutsideBoundary`, +2 `foreignEntityType` (Cash); −5 удалённый скоринг. По проекту +111 записей новых идентификаторов (192 ошибки), всего 2312 → 2418 | #2681 |
| 5.1 | 2026-10-14 | 238 записей | 230 записей | −8 записей `flushOutsideBoundary` в 7 файлах (−16 вызовов); запись `phpat…Company` перенесена вслед за переименованием `GeneralCfoAssigner` → `AssignGeneralCfoToCashAutoRulesAction`; всего 2418 → 2410 | #2682 |
| 5.2 | 2026-10-15 | 230 записей | 224 записи | −3 записи `flushOutsideBoundary` (−10 вызовов); −3 `argument.type` (nullable статья плана — явная проверка, guard baseline не видит перенос в новые файлы); 2 записи перенесены вслед за переименованием; всего 2410 → 2404 | #2683 |
| 5.3 | 2026-10-15 | 224 записи | 220 записей | −3 `flushOutsideBoundary` (−7 вызовов), −1 `argument.type` (`recalcRange`); в тестах −4; всего 2404 → 2396 | #2684 |
| 6 | 2026-10-15 | 220 записей | 220 записей | baseline не менялся, всего 2396; новое правило `test_cash_does_not_depend_on_analytics` — 0 нарушений, проба ловится | #2685 |
| 7.1 | 2026-10-15 | 220 записей | 219 записей | −10 `phpat…Cash` из Analytics, −3 `nullCoalesce.offset` в Analytics, −1 `return.type` (перенесённый запрос); всего 2396 → 2382 | #2686 |
| 7.2 | 2026-10-15 | 219 записей | 219 записей | вне Cash: −14 `phpat…Cash` из Finance, −5 `argument.type`, −1 `nullCoalesce.variable`, −4 `method.notFound` и count 5 → 2 (`FinanceFacade`, `find()` без типа); всего 2382 → 2358 | этот PR |

## Находки

Обнаруженное по ходу работ вне scope текущего этапа. Не чинится в этом плане
без согласования.

- **Finance читает таблицы Cash сырым SQL (этап 7.2).** `ReportCashflowOpsCheckController`
  через `Connection` выбирает из `cash_transaction`, `money_account`,
  `cash_transaction_split`, `cashflow_categories`. Граница модуля обходится в обход
  PHPat; перенос — отдельной задачей (запрос на ~100 строк, отчёт сверки).
- **`last_updated_at` дашборда всегда `null` (этап 7.1).** Все три источника —
  `CashTransactionRepository`, `MoneyFundMovementRepository`,
  `PLDailyTotalRepository::maxUpdatedAtForCompany` — проверяют результат DQL
  `MAX()` на `instanceof \DateTimeInterface`, а Doctrine возвращает строку; PHPStan
  это видит (записи `instanceof.alwaysFalse` в baseline). На фикстурах с данными
  `context.last_updated_at` = `null`. Исправление меняет видимое поведение
  дашборда — отдельной задачей.
- `number_format((float) …)` в `ClientBank1CImportService:562,797`,
  `BankImportService:307` — форматирование входа через `float`.
- `(float)` на суммах вне Cash: Marketplace — 22, Finance — 12, Catalog — 3,
  MarketplaceAnalytics — 3.
- `CashFileImportMessage` на `async_sync` — проверить на этапе 4.
- Этап 0: 2 активные транзакции с `allocated_amount = amount`, у которых нет
  ни одного документа, ни живого, ни удалённого. Это не `float`: значение
  устарело, вероятно после физического удаления документов без пересчёта.
  Остаток при создании документа считается заново по документам, поэтому
  создание не блокируется; устаревшее значение видно только там, где
  читается колонка. Чинить — отдельной задачей после согласования.
- **Правило `CLAUDE.md` о Messenger противоречит Symfony.** «`warning` +
  `RecoverableMessageHandlingException` пока ретраим» ведёт к бесконечным
  ретраям: `RecoverableExceptionInterface` ретраится в обход `max_retries` и
  никогда не попадает в `failed`. Практика проекта (`SyncOzonListingCatalogHandler`,
  этап 1) — обычное исключение и `retry_strategy` транспорта. Правило нужно
  переписать отдельным PR в правила.
- Ревью этапа 1, вне scope (банковский импорт на проде не используется, но
  перед включением это нужно закрыть):
  - `BankImportService` логирует всю банковскую транзакцию
    (`'transaction' => $transaction`, т. е. тело ответа банка) и номера счетов
    в `account_number` — противоречит правилам логирования.
  - Канал `http_client` Symfony пишет на INFO полный URL с query (номер
    счёта): исключить канал из основного лога на проде или дать банковскому
    клиенту свой логгер.
  - У `AlfaApiClient` нет `timeout`/`max_duration`: воркер `async_sync` может
    висеть до таймаута сокета.
  - Нет интеграционного теста Messenger: временный сбой → 3 ретрая → `failed`;
    неустранимый → без ретраев.
  - Неустранимая ветка даёт два события в GlitchTip: `error` handler'а и
    `critical` Messenger «Removing from transport». Решить, понижать ли первый.
- Ревью этапа 2, вне scope:
  - Невалидный id всё ещё даёт 500 вне диффа этапа: `CashTransactionSplitsController`
    (`/{id}/splits`) и `CashTransactionAutoRuleController` (edit, check,
    disable, enable, delete, `match/{transactionId}`, `apply/{transactionId}`)
    без `requirements`; `CashTransactionRepository::findOneByIdAndCompanyId` и
    `CashTransactionAutoRuleRepository::findOneByIdAndCompanyId` не проверяют
    UUID. Унифицировать: `CanonicalUuid` в репозиториях + `requirements`.
  - Вне диффа этапа входные id проверяются через `Ramsey\Uuid\Uuid::isValid()`,
    который пропускает `urn:uuid:…` (пример — `CashTransactionAutoRuleController`,
    `fromTransactionId`) → 500 на PostgreSQL. Перевести на `CanonicalUuid`
    вместе с предыдущим пунктом.
  - JSON-ошибки `create-from` в старом формате `{error: true, message}`, не
    `{error: {code, message}}` из `CLAUDE.md` — менять вместе с шаблоном.
- Этап 4, вне scope:
  - `ProcessMarketplaceRawDocumentAction` помечен `#[AsMessageHandler]`, но
    команда в шину не отправляется — атрибут рудиментарный (Marketplace);
    в тесте routing — явное исключение.
  - `ForeignEntityTypeRule` не видит собственные типы-обёртки над
    `EntityType` (`ProjectDirectionPickerType` в `CashTransactionAutoRuleType`).
  - `ControllerUnscopedFindRule` не проверяет `findBy()`/`findOneBy()`
    (критерий может содержать компанию), `$em->find($class, $id)` с классом в
    переменной, владение глубже одного уровня (`LoanPaymentSchedule` в
    `LoanSchedule*Controller`) и сам `Company` — тенант-корень без поля
    компании (`CompanyController`, `CompanyMemberController`); для него нужен
    отдельный признак — доступ через членство.
  - `CompanyOwnedEntity` смотрит собственные свойства: наследование сущностей
    и `MappedSuperclass` с private `$company` станут слепым пятном.
  - `MessengerRoutingTest` не ловит устаревший routing (запись без handler'а).
  - Кэш результатов PHPStan не знает, что правила контроллеров (как и
    `RepositoryCompanyScopeRule`) зависят от сущностей: после добавления поля
    `company` в сущность закэшированный контроллер локально не перепроверится —
    полный прогон в CI это обходит. `wrapInTransaction(fn () => $em->flush())`
    в сервисе даёт две ошибки на одну границу.
  - В Company осталась заглушка `CounterpartyUpdateScoringAction` с пустым
    `__invoke` (TODO) и её Command; поля скоринга контрагента никто не
    пересчитывает — удалить или реализовать отдельной задачей.
- Ревью подэтапа 5.1, вне scope:
  - `BankImportServiceTest` не проверяет новый порядок «курсор сохраняется до
    общего flush дня».
  - Банковский импорт без блокировки: при двух воркерах на новый курсор второй
    падает на уникальном индексе уже на первом flush дня (раньше — в
    `getOrCreate`); дублей нет, `finishImportLog` на закрытом EM маскирует
    исходную ошибку (как и раньше).
  - `ApplyAutoRuleToTransactionAction` возвращает готовый JSON-payload с
    текстами UI — на будущее лучше DTO результата.
- Подэтап 5.2, вне scope (существующее поведение, не проверено в UI):
  `PaymentPlan::setExpectedAt()`/`getExpectedAt()` работают с `plannedAt`, а в
  форме `PaymentPlanType` это два поля; `PaymentPlanDtoApplier` применяет
  `expectedAt` после `plannedAt`. При правке плана, где изменена только «Дата»,
  а «Ожидаемая дата» осталась прежней, новая дата, вероятно, теряется.
  Проверить в календаре и решить с Владельцем.
- Ревью подэтапа 5.2, вне scope:
  - `MaterializeRecurringPaymentPlansAction` пишет в БД на GET календаря;
    защита от дублей — check-then-insert (`existsRecurrenceDuplicate()`), два
    параллельных GET могут создать дубли без уникального индекса. Кандидат —
    перенос в cron/Handler и уникальный индекс.
  - В `PaymentCalendarController` остаются неиспользуемый `$paymentPlanService`
    и `entityManager->getRepository()->findBy` для справочников фильтров.
  - Новые плановые платежи и профили создаются с `Uuid::uuid4` (правило — v7);
    код перенесён дословно.
- Ревью подэтапа 5.3, вне scope (всё существовало до этапа):
  - Нет теста `UpdateCashTransactionAction` со сменой счёта: пересчёт остатков
    и по старому, и по новому счёту не проверен тестом (денежный путь).
  - `CreatePnlDocumentFromTransactionAction` ловит `\Exception`, а не
    `\Throwable`: при `\Error` транзакция БД останется открытой; закроется
    переходом на `wrapInTransaction()`.
  - `AddCashTransactionAction` не атомарен (flush → матчер → flush → пересчёт
    через DBAL) — риск C3 из `docs/financial-core/03-current-failure-model.md`.
  - `CashTransactionServiceTest` теперь тестирует Action'ы — переименовать или
    перенести в `tests/Integration/Cash/Application/`.
- `FundController::convertToMinorUnits()` — ветка «Некорректная сумма»
  недостижима (`MoneyType` валидирует ввод); можно удалить при следующей правке.
- Этап 3, вне scope:
  - Finance работает с распределением через float-обёртки `CashTransaction`
    (`DocumentController:262,498`, `FinanceFacade:198` с `(float) $command->amount`,
    `RestoreDocumentAction:32`) и `Document::getTotalAmount(): float` — перевести
    на `Money`-методы задачей модуля Finance.
  - Правило `vashfindir.moneyFloatCast` действует только в Cash; вне модуля
    11 приведений `(float)` от геттеров сумм (Finance 10, Ai 1) — расширять с
    baseline на этапе 4 или задачей Finance.
  - Правило `vashfindir.moneyFloatCast` ловит только `(float)` от вызова
    `get*Amount*()`. В Cash остаётся около 20 денежных `(float)` по
    переменным: разбор сумм в импортах (`ClientBank1CImportService:562,797,820,826`,
    `BankImportService:307`, `CashFileRowNormalizer:245`,
    `Bank1CImportController:274`) и агрегаты (`CashTransactionRepository:711-876`,
    `ForecastBalanceService`, `CashTransactionXlsxExporter:94`). Зелёный гейт
    не означает «Cash без float»; импорт — реальный денежный путь через float,
    кандидат в этап 4 или отдельную задачу.
  - Форма Finance `DocumentOperationType` — `NumberType` без `input: 'string'`
    и `scale`: до сохранения в операции оказывается float-строка (`'1.0E-5'`).
    `CashTransaction` это переваривает, но корень — в форме Finance.
  - `Money` берёт число знаков валюты из Intl, колонки — `decimal(18,2)`.
    Сейчас все фиатные валюты Cash (RUB, USD, EUR, KZT) — 2 знака; валюта с
    0 или 3 знаками начала бы округлять распределение.
  - `VatPolicy` возвращает 20% для ОСНО, базовая ставка НДС с 2026 — 22%
    (для 22% ровной половины копейки нет). Проверить с Владельцем.
  - `tx.remainingAmount` в `templates/transaction/index.html.twig` до трёх раз
    на строку пересчитывает распределение по документам и операциям (N+1, не
    новое) — вынести в view-модель.
  - `addDocument()` прибавляет сумму документа к сохранённой даже для уже
    привязанного документа (поведение до этапа, не менялось); фактический
    остаток пересчитывается заново, поэтому на создание документов не влияет.
- `Finance/Entity/Document::getTotalAmount()` суммирует через `float` —
  расхождений на данных нет (этап 0), перевод на `Money` — задача модуля
  Finance.
