# CLAUDE.md — VashFinDir

> Читается Claude Code автоматически. Workflow и полномочия — `AGENTS.md`
> (читается всегда: там же единая карта файлов с триггерами «когда читать»),
> паттерны с кодом — `PATTERNS.md`, живые контракты — `ARCHITECTURE.md`.
> Здесь только backend-правила и то, чего нет в `AGENTS.md`.

## Workflow — `AGENTS.md`, здесь не дублируется

Ключевое: один вопрос Владельцу за задачу — «merge and deploy #N» на handoff
(§3.2); всё до handoff pre-authorized (§3.1); STOP только по списку §3.4.
`CLAUDE.md` ниже `AGENTS.md` и не может вводить ручной STOP, которого там нет.
Задача по React / TypeScript / Vite / вёрстке → прочитать `CLAUDE.frontend.md`;
правила ниже к фронту не применяются, workflow — тот же.

## Перед кодом

1. `ARCHITECTURE.md` — раздел затронутого модуля и общие разделы, которых
   касается задача (Messenger, Cron, конфигурация); весь файл не читать.
   Актуальные Facade-методы, Enum, Entity — оттуда; существующие контракты не
   выдумывать. В разделы контрактов писать только текущее состояние; история
   этапов («Stage N добавил…») — строкой в `Changelog` того же файла.
2. Модуль вывести из соседнего кода; спросить только при материальной
   неоднозначности.
3. Новый Facade, метод, Enum или Entity, однозначно следующий из ТЗ, — создать
   в scope и обновить `ARCHITECTURE.md` в том же Stage.
4. Нужен паттерн — соответствующий раздел `PATTERNS.md`.

## Legacy-зона

`src/Entity/`, `src/Service/`, `src/Repository/`, `src/Controller/`: новые
файлы не создавать. Правка там — HIGH-LOCAL: только когда прямо требуется
задачей, с усиленными тестами и review; не STOP. Структура нового модуля —
`docs/workflow/new-module.md`.

## Правила PHP

- Каждый файл начинается с `declare(strict_types=1)`; гейт
  `make site-cs-strict-types`. Ветку, созданную до миграции на strict_types,
  обновить из `master` до `make site-cs-fix`, иначе fixer впишет объявление в
  чужие файлы и включит там строгую проверку скаляров.
- Конструктор: `private readonly`, только constructor injection.

| Модификатор | Когда |
|---|---|
| `class` | Entity — Doctrine proxy наследуется, `final` это ломает |
| `final class` | Builder, Action, Controller, Facade, Repository, Query, Handler |
| `final readonly class` | DTO, Message, stateless-сервисы |
| `enum` | без `final` — enum implicitly final |

### Entity

- ID — UUID v7. Его передаёт вызывающий код (Action, импорт — ради
  идемпотентности и дедупликации, Builder в тестах) либо генерирует конструктор
  при `?string $id = null`: `$id ?? Uuid::uuid7()->toString()`. Переданный ID —
  `Assert::uuid()`. `#[ORM\Table(name: ...)]` всегда.
- `string $companyId` без setter'а, `Assert::uuid()`; не `#[ManyToOne] Company`.
- Ссылки на чужие модули — `string $counterpartyId`, не `#[ManyToOne]`.
- Entity защищает свои инварианты в конструкторе и в методах изменения
  состояния (`close()`, `replaceSplits()`, `changeStatus()` с доменным
  событием). Внутри Entity нельзя: Repository, сервисы, Messenger, EntityManager,
  изменение других агрегатов.
- `DateTimeImmutable`, не `DateTime`. Паттерн — `PATTERNS.md` §11.

### IDOR — проверяется в ревью первым

- Каждый метод-запрос Repository принимает `string $companyId` либо ненулевой
  объектный `$company`. Компания внутри DTO ограничением не считается.
- Контроллер: `$this->activeCompanyService->getActiveCompany()` до обращения к
  данным. `$repo->find($id)` без компании — запрещено.
