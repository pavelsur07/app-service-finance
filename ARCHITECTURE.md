# Архитектурные правила проекта VashFinDir

> **Версия:** 1.0  
> **Дата:** 2026-03-26  
> **Аудитория:** разработчики (люди) и ИИ-ассистенты (Claude и др.)  
> **Стек:** Symfony 7, PHP 8.3+, PostgreSQL, Redis, Doctrine ORM, Messenger

---

## 1. Модульная структура (`src/`)

Проект организован по **доменным модулям** (bounded contexts). Каждый модуль — самостоятельная папка первого уровня в `src/`.

### 1.1. Список модулей

| Модуль | Назначение |
|---|---|
| `Cash` | Денежные счета, транзакции, банковский импорт, план платежей |
| `Marketplace` | Интеграции WB/Ozon, продажи, возвраты, расходы, закрытие месяца |
| `Catalog` | Товары, штрихкоды, закупочные цены |
| `Deals` | Сделки |
| `Finance` | PnL-отчёты, кэшфлоу, фасады финансовой аналитики |
| `Company` | Компании, пользователи, приглашения, тарифы |
| `Balance` | Управленческий баланс, провайдеры значений |
| `Billing` | Биллинг и подписки |
| `Loan` | Кредиты и займы |
| `Ai` | Интеграция с LLM |
| `Telegram` | Telegram-бот, вебхуки |
| `MoySklad` | Интеграция с МойСклад |
| `Analytics` | Аналитические запросы и дашборды |
| `MarketplaceAnalytics` | Аналитика маркетплейсов (витрина) |
| `Notification` | Каналы уведомлений (email, и т.д.) |
| `Shared` | Общий код: ActiveCompanyService, аудит, безопасность, storage |
| `Admin` | Административная панель (отдельный firewall) |

### 1.2. Legacy-зона (плоская структура)

Ниже перечислены области, которые **исторически** живут в плоском `src/Entity`, `src/Service`, `src/Repository`, `src/Controller` и т.д. Это **технический долг**. Новый код сюда **НЕ ДОБАВЛЯТЬ**.

Текущие legacy-сущности: `Document`, `DocumentOperation`, `PLCategory`, `PLDailyTotal`, `PLMonthlySnapshot`, `ProjectDirection`, `Counterparty`, `ReportApiKey`.

> **Правило:** при существенной доработке legacy-класса — переносить его в соответствующий модуль (например, `PLCategory` → `Finance/Entity/`, `Counterparty` → `Company/Entity/`). Перенос оформлять отдельным PR.

---

## 2. Внутренняя структура модуля

Каждый модуль **ДОЛЖЕН** следовать единой структуре папок. Разрешённые подпапки:

```
src/{Module}/
├── Controller/          # HTTP-контроллеры (тонкие, без бизнес-логики)
│   └── Api/             # JSON API-контроллеры
├── Entity/              # Doctrine-сущности
├── Repository/          # Doctrine-репозитории (только запросы к БД)
├── Application/         # Use-case классы: Action, Handler, Command (CLI)
│   ├── Command/         # Symfony Console Commands
│   ├── DTO/             # DTO, специфичные для use-case
│   ├── Processor/       # Пакетная обработка
│   ├── Service/         # Координирующие сервисы application-уровня
│   └── Source/          # Data-source классы (tagged iterators)
├── Domain/              # Чистая доменная логика: политики, value objects
│   ├── ValueObject/
│   └── Service/         # Доменные сервисы (без инфраструктуры)
├── Infrastructure/      # Реализации интерфейсов, внешние API, query-builders
│   ├── Api/             # HTTP-клиенты к внешним сервисам
│   ├── Query/           # Сложные read-модели (Doctrine DBAL / QueryBuilder)
│   ├── Repository/      # Альтернативное размещение репозиториев
│   └── Normalizer/      # Преобразование внешних данных
├── DTO/                 # Data Transfer Objects
├── Enum/                # PHP Enums
├── Facade/              # Публичный API модуля для ДРУГИХ модулей
├── Form/                # Symfony Form Types
├── Message/             # Messenger messages (async)
├── MessageHandler/      # Messenger handlers
├── EventSubscriber/     # Doctrine/Symfony event subscribers
├── Command/             # Альтернативное размещение Console Commands
└── Service/             # Legacy-сервисы (для нового кода → Application/)
```

### 2.1. Правила именования

| Элемент | Паттерн | Пример |
|---|---|---|
| Use-case Action | `{Verb}{Noun}Action` | `CreateProductAction` |
| Console Command | `{Module}{Verb}Command` | `MarketplaceSyncCommand` |
| Messenger Message | `{Verb}{Noun}Message` | `SyncWbReportMessage` |
| Message Handler | `{MessageName}Handler` | `SyncWbReportMessageHandler` |
| Facade | `{Noun}Facade` | `ProductFacade`, `CompanyFacade` |
| Repository | `{Entity}Repository` | `ProductRepository` |
| Query (read-model) | `{Noun}Query` | `ProductQuery` |
| Domain Policy | `{Noun}Policy` | `PurchasePriceTimelinePolicy` |
| DTO (команда) | `{Verb}{Noun}Command` | `CreateProductCommand` (DTO, не CLI) |
| DTO (фильтр) | `{Noun}Filter` | `ProductListFilter` |
| Enum | `{Noun}` или `{Noun}{Type}` | `ProductStatus`, `AuditLogAction` |
| Контроллер | `{Noun}{Verb}Controller` | `ProductEditController` |

---

## 3. Зависимости между модулями

### 3.1. Стратегия: два поколения модулей

Проект находится в процессе **разъединения модулей**. Существуют два поколения:

| | Старые модули (legacy) | Новые модули |
|---|---|---|
| Связь с Company | `#[ManyToOne] private Company $company` | `#[Column(type: 'guid')] private string $companyId` |
| Import из Company | `use App\Company\Entity\Company` в Entity | **Нет импорта** Company в Entity |
| Формы с данными другого модуля | `EntityType::class` с прямым `query_builder` | `ChoiceType` с данными из Facade |
| Примеры | Cash, Deals, Balance, Loan, legacy Entity | MoySklad, MarketplaceMonthClose, AuditLog, ProductImport |

**Правило для нового кода:** все новые Entity используют `string $companyId`. Старые Entity не переписываем без необходимости, но при крупном рефакторинге — мигрируем.

### 3.2. РАЗРЕШЕНО

- Модуль **может** зависеть от `Shared/`.
- Модуль **может** зависеть от **Facade** другого модуля.
- Модуль **может** зависеть от **Enum** другого модуля.
- Модуль **может** зависеть от **DTO** другого модуля, если это публичный DTO.
- **(Legacy, допустимо)** Старый модуль может иметь `#[ManyToOne] Company $company` в Entity. Это работает, но не создавать новых таких связей.

### 3.3. ЗАПРЕЩЕНО

- **Никогда** не импортировать `Service`, `Application`, `Repository`, `Infrastructure`, `Form`, `Controller` из чужого модуля напрямую.
- **Никогда** не вызывать `EntityManager->getRepository(SomeOtherModuleEntity::class)` из чужого модуля. Только через Facade.
- **Никогда** не создавать циклических зависимостей между модулями.
- **Никогда** (в новых модулях) не использовать `#[ManyToOne]` на Entity из чужого модуля. Только `string $companyId` / `string $counterpartyId` / и т.д.
- **Никогда** (в новых модулях) не использовать `EntityType::class` для сущностей из чужого модуля в формах. Только `ChoiceType` с данными из Facade.

### 3.4. Facade — публичный API модуля

Facade — единственная точка входа для межмодульного взаимодействия.

```php
// ✅ ПРАВИЛЬНО: Marketplace использует CompanyFacade
final class MarketplaceSyncCommand extends Command
{
    public function __construct(
        private readonly CompanyFacade $companyFacade,
    ) {}
}

// ❌ НЕПРАВИЛЬНО: прямое обращение к репозиторию чужого модуля
final class MarketplaceSyncCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,  // ЗАПРЕЩЕНО
    ) {}
}
```

**Правила Facade:**
- Класс `final readonly class {Name}Facade`
- Только read-методы или простые команды (делегирование)
- Без бизнес-логики внутри — только проксирование к внутренним сервисам
- Минимальный публичный интерфейс — не выставлять всё подряд

### 3.5. Формы и списки: работа с данными чужого модуля