- Машинно: `site/tests/PHPStan/Rules/RepositoryCompanyScopeRule.php` ловит
  публичные `find*`/`get*`/`count*`/… без параметра компании в репозиториях
  сущностей, принадлежащих компании. Единственное санкционированное исключение —
  `@companyScopeExempt <причина>` в docblock.
- В контроллерах: `ControllerUnscopedFindRule` ловит `find()`/`findAll()`
  сущности компании, `ControllerEntityArgumentRule` — сущность компании
  аргументом действия (Entity value resolver грузит её без компании).
- Правила — эвристика: не видят неиспользуемый параметр, владение глубже одного
  уровня, сам `Company`, `findBy()`/`findOneBy()` в местах вызова и вызовы вне
  контроллеров.
  Зелёный гейт не отменяет ручную проверку. Паттерн — `PATTERNS.md` §14.

### Слои

| Слой | Правила | `PATTERNS.md` |
|---|---|---|
| Controller | один action, `__invoke`, `#[Route]`-атрибуты, только HTTP in/out | §2 |
| Action | `final`, `__invoke`, без `Request`/`Response`; граница транзакции — `flush()` / `wrapInTransaction()` здесь (а также в MessageHandler и консольной Command, см. «Транзакции») | §3 |
| Facade | единственная точка входа между модулями; чужие `Service/`, `Repository/`, `Infrastructure/`, `Application/` не импортировать, кроме `Application/DTO` — это типы контракта Facade; проверяется `site/tests/Architecture/ModuleBoundaryRules.php`; новый Facade или метод → `ARCHITECTURE.md`. Новый Facade — интерфейс `{Name}FacadeInterface` + `final` реализация, потребители зависят от интерфейса (образец — `MarketplaceAnalyticsFacadeInterface`). Новый метод Facade возвращает DTO, Enum или скаляры, не Entity; существующие методы с Entity не переделывать без задачи | §7 |
| Message / Handler | `readonly class` со scalar ID; routing в `config/packages/messenger.yaml`; ровно один транспорт — см. таблицу ниже; Handler без `Request`/`Session`/`Security` | §10 |
| Формы | `ChoiceType` с данными из Facade, не `EntityType` с чужой Entity | §8 |

| Транспорт | Когда |
|---|---|
| `async_sync` | Внешние HTTP: маркетплейсы, банки, e-mail, MoySklad |
| `async_pipeline` | Локальная DB/CPU-обработка уже загруженных данных |
| `async_ads` | Ozon Performance polling, сообщение может работать до 10 мин |
| `async_wb_finance` | Только `SyncWbFinancialReportDayMessage`: ретраи до 20 раз под лимиты WB |
| `ingest_fetch` | Ingestion: загрузка из внешнего источника (DSN общий с `async_sync`) |
| `ingest_normalize` | Ingestion: нормализация сырых записей (DSN общий с `async_pipeline`) |

Воркеры и текущий routing — `ARCHITECTURE.md`, «Messenger: транспорты и
воркеры». Изменение `messenger.yaml` — HIGH-LOCAL: проверить routing,
retry/failure, совместимость сообщений, усиленный review; без STOP.

### Транзакции

- `flush()` и `wrapInTransaction()` — только в Action, MessageHandler и
  консольной Command. В ORM 3 `flush()` сохраняет весь UnitOfWork, поэтому
  `flush()` в сервисе или Repository молча сохраняет чужие незавершённые
  изменения.
- Пакет больше 100 записей: сервис отдаёт порции (генератор или callback на
  порцию), `flush()` и `clear()` вызывает Action/Handler/Command каждые N
  записей.
- Сервис и Repository только меняют сущности, `persist()` и `remove()`.

## Глобальные запреты