Когда модулю A нужно показать в форме или списке данные из модуля B (например, выбор контрагента в сделке, выбор PL-категории в маппинге):

**Старый паттерн (ЗАПРЕЩЕНО в новых модулях):**

```php
// ❌ CreateDealType.php — прямой импорт Entity + EntityType + query_builder
use App\Entity\Counterparty;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

$builder->add('counterpartyId', EntityType::class, [
    'class' => Counterparty::class,
    'query_builder' => static function (EntityRepository $repo) use ($company) {
        return $repo->createQueryBuilder('c')
            ->where('c.company = :company')
            ->setParameter('company', $company);
    },
]);
```

Проблемы: модуль Deals жёстко зависит от Entity и Repository модуля Company. Нельзя рефакторить Counterparty без ломки Deals.

**Новый паттерн (ОБЯЗАТЕЛЕН для новых модулей):**

**Шаг 1.** Facade в модуле-владельце возвращает простые DTO:

```php
// src/Company/Facade/CounterpartyFacade.php
final readonly class CounterpartyFacade
{
    public function __construct(private CounterpartyRepository $repository) {}

    /**
     * @return list<array{id: string, name: string}>
     */
    public function getChoicesForCompany(string $companyId): array
    {
        return $this->repository->findChoicesForCompany($companyId);
    }
}
```

**Шаг 2.** Controller получает данные через Facade и передаёт в форму/шаблон:

```php
// src/Deals/Controller/DealCreateController.php
final class DealCreateController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CounterpartyFacade $counterpartyFacade,
    ) {}

    #[Route('/deals/new', name: 'deal_new')]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->companyService->getActiveCompany()->getId();

        // Facade возвращает простой массив — нет зависимости на Entity
        $counterpartyChoices = $this->counterpartyFacade->getChoicesForCompany($companyId);

        $form = $this->createForm(CreateDealType::class, null, [
            'counterparty_choices' => $counterpartyChoices,
        ]);
        // ...
    }
}
```

**Шаг 3.** Форма использует `ChoiceType` вместо `EntityType`:

```php
// src/Deals/Form/CreateDealType.php
$builder->add('counterpartyId', ChoiceType::class, [
    'label'       => 'Контрагент',
    'required'    => false,
    'placeholder' => 'Без контрагента',
    'choices'     => array_column($options['counterparty_choices'], 'id', 'name'),
    // или flip если нужно: ключ — label, значение — id
]);
```

**Результат:** модуль Deals не импортирует ничего из `App\Entity\Counterparty`, не знает про Doctrine-репозиторий контрагентов, не использует `EntityType`. Зависимость только на Facade (стабильный контракт).

**Паттерн для отображения имени по ID (в списках и show-страницах):**

Если Entity хранит `string $counterpartyId` и нужно показать имя:

```php
// В контроллере или Twig-расширении
$counterpartyNames = $this->counterpartyFacade->getNamesByIds($counterpartyIds);
// Передать в шаблон как маппинг: ['uuid-1' => 'ООО Ромашка', 'uuid-2' => 'ИП Иванов']
```

```twig
{# В шаблоне #}
{{ counterparty_names[deal.counterpartyId] ?? '—' }}
```

---

## 4. Контроллеры

### 4.1. Общие правила

- **Один action на контроллер** (invokable: метод `__invoke`).
- Контроллер **тонкий**: извлечь данные из Request → передать в Action/Service → вернуть Response.
- Максимум бизнес-логики в контроллере: **0 строк**. Валидация — через Form или DTO.
- Контроллер наследует `AbstractController`.
- Использовать `#[Route]` атрибуты (не YAML для роутов к контроллерам).

### 4.2. Конвенция маршрутов

```
/{module}/{resource}             — список
/{module}/{resource}/new         — создание (форма)
/{module}/{resource}/{id}        — просмотр
/{module}/{resource}/{id}/edit   — редактирование
```

Префикс API: `/api/public/` — публичный, `/api/` — авторизованный.

### 4.3. Безопасность в контроллерах

- **Всегда** проверять принадлежность сущности к `ActiveCompany` (через `ActiveCompanyService`).
- **Никогда** не доверять `id` из URL без проверки company scope.
- CSRF-токен обязателен для POST/PUT/DELETE в формах.

```php
// ✅ ПРАВИЛЬНО
$company = $this->activeCompanyService->getActiveCompany();
$product = $this->productRepository->findByIdAndCompany($id, $company->getId());

// ❌ НЕПРАВИЛЬНО — IDOR-уязвимость
$product = $this->productRepository->find($id);
```

---

## 5. Entity (Doctrine)

### 5.1. Общие правила

- **UUID v7** (`Ramsey\Uuid\Uuid::uuid7()`) в качестве первичного ключа. Тип колонки: `guid`.
- UUID генерируется в **конструкторе Entity**, не в контроллере.
- **Нет `public` свойств.** Только `private` + getters/setters.
- `#[ORM\Table(name: '...')]` — всегда явное имя таблицы.
- Индексы прописываются через `#[ORM\Index]`.
- Enum-поля: `#[ORM\Column(enumType: SomeEnum::class)]`.
- **Нет бизнес-логики** в Entity, кроме инвариантов (валидация в конструкторе, guard-условия в сеттерах).
- Использовать `Webmozart\Assert\Assert` для проверки инвариантов.

### 5.2. Связи

- `onDelete: 'CASCADE'` ставить на уровне ORM-аннотации.
- Eager loading — запрещён по умолчанию. Использовать `fetch: 'LAZY'` (дефолт).
- Для коллекций: `new ArrayCollection()` в конструкторе.

### 5.3. Multi-tenancy (Company scope)

**Каждая бизнес-сущность** ОБЯЗАНА быть привязана к компании. Способ привязки зависит от поколения модуля:

**Новые модули — `string $companyId` (ОБЯЗАТЕЛЬНО для нового кода):**

```php
// ✅ НОВЫЙ ПАТТЕРН: нет импорта Company Entity, нет ORM-связи
#[ORM\Entity]
#[ORM\Table(name: 'moysklad_connections')]
#[ORM\Index(columns: ['company_id', 'is_active'])]
class MoySkladConnection
{
    #[ORM\Column(type: 'guid')]
    private string $companyId;

    public function __construct(string $id, string $companyId, ...)
    {
        Assert::uuid($companyId);
        $this->companyId = $companyId;
        // ...
    }

    public function getCompanyId(): string { return $this->companyId; }
    // Нет setCompanyId() — companyId неизменяем после создания
}
```

**Старые модули — `Company $company` (допустимо, не переписывать без причины):**

```php
// Старый паттерн — работает, но создаёт жёсткую связь на Company Entity
#[ORM\ManyToOne(targetEntity: Company::class)]
#[ORM\JoinColumn(nullable: false)]
private Company $company;
```

**Правила для всех поколений:**
- Уникальные индексы — **всегда** с учётом `company_id`.
- Репозиторий: все методы выборки **обязаны** принимать `string $companyId`.
- В новых Entity: конструктор принимает `string $companyId`, валидирует через `Assert::uuid()`, не даёт менять после создания.

**Ссылки на другие модули — тот же принцип:**

```php
// ✅ НОВЫЙ: храним ID, не ORM-связь
#[ORM\Column(type: 'guid', nullable: true)]
private ?string $counterpartyId = null;

#[ORM\Column(type: 'guid', nullable: true)]
private ?string $projectDirectionId = null;

// ❌ СТАРЫЙ: ManyToOne на чужой Entity (не создавать в новых модулях)
#[ORM\ManyToOne(targetEntity: Counterparty::class)]
private ?Counterparty $counterparty = null;
```

**Таблица: что уже мигрировано на `companyId`:**

| Entity | Модуль | Паттерн |
|---|---|---|
| `MoySkladConnection` | MoySklad | `string $companyId` ✅ |
| `MarketplaceMonthClose` | Marketplace | `string $companyId` ✅ |
| `MarketplaceOzonRealization` | Marketplace | `string $companyId` ✅ |
| `MarketplaceJobLog` | Marketplace | `string $companyId` ✅ |
| `MarketplaceCostPLMapping` | Marketplace | `string $companyId` ✅ |
| `ProductImport` | Catalog | `string $companyId` ✅ |
| `ProductBarcode` | Catalog | `string $companyId` ✅ |
| `ProductPurchasePrice` | Catalog | `string $companyId` ✅ |
| `AuditLog` | Shared | `string $companyId` ✅ |
| `CashTransaction`, `MoneyAccount`, ... | Cash | `Company $company` (legacy) |
| `Deal`, `ChargeType` | Deals | `Company $company` (legacy) |
| `PLCategory`, `Document`, ... | legacy Entity | `Company $company` (legacy) |

---

## 6. Application Layer (Actions)

### 6.1. Action-классы

- Один Action = один use-case.
- `final class`, метод `__invoke(...)`.
- Принимает DTO или скалярные параметры. Не принимает `Request`.
- Возвращает результат (id, DTO, void). Не возвращает `Response`.
- Может бросать `\DomainException` при нарушении бизнес-правил.

```php
final class CreateProductAction
{
    public function __construct(
        private readonly ProductSkuPolicy $productSkuPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(string $companyId, CreateProductCommand $cmd): string
    {
        // бизнес-логика
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        return $product->getId();
    }
}
```

### 6.2. DTO

- `readonly class` или обычный класс с `public readonly` свойствами.
- Без логики — только данные.
- Статический фабричный метод `fromRequest(Request $request)` — допускается в DTO фильтров.
- **Не использовать** Entity как DTO. Всегда маппить.

---

## 7. Domain Layer

- `Domain/` содержит чистую бизнес-логику без зависимостей на инфраструктуру.
- **Policy** — проверка бизнес-правил (может бросить исключение).
- **ValueObject** — иммутабельные объекты-значения.
- **Интерфейсы** (контракты) для инфраструктурных реализаций объявляются в `Domain/` или в выделенной папке `Contract/`.
- Реализации — в `Infrastructure/`.

```php
// src/Catalog/Domain/ProductSkuUniquenessChecker.php — ИНТЕРФЕЙС
interface ProductSkuUniquenessChecker
{
    public function isUnique(string $sku, string $companyId): bool;
}

// src/Catalog/Infrastructure/ProductSkuUniquenessCheckerDoctrine.php — РЕАЛИЗАЦИЯ
final class ProductSkuUniquenessCheckerDoctrine implements ProductSkuUniquenessChecker
{
    // ...Doctrine-запрос
}
```

Привязка интерфейса к реализации — в `services.yaml`:

```yaml
App\Catalog\Domain\ProductSkuUniquenessChecker: '@App\Catalog\Infrastructure\ProductSkuUniquenessCheckerDoctrine'
```

---

## 8. Infrastructure Layer

### 8.1. Repository

- Наследует `ServiceEntityRepository<T>` или реализует кастомный интерфейс.
- **Только** запросы к БД. Без бизнес-логики.
- Сложные read-модели → `Infrastructure/Query/{Name}Query.php` (используют DBAL QueryBuilder, не ORM).

### 8.2. Внешние API

- Каждая интеграция — в `Infrastructure/Api/`.
- HTTP-клиент через Symfony HttpClient, инъекция через constructor.
- Контракт (interface) — в `Infrastructure/Api/Contract/` или `Domain/`.
- Реализации группируются по провайдеру: `Infrastructure/Api/Ozon/`, `Infrastructure/Api/Wildberries/`.

### 8.3. Normalizer

- Преобразование внешних данных в внутренние DTO.
- `Infrastructure/Normalizer/Contract/` — интерфейсы.
- `Infrastructure/Normalizer/{Provider}/` — реализации.

---

## 9. Messenger (Async)

### 9.1. Message

- Иммутабельный `readonly class` с минимумом данных (только ID сущностей, не сами сущности).
- Не содержит объектов Doctrine (Entity, Collection) — они не сериализуемы.
- Располагается в `{Module}/Message/`.

```php
final readonly class SyncWbReportMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
    ) {}
}
```

### 9.2. MessageHandler

- `#[AsMessageHandler]` (autoconfigure работает по конвенции).
- `__invoke(MessageClass $message)`.
- Располагается в `{Module}/MessageHandler/`.
- Разрешено в handler: загрузить Entity по ID, вызвать Action/Service, залогировать.
- **Запрещено**: обращаться к Request, Session, Security. Handler работает в CLI-контексте worker'а.

### 9.3. Routing

- Все async-сообщения маршрутизируются в `config/packages/messenger.yaml` → transport `async`.
- Новое сообщение = новая строка в `routing:`.

---

## 10. Services и DI

### 10.1. Autowiring

- Autowiring включён глобально (`_defaults: autowire: true, autoconfigure: true`).
- Явная конфигурация в `services.yaml` — только когда autowiring не справляется (tagged iterators, привязка интерфейсов, скалярные параметры).

### 10.2. Tagged Services

- Используются для Strategy/Chain паттернов: калькуляторы, адаптеры, провайдеры данных.
- Тег задаётся в `services.yaml`, собирается через `!tagged_iterator`.

```yaml
App\Marketplace\Service\CostCalculator\WbCommissionCalculator:
    tags:
        - { name: 'app.marketplace.cost_calculator', priority: 110 }

App\Marketplace\Application\ProcessWbCostsAction:
    arguments:
        $costCalculators: !tagged_iterator app.marketplace.cost_calculator
```

### 10.3. Правила классов

- `final class` — по умолчанию. Если класс не задуман для наследования — `final`.
- `readonly class` — для DTO, Value Objects и stateless сервисов.
- Все зависимости — через **constructor injection** (`private readonly`).
- **Запрещено**: `new Service()` внутри другого сервиса. Только DI.
- **Запрещено**: service locator / `ContainerInterface` в бизнес-коде.

---

## 11. База данных и миграции

### 11.1. Миграции

- Генерировать: `php bin/console doctrine:migrations:diff`.
- Всегда проверить сгенерированный SQL перед коммитом.
- Одна миграция = одна логическая операция.
- **Не редактировать** уже применённые миграции.
- Миграции хранятся в `migrations/`, namespace `DoctrineMigrations`.

### 11.2. Запросы

- **Простые выборки** → ORM Repository (`findBy`, `findOneBy`, custom DQL).
- **Сложные отчёты / агрегации** → DBAL QueryBuilder в `Infrastructure/Query/`.
- **Raw SQL** → только если QueryBuilder не справляется. Обязательно параметризация.
- **Запрещено**: конкатенация пользовательского ввода в SQL.

### 11.3. Транзакции

- Doctrine flush — вызывается в Application-слое (Action), не в Repository.
- Для длинных операций: явный `$em->wrapInTransaction(...)`.
- `use_savepoints: true` включён глобально.

---

## 12. Безопасность

### 12.1. Аутентификация

- Firewall `main` — form_login для пользователей.
- Firewall `admin` — отдельный firewall для админки.
- Firewall `public_api` — stateless, анонимный доступ.
- Роли: `ROLE_USER → ROLE_COMPANY_USER → ROLE_COMPANY_OWNER`, `ROLE_ADMIN → ROLE_SUPER_ADMIN`.

### 12.2. Multi-tenancy

- `ActiveCompanyService` — центральный сервис для получения текущей компании из сессии.
- **Каждый запрос к данным** ОБЯЗАН фильтроваться по `company_id`.
- Это **главное правило безопасности** проекта. Нарушение = IDOR.

### 12.3. Шифрование

- Sensitive-поля шифруются через `SodiumFieldEncryptionService`.
- Ключи хранятся в файле (`APP_ENCRYPTION_KEY_FILE`), не в коде.
- Ротация ключей — через `SecretRotationService`.

### 12.4. API-ключи

- Публичное API (`/api/public/`) использует `ReportApiKey` с передачей токена через query-параметр `token`.
- Rate limiting: `reports_api` (60/мин), `registration` (5/10 мин).

---

## 13. Логирование и мониторинг

- **Monolog** с каналами: `import.bank1c`, `recalc`, `deprecation`.
- **GlitchTip (Sentry)** — только `ERROR` уровень.
- В prod: JSON-формат в stderr, fingers_crossed handler.
- `AppLogger` (`Shared/Service/`) — обёртка для структурированного логирования.
- **Запрещено**: `dump()`, `dd()`, `var_dump()` в коммитах.
- **Запрещено**: логирование sensitive-данных (пароли, ключи API, токены).

---

## 14. Фронтенд

### 14.1. Стек

- **Twig** — основной шаблонизатор.
- **Vite** (через `pentatrion/vite-bundle`) — сборка ассетов.
- **React 18** — для интерактивных виджетов (embedded в Twig).
- **Stimulus + Turbo** — для лёгких интеракций.
- **Tabler** — UI-фреймворк (иконки, Bootstrap 5 layout).