```
dump() / dd() / var_dump()                — нельзя в коммитах
new SomeService()                         — только constructor injection
flush() в Repository / Service / Controller — только Action, Handler, Command
хардкод секретов / URL / API-ключей       — только через .env
бизнес-логика в Controller                — вынести в Action
Repository / сервисы / EM внутри Entity   — Entity держит только свои инварианты
import Service/Repository чужого модуля   — только через Facade
import Application/ чужого модуля         — только Application/DTO
#[ManyToOne] на Entity чужого модуля      — только string $entityId
EntityType с чужой Entity в формах        — только ChoiceType + Facade
SELECT * в raw SQL                        — явное перечисление колонок
циклические зависимости между модулями    — нельзя
операции ОПиУ из Ingestion                — модуль их не создаёт, гейт ModuleBoundaryRules
getRepository() чужого модуля             — только через Facade
```

## Тесты — минимум

| Сделано | Написать в том же Stage |
|---|---|
| Новый Action | happy-path тест + 1 негативный |
| Новый Domain Policy | unit-тесты на все ветки |
| Новая Entity | Builder в `tests/Builders/{Module}/` |
| Исправление бага | регрессионный тест, красный на старом коде и зелёный на новом |
| Новый Facade-метод | functional-тест через вызывающий код или unit на Facade |

Work item без нужных тестов не завершён. Паттерны — `PATTERNS.md` §16, §17.

## Новый модуль

`src/{Module}` требует правок в `config/routes.yaml`,
`config/packages/doctrine.yaml`, `config/packages/twig.yaml` и при async
Messages — `messenger.yaml`. Готовые блоки — `docs/workflow/new-module.md`.

## Обработка ошибок

- Доменные исключения в `src/{Module}/Exception/`: `final class`, extends
  `\RuntimeException` или `AppException`, имя по причине
  (`DocumentNotFoundException`).
- Поток: Domain/Action бросает → ExceptionListener маппит в HTTP-статус и JSON
  → в JSON-контроллере нет `try/catch`, кроме технических кейсов (stream,
  lock, временный файл).
- HTML-контроллер с Twig-формой может ловить **конкретное** доменное
  исключение, чтобы показать flash или ошибку формы и сделать redirect: иначе
  пользователь получит 500 вместо сообщения. `catch (\Throwable)` /
  `catch (\Exception)` ради flash — нельзя; если такой catch нужен
  технически — `logger->error` с `companyId` до flash.
- Формат ответа для нового кода:

```json
{ "error": { "code": "document_not_found", "message": "Документ не найден" } }
```

`code` — стабильный snake_case-идентификатор, `message` — человекочитаемый,
HTTP-статус несёт семантику: 404 / 422 / 403 / 500.

```
try/catch с пустым телом          — глотать исключения нельзя
throw new \Exception('ошибка')    — только конкретные доменные классы
return null вместо исключения     — обязанная существовать сущность бросает
```

Паттерн — `PATTERNS.md` §13.

## Логирование

Sentry (GlitchTip) получает только `ERROR`+. Monolog — структурированные
INFO/DEBUG.

| Уровень | Когда |
|---|---|
| `ERROR` | Инцидент: неустранимый сбой, баг, повреждение данных — нужно будить человека |
| `WARNING` | Ожидаемо и обрабатывается само: retry, 429, таймаут, невалидный вход + skip |
| `INFO` | Бизнес-события: старт/финиш async-задачи, отправка документа, импорт завершён |
| `DEBUG` | Только локально |

- Messenger: `warning` + `RecoverableMessageHandlingException` пока ретраим;
  `error` + `UnrecoverableMessageHandlingException` только на неустранимом.
- Обязательно: старт и финиш каждого MessageHandler с ID и `companyId`;
  внешние HTTP — метод, URL, статус, время, без тела. В циклах — один
  агрегированный `error` со счётчиком, не по записи.
- Запрещено: пароли, токены, ключи даже в DEBUG; ФИО и ИНН без явного
  требования; тело ответа внешних API.
- Детали и примеры — `PATTERNS.md` §23; мониторы —
  `docs/workflow/health-gates.md`.