### 14.2. Шаблоны Twig

- Twig namespace'ы: `@Balance`, `@Loan`, `@Telegram`, `@Marketplace`, `@MoySklad`, `@Finance`, `@Partials`.
- Base-layout: `base.html.twig`.
- Формы: `bootstrap_5_layout.html.twig` + кастомные theme'ы.

---

## 15. Тестирование

- Фреймворк: PHPUnit.
- Тестовая БД: настраивается через `DATABASE_URL`, суффикс отключён.
- Хэширование паролей: облегчённое (`cost: 4`) для скорости.
- Fixtures: через `DoctrineFixturesBundle`.
- **Entity Builders** — обязательный паттерн для создания Entity в тестах. Расположение: `tests/Builders/{Module}/`. Иммутабельные, с детерминированными UUID и семантическими хелперами (`asArchived()`, `asDisabled()`). Подробные правила → раздел 21.17.
- **Минимальные требования:** тесты на все Action-классы, критический бизнес-код, Builder для каждой Entity.

---

## 16. Feature Flags

- Реализованы через `FeatureFlagService`.
- Параметры: `%env(bool:FEATURE_FUNDS_AND_WIDGET)%` и т.д.
- Проверка в контроллерах и шаблонах.
- **Правило**: удалять флаг и мёртвый код после завершения rollout.

---

## 17. Чеклист для нового кода

Перед каждым PR проверить:

1. **Модуль:** код размещён в правильном модуле, а не в legacy-зоне.
2. **Зависимости:** нет прямых импортов из `Service/`, `Repository/`, `Infrastructure/` чужого модуля. Только через Facade.
3. **Company scope:** все запросы к данным фильтруются по `company_id`.
4. **Controller:** тонкий, бизнес-логика в Action.
5. **Entity:** UUID v7, явное имя таблицы, связь с Company.
6. **DTO:** не передаём Entity как DTO наружу контроллера/API.
7. **Messenger:** Message содержит только ID, не Entity.
8. **Тесты:** покрыт новый Action или критическая логика.
9. **Builder:** новая Entity → создан Builder в `tests/Builders/{Module}/`.
10. **Миграция:** проверен SQL, нет destructive-изменений без плана.
11. **Нет:** `dump()`, `dd()`, хардкод-секретов, raw SQL без параметров.

---

## 18. Антипаттерны (ЗАПРЕЩЕНО)

| # | Антипаттерн | Почему плохо | Что делать |
|---|---|---|---|
| 1 | Бизнес-логика в контроллере | Не тестируемо, дублирование | Вынести в Action |
| 2 | `new SomeService()` вручную | Обход DI, не mockable | Constructor injection |
| 3 | Entity без `company` scope | IDOR-уязвимость | Добавить ManyToOne на Company |
| 4 | Import из `{Module}/Service/` другого модуля | Tight coupling | Создать Facade |
| 5 | Entity как Message payload | Не сериализуется, stale данные | Передавать только ID |
| 6 | `flush()` в Repository | Неожиданный side-effect | flush() в Action |
| 7 | Код в `src/Entity/` (плоский) | Legacy-зона | В модуль `{Module}/Entity/` |
| 8 | God-сервис (>300 строк) | Нечитаемо, не testable | Разбить на Actions |
| 9 | Wildcard `SELECT *` в raw SQL | Хрупкость, лишние данные | Явное перечисление колонок |
| 10 | Без rate-limit на публичных API | DDoS, abuse | `RateLimiter` framework |

---

## 19. Рекомендации по рефакторингу legacy

### Приоритет 1 (высокий)
- Перенести `src/Entity/{PLCategory, ProjectDirection, Counterparty, Document, DocumentOperation}` в модули `Finance/`, `Company/`, `Cash/`.
- Перенести `src/Repository/` → в соответствующие `{Module}/Repository/`.
- Перенести `src/Service/` → в `{Module}/Application/` или `{Module}/Domain/Service/`.

### Приоритет 2 (средний)
- Создать Facade для `Finance` (уже частично есть `PLCategoryFacade`).
- Устранить прямые импорты `App\Entity\PLCategory` и `App\Repository\PLCategoryRepository` из `Marketplace/Controller/` — заменить на Facade.
- Устранить прямые `App\Repository\ProjectDirectionRepository` из `Marketplace/` — заменить на Facade.

### Приоритет 3 (низкий)
- Унифицировать размещение Repository: выбрать **одну** конвенцию (`{Module}/Repository/` или `{Module}/Infrastructure/Repository/`).
- Унифицировать Application-слой: `{Module}/Service/` → `{Module}/Application/`.

---

## 20. Конвенции для ИИ-ассистентов

При генерации кода для этого проекта, ИИ **ОБЯЗАН**:

1. Размещать код в правильном модуле. Спрашивать, если неясно.
2. Не создавать файлы в `src/Entity/`, `src/Service/`, `src/Repository/` (legacy-зона).
3. Использовать `final class` по умолчанию.
4. Использовать `declare(strict_types=1);` в каждом файле.
5. Использовать UUID v7 для новых Entity.
6. **В новых Entity:** использовать `string $companyId` (НЕ `Company $company`). То же для ссылок на сущности чужих модулей: `string $counterpartyId`, а не `#[ManyToOne] Counterparty`.
7. Создавать тонкие контроллеры (один action = `__invoke`).
8. Бизнес-логику выносить в Action-классы.
9. Для межмодульного взаимодействия использовать Facade.
10. **В формах новых модулей:** `ChoiceType` с данными из Facade, НЕ `EntityType` с Entity чужого модуля.
11. Для async-задач: Message с ID, Handler с `__invoke`.
12. Добавлять `#[ORM\Table(name: '...')]` к каждой Entity.
13. Следовать конвенциям именования из раздела 2.1.
14. При создании нового Message — добавлять routing в `messenger.yaml`.
15. Не использовать `dump()`, `dd()`, `var_dump()`.
16. Комментировать код и PR-описания на **русском языке**.
17. При создании новой Entity — создавать Builder в `tests/Builders/{Module}/`.

---

## 21. Best Practices: Symfony-архитектура

Данный раздел описывает лучшие практики, применимые к нашему проекту, с конкретными примерами из кодовой базы.

---

### 21.1. Принцип единственной ответственности в слоях

Каждый слой отвечает за одну задачу. Нарушение — главная причина «спагетти» при росте проекта.

| Слой | Отвечает за | НЕ отвечает за |
|---|---|---|
| **Controller** | HTTP: десериализация Request → вызов Action → сериализация Response | Бизнес-логика, SQL, валидация правил |
| **Application (Action)** | Оркестрация: загрузка Entity, вызов Domain-политик, persist/flush | HTTP, Session, шаблоны |
| **Domain** | Бизнес-правила, инварианты, Value Objects | Doctrine, HTTP, внешние API |
| **Infrastructure** | БД-запросы, HTTP-клиенты, файловые системы | Бизнес-решения |
| **Facade** | Публичный API модуля для других модулей | Собственная бизнес-логика |

**Эталон из проекта — `Catalog`:**

```
Controller (ProductEditController)
    → вызывает Action (UpdateProductAction)
        → вызывает Domain (ProductSkuPolicy.assertSkuIsUnique)
            → использует Infrastructure (ProductSkuUniquenessCheckerDoctrine)
```

Контроллер не знает о проверке SKU. Action не знает о SQL. Domain не знает о Doctrine.

---

### 21.2. Invokable Controllers (один action = один класс)

**Правило:** каждый контроллер содержит ровно один public-метод `__invoke`.

**Почему:**
- Класс маленький, легко читать и тестировать.
- Нет конфликтов зависимостей — контроллер инжектирует только то, что нужно одному action.
- Имя файла = имя use-case: `ProductEditController`, `ProductIndexController`.

```php
// ✅ Эталон: src/Catalog/Controller/ProductIndexController.php
final class ProductIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ListProductsAction $listProductsAction,
    ) {}

    #[Route('/catalog/products', name: 'catalog_products_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $filter = ProductListFilter::fromRequest($request)->withCompanyId($company->getId());
        $pager = ($this->listProductsAction)($filter, $page, $perPage);

        return $this->render('catalog/product/index.html.twig', [...]);
    }
}
```

```php
// ❌ God-контроллер с 10 action-методами
final class ProductController extends AbstractController
{
    // 15 зависимостей в конструкторе, половина не нужна для каждого конкретного action
    public function index() { ... }
    public function show() { ... }
    public function edit() { ... }
    public function delete() { ... }
    // ...
}
```

**Исключение:** контроллер с `#[Route]` на уровне класса и 2-3 тесно связанными actions допустим, если все действия работают с одним ресурсом и используют одни зависимости (пример: `MonthCloseController` — index + preflight + closeStage + reopenStage). Но при росте до 5+ actions — разбивать.

---

### 21.3. Action-классы: оркестрация, не God-объекты

Action = один use-case. Принимает DTO/скаляры, возвращает результат, бросает `DomainException`.

**Правила:**
- `final class`, метод `__invoke`.
- Не принимает `Request`, не возвращает `Response`.
- Координирует: загрузить Entity → проверить правила → изменить → flush.
- Максимальный размер: **~100 строк**. Если больше — выделять Domain-сервис или Policy.

```php
// ✅ Эталон: src/Catalog/Application/CreateProductAction.php
final class CreateProductAction
{
    public function __construct(
        private readonly ProductSkuPolicy $productSkuPolicy,
        private readonly InternalArticleGenerator $articleGenerator,
        private readonly CompanyFacade $companyFacade,      // межмодульное — через Facade
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(string $companyId, CreateProductCommand $cmd): string
    {
        $company = $this->companyFacade->findById($companyId)
            ?? throw new \DomainException("Компания не найдена.");

        $this->productSkuPolicy->assertSkuIsUnique($cmd->sku, $companyId);

        $product = new Product(Uuid::uuid7()->toString(), $company);
        $product->setName($cmd->name)->setSku($cmd->sku);
        $product->assignInternalArticle($this->articleGenerator->generate($companyId));

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product->getId();
    }
}
```

**Антипаттерн — «жирный» Action (>200 строк):**
- Если Action загружает данные, фильтрует, пересчитывает, создаёт несколько Entity, шлёт уведомления — пора разбивать.
- Вычислительную логику → в `Domain/Service/` или `Domain/Policy`.
- Побочные эффекты (email, async) → через Messenger dispatch.

---

### 21.4. Facade — единственный мост между модулями

**Зачем:** модули должны общаться через стабильный контракт, а не через внутреннюю реализацию. Если модуль B рефакторит свои репозитории — модуль A не ломается.

**Эталон: `FinanceFacade`** — один из лучших примеров в проекте:

```php
// src/Finance/Facade/FinanceFacade.php
final class FinanceFacade
{
    public function __construct(
        private readonly CreatePLDocumentAction $createAction,
        private readonly DeletePLDocumentAction $deleteAction,
    ) {}

    // Принимает скалярные данные — не Entity из другого модуля
    public function createPLDocument(
        string $companyId,
        PLDocumentSource $source,
        PLDocumentStream $stream,
        string $periodFrom,
        string $periodTo,
        array $entries,
    ): string { ... }

    public function deletePLDocument(string $companyId, string $documentId): void { ... }
}
```

**Принципы хорошего Facade:**
- `final class` (или `final readonly class`).
- Принимает **скалярные типы и DTO**, не Entity чужого модуля.
- Возвращает скаляры, DTO или Entity **своего** модуля.
- Не содержит бизнес-логики — делегирует в Actions.
- Минимальный интерфейс: выставлять только то, что реально нужно другим модулям.

**Когда создавать новый Facade:**
- Модуль A хочет вызвать Action/Repository из модуля B → создать Facade в B.
- Уже существует прямой `use App\SomeModule\Repository\...` из другого модуля → заменить на Facade.

---

### 21.5. Domain-слой: Policy и Value Objects

Domain-слой — это код без зависимостей на инфраструктуру. Он содержит правила бизнеса, которые не зависят от БД, HTTP или внешних API.

**Policy — проверка бизнес-правил:**

```php
// src/Catalog/Domain/ProductSkuPolicy.php — использует интерфейс, не Doctrine
final class ProductSkuPolicy
{
    public function __construct(
        private readonly ProductSkuUniquenessChecker $checker, // интерфейс
    ) {}

    public function assertSkuIsUnique(string $sku, string $companyId): void
    {
        if (!$this->checker->isUnique($sku, $companyId)) {
            throw new \DomainException('SKU уже занят.');
        }
    }
}
```

**Value Object — иммутабельные доменные значения:**

```php
// src/Marketplace/Domain/ValueObject/ListingKey.php
final readonly class ListingKey
{
    public function __construct(
        private string $marketplaceSku,
        private string $size,
    ) {}

    public function toString(): string {
        return sprintf('%s:%s', $this->marketplaceSku, $this->size);
    }

    public static function fromString(string $key): self { ... }
}
```

**Когда выделять в Domain:**
- Логика используется в нескольких Actions.
- Правило сложнее одной проверки `if`.
- Есть понятие из предметной области (ListingKey, Period, Money).

---

### 21.6. Infrastructure: Contracts и реализации

**Принцип:** Domain/Application объявляет **что** нужно (интерфейс). Infrastructure реализует **как**.

```
Domain/ProductSkuUniquenessChecker.php         — interface
Infrastructure/ProductSkuUniquenessCheckerDoctrine.php — implements (Doctrine-запрос)
```

Привязка в `services.yaml`:

```yaml
App\Catalog\Domain\ProductSkuUniquenessChecker:
    '@App\Catalog\Infrastructure\ProductSkuUniquenessCheckerDoctrine'
```

**Для внешних API — тот же принцип:**

```
Infrastructure/Api/Contract/MarketplaceFetcherInterface.php   — интерфейс
Infrastructure/Api/Wildberries/WildberriesFetcher.php         — реализация WB
Infrastructure/Api/Ozon/OzonFetcher.php                       — реализация Ozon
```

**Query-объекты для сложных read-моделей:**

Когда запрос слишком сложен для ORM Repository (отчёты, агрегации, multi-join) — выносить в `Infrastructure/Query/`:

```php
// src/Catalog/Infrastructure/Query/ProductQuery.php
// Использует DBAL QueryBuilder напрямую, минуя ORM hydration
final class ProductQuery
{
    public function __construct(private readonly Connection $connection) {}

    public function findForListing(string $companyId, ProductListFilter $filter): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.name', 'p.sku')
            ->from('products', 'p')
            ->where('p.company_id = :companyId')
            ->setParameter('companyId', $companyId);
        // ...
    }
}
```

---

### 21.7. Tagged Services: Strategy/Chain/Registry

Для паттернов, где нужно динамически собрать набор реализаций — использовать tagged services с `!tagged_iterator`.

**Эталон из проекта — калькуляторы затрат WB:**

```yaml
# services.yaml
App\Marketplace\Service\CostCalculator\WbCommissionCalculator:
    tags: [{ name: 'app.marketplace.cost_calculator', priority: 110 }]

App\Marketplace\Service\CostCalculator\WbLogisticsDeliveryCalculator:
    tags: [{ name: 'app.marketplace.cost_calculator', priority: 108 }]

App\Marketplace\Application\ProcessWbCostsAction:
    arguments:
        $costCalculators: !tagged_iterator app.marketplace.cost_calculator
```

```php
final class ProcessWbCostsAction
{
    /** @param iterable<CostCalculatorInterface> $costCalculators */
    public function __construct(
        private readonly iterable $costCalculators,
    ) {}

    public function __invoke(...): void
    {
        foreach ($this->costCalculators as $calculator) {
            $calculator->calculate($sale, $costs);
        }
    }
}
```

**Когда использовать:**
- Набор однотипных обработчиков (калькуляторы, адаптеры, провайдеры).
- Новый обработчик добавляется без изменения ядра — достаточно класса + тега.
- Open/Closed Principle в действии.

**Текущие tag-группы в проекте:**
- `app.marketplace.cost_calculator` — калькуляторы WB-затрат
- `app.marketplace.adapter` — адаптеры маркетплейсов (WB, Ozon)
- `app.balance.value_provider` — провайдеры значений баланса
- `marketplace.data_source` — источники данных для закрытия месяца
- `app.notification.sender` — каналы отправки уведомлений

---

### 21.8. Messenger: правильная границы sync/async

**Правило выбора sync vs async:**