## Производительность

- N+1 чинить в текущем Work item; в Stage self-review проверяется явно.
- Списки только через Pagerfanta: `?page=1&limit=50`, максимум 200, сверх →
  422; `OutOfRangeCurrentPageException` → 422 в ExceptionListener.
- Query-класс: список для UI — отдаёт `QueryBuilder` под Pagerfanta; агрегат
  или отчёт (SUM, GROUP BY, проекции) — readonly-DTO или `list<DTO>`.
  Нетипизированный `array` из нового Query — нельзя.
- Новое FK-поле в Entity → индекс в миграции; составной `(company_id, field)`,
  если фильтр всегда по компании.
- Batch больше 100 записей — `flush()` каждые N итераций в вызывающем
  Action/Handler/Command (см. «Транзакции»). Query без `companyId` запрещён:
  IDOR и полный скан.

## Гейты качества

| Команда | Что |
|---|---|
| `make site-stan` | PHPStan level 8, baseline `site/phpstan-baseline.neon` |
| `make site-cs-check` | PHP CS Fixer `@Symfony` + risky |
| `make site-cs-strict-types` | только `declare_strict_types` |
| `make site-test-unit` / `make site-test` | unit / полный набор |

- Baseline только сокращать. Рост блокирует CI; осознанный рост — метка
  `[baseline-grow]` в заголовке PR. `make site-stan-baseline` начисто стирает
  ratchet — не использовать вместо починки.
- Новый гейт (PHPStan/PHPat-правило) вводится одним PR вместе с baseline своих
  текущих нарушений: метка `[baseline-grow]`, в описании PR — идентификатор
  правила и число записей. Это не регресс; рост по другим идентификаторам в
  том же PR не допускается. Дальше записи нового правила только сокращаются.
- Красный baseline до задачи — не регресс и не индульгенция: проверять
  изменённые файлы точечно и записать факт с цифрой.
- Устройство гейтов, обязательные job'ы и branch protection —
  `docs/maintenance/quality-gates.md`.

### Правило без гейта — правило ревью

Правило из этого файла либо проверяется машинно, либо проверяется только в
ревью — и это надо знать. Сейчас машинно: `strict_types`, CS, границы модулей и
легаси-зона (`ModuleBoundaryRules`), область компании в Repository
(`RepositoryCompanyScopeRule`) и в контроллерах (`ControllerUnscopedFindRule`,
`ControllerEntityArgumentRule`), `flush()` вне Action/Handler/Command
(`FlushOutsideTransactionBoundaryRule`), `EntityType` с чужой сущностью
(`ForeignEntityTypeRule`), `(float)` от сумм в Cash (`MoneyFloatCastRule`),
routing каждого сообщения (`MessengerRoutingTest`), PHPStan level 8 с baseline.
Остальное — ревью. Правило, которое нарушает больше половины существующего кода, не
игнорируется и не применяется молча: его пересматривают (меняют формулировку
или вводят гейт с baseline) отдельным PR в правила.

## Практики

- Сначала подтвердить дефект в данных, потом трогать код. Сообщение о проде
  описывает симптом; если данные дефект не подтверждают — сказать и остановиться.
- Замеры «до» снимать до изменения: счётчики, суммы денежных полей, инварианты.
  После прогона восстановить их нечем.
- Миграции с данными — `docs/workflow/data-migrations.md`. Необратимая миграция
  требует бэкапа и явного упоминания в запросе «merge and deploy».
- После деплоя миграции сверить эффект, счётчики «до», инварианты, висячие
  ссылки. Расхождение сначала объяснить, потом докладывать.

## Design System

Эталон визуала обоих интерфейсов — дизайн-система «Ваш Финдир»:
листы `docs/design-system/sheets/`, решения и расхождения —
`docs/plan/ds-legacy-tabler-audit.md`. Реализация и правила (в т. ч. деньги:
`vf_money`/`vf_amount`) — `CLAUDE.frontend.md`.