| Ситуация | Подход |
|---|---|
| Действие < 1 сек, пользователь ждёт результат | Sync: Action напрямую |
| Действие > 3 сек или может упасть (внешний API, импорт) | Async: Message → Handler |
| Побочный эффект (email, уведомление) | Async: Message → Handler |
| Цепочка тяжёлых шагов | Async: каждый шаг = отдельное Message |

**Правила Message:**

```php
// ✅ ПРАВИЛЬНО: только скалярные ID
final readonly class CloseMonthStageMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplace,
        public int $year,
        public int $month,
        public string $stage,
        public string $actorUserId,
    ) {}
}

// ❌ НЕПРАВИЛЬНО: Entity в Message
final readonly class CloseMonthStageMessage
{
    public function __construct(
        public Company $company,           // НЕ сериализуется
        public MarketplaceMonthClose $mc,  // stale данные при десериализации
    ) {}
}
```

**Правила Handler:**
- Загружать Entity заново по ID (данные могли измениться между dispatch и handle).
- Оборачивать в try/catch → логировать ошибки.
- Handler работает в CLI-контексте (worker): нет `Request`, `Session`, `Security->getUser()`.
- Для получения «кто инициировал» — передавать `actorUserId` в Message.

---

### 21.9. Entity: rich constructor, guard-методы

**Конструктор Entity — единственное место создания:**

```php
// ✅ Entity управляет своей инициализацией
public function __construct(string $id, Company $company)
{
    Assert::uuid($id);
    $this->id = $id;
    $this->company = $company;
    $this->createdAt = new \DateTimeImmutable();
    $this->updatedAt = new \DateTimeImmutable();
}
```

**Guard-методы для бизнес-инвариантов:**

```php
public function closeStage(CloseStage $stage, string $userId, ...): void
{
    // Инвариант: нельзя закрыть уже закрытый этап
    if ($this->getStageStatus($stage)->isClosed()) {
        throw new \DomainException('Этап уже закрыт.');
    }
    $this->updatedAt = new \DateTimeImmutable();
    // ...
}
```

**Правила Entity:**
- `new Entity(...)` — только в Application-слое (Action).
- Setters: если есть бизнес-правило — реализовать guard. Если нет — простой setter допустим.
- Не помещать в Entity: работу с Repository, Messenger dispatch, обращение к другим сервисам.
- `DateTimeImmutable` вместо `DateTime` — всегда.

---

### 21.10. Обработка ошибок

**Стратегия по слоям:**

| Слой | Как сигнализирует об ошибке | Пример |
|---|---|---|
| Domain / Entity | `throw new \DomainException(...)` | «SKU уже занят» |
| Application (Action) | `throw new \DomainException(...)` | «Компания не найдена» |
| Infrastructure | Прокидывает или оборачивает инфраструктурную ошибку | `UniqueConstraintViolationException → DomainException` |
| Controller | Ловит `\DomainException` → `addFlash('error', ...)` или JSON error | — |

**Эталон из проекта:**

```php
// Action оборачивает Doctrine-ошибку в доменную
try {
    $this->entityManager->flush();
} catch (UniqueConstraintViolationException) {
    throw new \DomainException('Товар с таким SKU уже существует.');
}

// Controller ловит доменную ошибку
try {
    ($this->createAction)($companyId, $cmd);
} catch (\DomainException $e) {
    $this->addFlash('error', $e->getMessage());
    return $this->redirectToRoute('...');
}
```

**Кастомные исключения — для специфичных случаев:**

```
src/Exception/ForbiddenCompanyAccessException.php   — доступ к чужой компании
src/Deals/Exception/DealNotFound.php                — сделка не найдена
src/Deals/Exception/InvalidDealState.php            — невалидный переход состояния
```

Кастомные Exception размещать в `{Module}/Exception/`. Создавать когда:
- Нужно ловить конкретный тип ошибки (а не общий `\DomainException`).
- Ошибка несёт дополнительный контекст (id сущности, текущее состояние).

---

### 21.11. Скаффолдинг нового модуля

В проекте есть команда `app:make:module` для генерации эталонной структуры:

```bash
php bin/console app:make:module Sales
```

Создаёт:
- `src/Sales/` с полной структурой папок (Application, Controller, Domain, Infrastructure, Entity, DTO, Enum, Facade, Form).
- Заготовки: `FacadeInterface`, `StatusEnum`, `CreateAction`, `Controller`, `Request DTO`.
- `templates/sales/` — папка для Twig-шаблонов.

**После генерации — обязательно:**

1. Добавить маппинг Doctrine в `config/packages/doctrine.yaml`:
    ```yaml
    Sales:
        type: attribute
        is_bundle: false
        dir: '%kernel.project_dir%/src/Sales/Entity'
        prefix: 'App\Sales\Entity'
        alias: Sales
    ```

2. Добавить routes в `config/routes.yaml`:
    ```yaml
    sales_controllers:
        resource:
            path: ../src/Sales/Controller/
            namespace: App\Sales\Controller
        type: attribute
    ```

3. (Опционально) Twig namespace в `config/packages/twig.yaml`:
    ```yaml
    paths:
        '%kernel.project_dir%/templates/sales': Sales
    ```

---

### 21.12. Multi-tenancy: полная изоляция данных

**Это самое критичное архитектурное правило.** Каждая бизнес-сущность привязана к Company. Утечка данных между компаниями — это инцидент безопасности.

**Три уровня защиты:**

**1. Entity-уровень:** каждая сущность имеет связь с Company.

```php
#[ORM\ManyToOne(targetEntity: Company::class)]
#[ORM\JoinColumn(nullable: false)]
private Company $company;
```

**2. Repository-уровень:** все методы фильтруют по companyId.

```php
// ✅ Каждый метод — с companyId
public function findByIdAndCompany(string $id, string $companyId): ?Product
{
    return $this->findOneBy(['id' => $id, 'company' => $companyId]);
}

// ❌ ЗАПРЕЩЕНО — нет фильтра по company
public function findById(string $id): ?Product
{
    return $this->find($id);
}
```

**3. Controller-уровень:** всегда через `ActiveCompanyService`.

```php
$company = $this->activeCompanyService->getActiveCompany();
$entity = $this->repository->findByIdAndCompany($id, $company->getId());
if (!$entity) {
    throw $this->createNotFoundException();
}
```

**В Messenger Handlers** (нет сессии): `companyId` передаётся через Message.

---

### 21.13. Конфигурация и параметры

**Правила:**
- Инфраструктурные настройки → `.env` файлы (DATABASE_URL, REDIS_DSN, MAILER_DSN).
- Бизнес-параметры → `config/services.yaml` → `parameters:`.
- Секреты → `Symfony Secrets` или файл ключей (APP_ENCRYPTION_KEY_FILE), **никогда** в `.env` продакшена в открытом виде.
- Feature flags → `%env(bool:FEATURE_...)%` → `FeatureFlagService`.

```yaml
# services.yaml
parameters:
    feature_funds_and_widget: '%env(bool:FEATURE_FUNDS_AND_WIDGET)%'
    app.storage_root: '%kernel.project_dir%/var/storage'
```

**Никогда:**
- Хардкод URL / ключей API в PHP-коде.
- `$_ENV` / `getenv()` напрямую — только через Symfony parameter binding.
- Секреты в git (`.env.local` в `.gitignore`).

---

### 21.14. Производительность Doctrine

**Правила для продакшена (уже настроены):**

```yaml
# config/packages/doctrine.yaml (when@prod)
doctrine:
    dbal:
        logging: false
        profiling: false
    orm:
        auto_generate_proxy_classes: false
        query_cache_driver: { type: pool, pool: doctrine.system_cache_pool }
        result_cache_driver: { type: pool, pool: doctrine.result_cache_pool }
```

**Правила при написании кода:**
- Не делать `findAll()` на больших таблицах. Всегда пагинация.
- Для отчётов и массовых выборок — DBAL QueryBuilder (`Infrastructure/Query/`), не ORM hydration.
- Для bulk-операций: `$em->clear()` периодически, чтобы не раздувать UnitOfWork.
- Eager loading (`fetch: EAGER`) — запрещён по умолчанию. Если нужен JOIN — явно в QueryBuilder.
- N+1 проблема: при итерации по коллекции связей — использовать `JOIN FETCH` в DQL или `addSelect` в QueryBuilder.

---

### 21.15. Redis: сессии, очереди, блокировки

Проект использует Redis для трёх целей:

| Назначение | DSN | Конфигурация |
|---|---|---|
| Сессии | `redis://site-redis:6379` | `sess_` prefix, TTL 14 дней |
| Messenger (очереди) | `redis://site-redis:6379/messages` | transport `async` |
| Lock (блокировки) | `redis://site-redis:6379?prefix=symfony-locks` | `framework.lock` |

**Правила:**
- Для кеширования бизнес-данных — использовать `framework.cache` с Redis-адаптером (пока не настроен, можно добавить).
- Для distributed lock в тяжёлых операциях (импорт, синхронизация) — использовать `Symfony\Component\Lock\LockFactory`.
- **Не** использовать Redis напрямую (`Predis\Client`) в бизнес-коде. Только через Symfony-абстракции (Cache, Lock, Messenger).

---

### 21.16. Event Subscribers и Doctrine Listeners

**Текущие примеры в проекте:**
- `AuditLogSubscriber` — пишет audit log при persist/update `CashTransaction`.
- `CashTransactionAutoRulesSubscriber` — автоправила после изменения транзакции.

**Когда использовать Doctrine Listeners:**
- Аудит и логирование (side-effect, не влияет на бизнес-поток).
- Автоматическое обновление `updatedAt` (если не делается в Entity).
- Денормализация данных в read-модели.

**Когда НЕ использовать:**
- Бизнес-логика, которая должна быть явной (валидация, пересчёт баланса) — такое в Action.
- Отправка email/уведомлений — через Messenger.
- Всё, что зависит от контекста запроса (текущий пользователь, параметры URL).

**Правила:**
- Использовать `#[AsDoctrineListener(event: Events::postPersist)]` (атрибут), не `EventSubscriber` интерфейс (deprecated в будущих версиях Doctrine).
- Listener не должен вызывать `flush()` — это вызовет бесконечную рекурсию. Если нужно сохранить AuditLog — использовать отдельный `EntityManager` или отложенную запись.
- Listener не должен бросать исключения, прерывающие основную транзакцию (если это побочный эффект).

```php
// ✅ Правильно: атрибут, чёткий scope, без flush
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AuditLogSubscriber
{
    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CashTransaction) {
            return; // фильтруем — реагируем только на нужные Entity
        }
        // ... создать AuditLog
    }
}
```

**Symfony Event Subscribers** (для HTTP-событий: kernel.request, kernel.exception и т.д.) — допустимы для:
- Глобальной обработки ошибок.
- Inject company context в каждый запрос.
- Rate limiting, CORS, security headers.

---

### 21.17. Тестирование: стратегия, Builders, правила

**Пирамида тестов для проекта:**

```
          ╱╲
         ╱  ╲        E2E (Panther/Browser) — минимум, только критические пути
        ╱────╲
       ╱      ╲      Functional (WebTestCase) — контроллеры, API endpoints
      ╱────────╲
     ╱          ╲    Integration — Action + реальная БД (KernelTestCase)
    ╱────────────╲
   ╱              ╲  Unit — Domain, Policy, ValueObject, DTO (PHPUnit)
  ╱────────────────╲
```

**Что обязательно покрывать тестами:**

| Слой | Тип теста | Приоритет |
|---|---|---|
| Domain (Policy, ValueObject) | Unit | Высокий — чистая логика, быстрые тесты |
| Application (Action) | Integration (KernelTestCase) | Высокий — ядро бизнеса |
| Facade | Integration | Средний — контракт между модулями |
| Controller | Functional (WebTestCase) | Средний — smoke-тест HTTP |
| Infrastructure (Query) | Integration | Низкий — зависит от схемы |
| Twig-шаблоны | Functional (проверка 200) | Низкий |

#### 21.17.1. Test Entity Builders (обязательный паттерн)

Для создания Entity в тестах используется паттерн **Immutable Builder**. Каждая бизнес-сущность имеет свой Builder в `tests/Builders/{Module}/`.

**Зачем Builders вместо фикстур или `new Entity(...)` напрямую:**
- Тест читается как бизнес-сценарий: `UserBuilder::aUser()->withRoles(['ROLE_ADMIN'])->build()`.
- Дефолтные значения — не нужно заполнять 10 полей ради одного, который важен для теста.
- Иммутабельность (`clone`) — один билдер можно переиспользовать без побочных эффектов.
- При изменении конструктора Entity ломается **один** Builder, а не 200 тестов.
- Детерминированные UUID — легко отлаживать: видишь `22222222-2222-2222-2222-000000000003` → это третий пользователь.

**Структура:**

```
tests/
└── Builders/
    └── Company/
        ├── UserBuilder.php
        ├── CompanyBuilder.php
        ├── CompanyMemberBuilder.php
        ├── CompanyInviteBuilder.php
        └── CounterpartyBuilder.php
    └── Catalog/
        ├── ProductBuilder.php
        └── ...
    └── Cash/
        ├── CashTransactionBuilder.php
        └── ...
```

**Эталонная структура Builder:**

```php
final class UserBuilder
{
    // 1. Детерминированные дефолты — всегда одинаковый результат
    public const DEFAULT_USER_ID = '22222222-2222-2222-2222-222222222222';
    public const DEFAULT_EMAIL = 'user+1@example.test';

    private string $id;
    private string $email;
    private array $roles;

    // 2. Private constructor — создание только через статический метод
    private function __construct()
    {
        $this->id = self::DEFAULT_USER_ID;
        $this->email = self::DEFAULT_EMAIL;
        $this->roles = ['ROLE_COMPANY_OWNER'];
    }

    // 3. Фабричный метод с говорящим именем
    public static function aUser(): self
    {
        return new self();
    }

    // 4. With-методы ИММУТАБЕЛЬНЫ (clone) — никогда не мутировать $this
    public function withEmail(string $email): self
    {
        $clone = clone $this;
        $clone->email = $email;
        return $clone;
    }

    // 5. withIndex() для серийного создания — детерминированные UUID
    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->email = sprintf('user+%d@example.test', $index);
        $clone->id = sprintf('22222222-2222-2222-2222-%012d', $index);
        return $clone;
    }

    // 6. Семантические хелперы — читаются как бизнес-фразы
    public function asCompanyOwner(): self
    {
        $clone = clone $this;
        $clone->roles = ['ROLE_COMPANY_OWNER'];
        return $clone;
    }

    // 7. build() — единственное место сборки Entity
    public function build(): User
    {
        $user = new User($this->id, $this->createdAt);
        $user->setEmail($this->email);
        $user->setRoles($this->roles);
        return $user;
    }
}
```

**Правила Builder:**

| Правило | Обоснование |
|---|---|
| `final class` | Builder не наследуется |
| `private __construct()` | Создание только через `aUser()`, `aCompany()` и т.д. |
| Все `with*()` возвращают `clone` | Иммутабельность — повторное использование без побочных эффектов |
| `DEFAULT_*` константы для всех дефолтов | Тесты могут ссылаться на `UserBuilder::DEFAULT_USER_ID` |
| Валидация в `with*()` где нужна | Builder ловит ошибку до `build()` — проще отлаживать |
| `withIndex(int)` для серий | Детерминированные UUID: `...-000000000001`, `...-000000000002` |
| Семантические методы: `asArchived()`, `asDisabled()`, `asCompanyOwner()` | Тест читается как бизнес-сценарий |
| Связанные Entity через другие Builders | `CompanyBuilder` использует `UserBuilder::aUser()->build()` для owner |

**Примеры использования в тестах:**

```php
// Минимальный тест — дефолты покрывают все обязательные поля
$user = UserBuilder::aUser()->build();

// Тест с конкретной ролью — видно ЧТО важно для теста
$admin = UserBuilder::aUser()
    ->withRoles(['ROLE_ADMIN'])
    ->build();

// Серия объектов с детерминированными ID
$user1 = UserBuilder::aUser()->withIndex(1)->build();
$user2 = UserBuilder::aUser()->withIndex(2)->build();

// Композиция — Company с конкретным owner
$owner = UserBuilder::aUser()->withIndex(1)->build();
$company = CompanyBuilder::aCompany()
    ->withOwner($owner)
    ->withName('Тест ООО')
    ->build();

// Бизнес-состояния читаются как фраза
$invite = CompanyInviteBuilder::anInvite()
    ->withCompany($company)
    ->withEmail('new@example.test')
    ->withAcceptedAt()
    ->build();

$disabledMember = CompanyMemberBuilder::aMember()
    ->withCompany($company)
    ->asDisabled()
    ->build();

$archivedCounterparty = CounterpartyBuilder::aCounterparty()
    ->withCompany($company)
    ->asArchived()
    ->build();
```

**Когда создавать новый Builder:**
- Появилась новая Entity в модуле → создать Builder в `tests/Builders/{Module}/`.
- Entity используется в 3+ тестах → Builder обязателен.
- Entity имеет сложный конструктор (>3 параметров) → Builder обязателен.

**Когда НЕ нужен Builder:**
- DTO/ValueObject с 1-2 полями — создавать через `new` напрямую.
- Entity используется в одном тесте — допустимо `new`, но при росте → вынести в Builder.

**Правила для ИИ при создании Builder:**

1. Namespace: `App\Tests\Builders\{Module}\{Entity}Builder`.
2. Файл: `tests/Builders/{Module}/{Entity}Builder.php`.
3. Фабричный метод: `a{Entity}()` (aUser, aCompany) или `an{Entity}()` (anInvite).
4. `DEFAULT_*` константы для каждого поля с дефолтом.
5. UUID-шаблон дефолта: `XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX` с повторяющейся цифрой (1 для Company, 2 для User, 3 для Counterparty и т.д.).
6. `withIndex(int)` — если сущность будет создаваться сериями.
7. Семантические методы (`asArchived`, `asDisabled`) — для частых бизнес-состояний.
8. Связанные Entity — создавать через другие Builders (`UserBuilder::aUser()->build()`), не через `new User(...)`.

**Правила написания тестов (общие):**

```php
// Unit-тест Domain Policy — быстрый, без БД
final class ProductSkuPolicyTest extends TestCase
{
    public function testThrowsWhenSkuNotUnique(): void
    {
        $checker = $this->createMock(ProductSkuUniquenessChecker::class);
        $checker->method('isUnique')->willReturn(false);

        $policy = new ProductSkuPolicy($checker);

        $this->expectException(\DomainException::class);
        $policy->assertSkuIsUnique('SKU-001', 'company-uuid');
    }
}

// Integration-тест Action — с реальной БД и Builders
final class CreateProductActionTest extends KernelTestCase
{
    public function testCreatesProduct(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $action = self::getContainer()->get(CreateProductAction::class);

        $owner = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $productId = ($action)($company->getId(), new CreateProductCommand(
            name: 'Test Product',
            sku: 'TST-001',
        ));

        $this->assertNotEmpty($productId);
    }
}
```

**Конфигурация тестов (уже настроена):**
- Отдельная БД: `DATABASE_URL` с `_test` суффиксом.
- Облегчённый хэшер паролей: `cost: 4` (быстро, но достаточно для тестов).
- Fixtures через `DoctrineFixturesBundle`.
- `session.storage.factory.mock_file` — без Redis в тестах.

**Минимальные требования перед merge:**
- Новый Action → как минимум один happy-path тест.
- Новый Domain Policy → unit-тесты на все ветки.
- Новая Entity → Builder в `tests/Builders/{Module}/`.
- Исправление бага → регрессионный тест, воспроизводящий баг.

---

### 21.18. Миграции: безопасность и discipline

**Жизненный цикл миграции:**

```bash
# 1. Сгенерировать diff
php bin/console doctrine:migrations:diff

# 2. ПРОВЕРИТЬ SQL вручную — всегда!
#    Открыть файл и убедиться, что нет DROP TABLE, DROP COLUMN без плана.

# 3. Применить на dev
php bin/console doctrine:migrations:migrate

# 4. Проверить целостность
php bin/console doctrine:schema:validate
```

**Правила:**
- Одна миграция = одна логическая операция. Не объединять несвязанные изменения.
- Уже применённые миграции **не редактировать** (они в `doctrine_migration_versions`).
- Destructive-операции (DROP COLUMN, DROP TABLE) — только через двухшаговый процесс:
    1. Первый PR: убрать использование колонки из кода (Entity, Query).
    2. Второй PR (через неделю): миграция с DROP.
- `NOT NULL` колонка в существующей таблице — добавлять с DEFAULT, потом заполнить, потом убрать DEFAULT.
- Длительные миграции (ALTER TABLE на больших таблицах) — помечать в PR, обсуждать с командой.

**Типичная ошибка (как с `settings`):**
```
Entity добавил колонку → миграцию забыли / не выполнили → Doctrine SELECT включает колонку → PostgreSQL 42703
```
Защита: всегда выполнять `doctrine:schema:validate` перед deploy.

---

### 21.19. Структура конфигурации Symfony

**Текущая организация config/ (уже хорошо настроена):**

```
config/
├── packages/           # Конфигурация бандлов (один файл = один бандл)
│   ├── doctrine.yaml
│   ├── messenger.yaml
│   ├── security.yaml
│   ├── monolog.yaml
│   └── test/           # Переопределения для test-окружения
├── routes/             # Маршруты
│   └── admin.yaml
├── routes.yaml         # Главный файл маршрутов (по модулям)
├── services.yaml       # DI-конфигурация
└── pnl_template.yaml   # Бизнес-конфигурация (шаблон PnL)
```

**Правила при добавлении нового модуля в config:**

1. `routes.yaml` — добавить блок маршрутов модуля:
   ```yaml
   newmodule_controllers:
       resource:
           path: ../src/NewModule/Controller/
           namespace: App\NewModule\Controller
       type: attribute
   ```

2. `doctrine.yaml` — добавить маппинг Entity:
   ```yaml
   NewModule:
       type: attribute
       is_bundle: false
       dir: '%kernel.project_dir%/src/NewModule/Entity'
       prefix: 'App\NewModule\Entity'
       alias: NewModule
   ```

3. `services.yaml` — только если нужна явная конфигурация (tagged services, interface bindings). Autowiring покрывает 90% случаев.

4. `messenger.yaml` — если модуль имеет async Messages, добавить routing.

---

### 21.20. Принятие архитектурных решений: Decision Matrix

Быстрая таблица для принятия решений при разработке:

**Где разместить код?**

| Вопрос | Ответ | Размещение |
|---|---|---|
| Код обрабатывает HTTP? | Да | `Controller/` |
| Код оркестрирует бизнес-процесс? | Да | `Application/{Verb}{Noun}Action` |
| Код описывает правило предметной области? | Да | `Domain/Policy` или `Domain/Service` |
| Код — неизменяемое значение? | Да | `Domain/ValueObject/` |
| Код делает SQL-запрос? | Да | `Repository/` или `Infrastructure/Query/` |
| Код общается с внешним API? | Да | `Infrastructure/Api/` |
| Код нужен другому модулю? | Да | Обернуть в `Facade/` |
| Код нужен всем модулям? | Да | `Shared/` |

**Как общаться между модулями?**

| Нужно | Решение |
|---|---|
| Прочитать данные другого модуля | `{Module}/Facade` → read-метод |
| Изменить данные другого модуля | `{Module}/Facade` → command-метод |
| Реагировать на событие другого модуля | Messenger: модуль-источник dispatch → Handler в модуле-получателе |
| Использовать Entity другого модуля в ORM-связи | Допустимо напрямую (Doctrine ManyToOne) |
| Использовать Enum другого модуля | Допустимо напрямую |

**Sync vs Async?**

| Критерий | Sync (Action) | Async (Messenger) |
|---|---|---|
| Время выполнения | < 1-2 сек | > 3 сек |
| Пользователь ждёт результат? | Да | Нет (фоновый процесс) |
| Внешний API / import | — | ✅ |
| Побочный эффект (email, нотификация) | — | ✅ |
| Может временно упасть (retry) | — | ✅ (retry_strategy в Messenger) |
| Тяжёлая цепочка шагов | — | ✅ (каждый шаг = Message) |

---

## 22. Версионирование этого документа

Этот документ — живой артефакт. При изменении архитектурных решений:

1. Обновить соответствующий раздел.
2. Инкрементировать версию в шапке.
3. Добавить запись в changelog ниже.

### Changelog

| Версия | Дата | Что изменилось |
|---|---|---|
| 1.0 | 2026-03-26 | Первая версия: модули, слои, правила, антипаттерны |
| 1.1 | 2026-03-26 | Добавлен раздел 21: Best Practices Symfony-архитектуры |
| 1.2 | 2026-03-26 | Добавлен раздел 21.17.1: Test Entity Builders |
| 1.3 | 2026-03-26 | Стратегия разъединения модулей: `companyId` вместо `Company`, ChoiceType вместо EntityType, формы через Facade |