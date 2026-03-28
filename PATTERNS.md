# PATTERNS.md — VashFinDir

> Справочник паттернов для Claude Code.
> Читай когда нужно реализовать конкретный паттерн — не весь файл сразу.
> Актуальная версия: 1.0 / 2026-03-28

---

## Навигация

- [1. Слои и ответственность](#1-слои-и-ответственность)
- [2. Controller → Action → Domain → Infrastructure](#2-controller--action--domain--infrastructure)
- [3. Action (Application Layer)](#3-action-application-layer)
- [4. Domain: Policy и Value Object](#4-domain-policy-и-value-object)
- [5. Infrastructure: Contracts и реализации](#5-infrastructure-contracts-и-реализации)
- [6. Infrastructure: Query-объекты](#6-infrastructure-query-объекты)
- [7. Facade — публичный API модуля](#7-facade--публичный-api-модуля)
- [8. Формы с данными чужого модуля](#8-формы-с-данными-чужого-модуля)
- [9. Tagged Services (Strategy / Chain)](#9-tagged-services-strategy--chain)
- [10. Messenger: Message и Handler](#10-messenger-message-и-handler)
- [11. Entity: конструктор и guard-методы](#11-entity-конструктор-и-guard-методы)
- [12. DTO](#12-dto)
- [13. Обработка ошибок](#13-обработка-ошибок)
- [14. Multi-tenancy: изоляция данных](#14-multi-tenancy-изоляция-данных)
- [15. Doctrine Listeners](#15-doctrine-listeners)
- [16. Тесты: Unit и Integration](#16-тесты-unit-и-integration)
- [17. Entity Builder](#17-entity-builder)
- [18. Decision Matrix](#18-decision-matrix)

---

## 1. Слои и ответственность

| Слой | Отвечает за | НЕ отвечает за |
|---|---|---|
| **Controller** | HTTP: десериализация Request → вызов Action → Response | Бизнес-логика, SQL, валидация правил |
| **Application (Action)** | Оркестрация: загрузка Entity, вызов Domain-политик, persist/flush | HTTP, Session, шаблоны |
| **Domain** | Бизнес-правила, инварианты, Value Objects | Doctrine, HTTP, внешние API |
| **Infrastructure** | БД-запросы, HTTP-клиенты, внешние системы | Бизнес-решения |
| **Facade** | Публичный API модуля для других модулей | Собственная бизнес-логика |

---

## 2. Controller → Action → Domain → Infrastructure

Эталонный поток вызовов в проекте:

```
Controller (ProductEditController)
    → вызывает Action (UpdateProductAction)
        → вызывает Domain (ProductSkuPolicy.assertSkuIsUnique)
            → использует Infrastructure (ProductSkuUniquenessCheckerDoctrine)
```

Контроллер не знает о проверке SKU. Action не знает о SQL. Domain не знает о Doctrine.

---

## 3. Action (Application Layer)

Один Action = один use-case. `final class`, метод `__invoke`.

```php
// src/Catalog/Application/CreateProductAction.php
final class CreateProductAction
{
    public function __construct(
        private readonly ProductSkuPolicy $productSkuPolicy,
        private readonly CompanyFacade $companyFacade,        // межмодульное — через Facade
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(string $companyId, CreateProductCommand $cmd): string
    {
        $company = $this->companyFacade->findById($companyId)
            ?? throw new \DomainException('Компания не найдена.');

        $this->productSkuPolicy->assertSkuIsUnique($cmd->sku, $companyId);

        $product = new Product(Uuid::uuid7()->toString(), $companyId, $cmd->name);
        $product->setSku($cmd->sku);

        $this->entityManager->persist($product);
        $this->entityManager->flush();                        // flush — только здесь

        return $product->getId();
    }
}
```

**Правила:**
- Не принимает `Request`, не возвращает `Response`
- Бросает `\DomainException` при нарушении бизнес-правил
- Максимум ~100 строк. Если больше — выделить Domain-сервис или Policy
- Побочные эффекты (email, уведомления) → dispatch через Messenger, не напрямую

---

## 4. Domain: Policy и Value Object

### Policy — проверка бизнес-правил

```php
// src/Catalog/Domain/ProductSkuPolicy.php
final class ProductSkuPolicy
{
    public function __construct(
        private readonly ProductSkuUniquenessChecker $checker, // интерфейс, не Doctrine
    ) {}

    public function assertSkuIsUnique(string $sku, string $companyId): void
    {
        if (!$this->checker->isUnique($sku, $companyId)) {
            throw new \DomainException('SKU уже занят в рамках компании.');
        }
    }
}
```

### Value Object — иммутабельные доменные значения

```php
// src/Marketplace/Domain/ValueObject/ListingKey.php
final readonly class ListingKey
{
    public function __construct(
        public readonly string $marketplaceSku,
        public readonly string $size,
    ) {}

    public function toString(): string
    {
        return sprintf('%s:%s', $this->marketplaceSku, $this->size);
    }

    public static function fromString(string $key): self
    {
        [$sku, $size] = explode(':', $key, 2);
        return new self($sku, $size);
    }
}
```

**Когда выделять в Domain:**
- Логика используется в нескольких Actions
- Правило сложнее одной проверки `if`
- Есть понятие из предметной области (ListingKey, Period, Money)

---

## 5. Infrastructure: Contracts и реализации

Domain объявляет **что** нужно (интерфейс). Infrastructure реализует **как**.

```php
// src/Catalog/Domain/ProductSkuUniquenessChecker.php — ИНТЕРФЕЙС в Domain
interface ProductSkuUniquenessChecker
{
    public function isUnique(string $sku, string $companyId): bool;
}
```

```php
// src/Catalog/Infrastructure/ProductSkuUniquenessCheckerDoctrine.php — РЕАЛИЗАЦИЯ
final class ProductSkuUniquenessCheckerDoctrine implements ProductSkuUniquenessChecker
{
    public function __construct(private readonly Connection $connection) {}

    public function isUnique(string $sku, string $companyId): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM products WHERE sku = :sku AND company_id = :companyId',
            ['sku' => $sku, 'companyId' => $companyId],
        );
        return (int) $count === 0;
    }
}
```

```yaml
# config/services.yaml — привязка интерфейса к реализации
App\Catalog\Domain\ProductSkuUniquenessChecker:
    '@App\Catalog\Infrastructure\ProductSkuUniquenessCheckerDoctrine'
```

**Для внешних API — тот же принцип:**

```
Infrastructure/Api/Contract/MarketplaceFetcherInterface.php  — интерфейс
Infrastructure/Api/Wildberries/WildberriesFetcher.php        — реализация WB
Infrastructure/Api/Ozon/OzonFetcher.php                      — реализация Ozon
```

---

## 6. Infrastructure: Query-объекты

Для сложных read-моделей (отчёты, агрегации, multi-join) — DBAL QueryBuilder в `Infrastructure/Query/`, минуя ORM hydration.

```php
// src/Catalog/Infrastructure/Query/ProductQuery.php
final class ProductQuery
{
    public function __construct(private readonly Connection $connection) {}

    public function findForListing(string $companyId, ProductListFilter $filter): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.name', 'p.sku', 'p.status')
            ->from('products', 'p')
            ->where('p.company_id = :companyId')
            ->setParameter('companyId', $companyId);

        if ($filter->status !== null) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $filter->status->value);
        }

        if ($filter->search !== null) {
            $qb->andWhere('p.name ILIKE :search')
               ->setParameter('search', '%' . $filter->search . '%');
        }

        return $qb->orderBy('p.created_at', 'DESC')
                  ->setMaxResults($filter->perPage)
                  ->setFirstResult(($filter->page - 1) * $filter->perPage)
                  ->fetchAllAssociative();
    }
}
```

**Когда использовать Query вместо Repository:**
- Сложные JOIN с несколькими таблицами
- Агрегации (COUNT, SUM, GROUP BY)
- Отчёты и дашборды
- Когда ORM hydration избыточен (нужны только скалярные данные)

---

## 7. Facade — публичный API модуля

Единственная точка входа для межмодульного взаимодействия.

```php
// src/Company/Facade/CounterpartyFacade.php
final readonly class CounterpartyFacade
{
    public function __construct(private readonly CounterpartyRepository $repository) {}

    /**
     * @return list<array{id: string, name: string}>
     */
    public function getChoicesForCompany(string $companyId): array
    {
        return $this->repository->findChoicesForCompany($companyId);
    }

    /**
     * @param string[] $ids
     * @return array<string, string>  uuid => name
     */
    public function getNamesByIds(array $ids): array
    {
        return $this->repository->findNamesByIds($ids);
    }
}
```

**Правила Facade:**
- `final readonly class`
- Принимает скалярные типы и DTO, не Entity чужого модуля
- Возвращает скаляры, DTO или Entity **своего** модуля
- Без бизнес-логики — только делегирование в Actions/Repository
- Минимальный интерфейс: выставлять только то, что реально нужно другим

---

## 8. Формы с данными чужого модуля

### Шаг 1. Facade возвращает простые данные

```php
// src/Company/Facade/CounterpartyFacade.php
public function getChoicesForCompany(string $companyId): array
{
    return $this->repository->findChoicesForCompany($companyId);
    // [['id' => 'uuid', 'name' => 'ООО Ромашка'], ...]
}
```

### Шаг 2. Controller получает данные и передаёт в форму

```php
// src/Deals/Controller/DealCreateController.php
final class DealCreateController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CounterpartyFacade $counterpartyFacade,  // Facade, не Repository
        private readonly CreateDealAction $createDealAction,
    ) {}

    public function __invoke(Request $request): Response
    {
        $companyId = $this->companyService->getActiveCompany()->getId();
        $choices = $this->counterpartyFacade->getChoicesForCompany($companyId);

        $form = $this->createForm(CreateDealType::class, null, [
            'counterparty_choices' => $choices,
        ]);
        // ...
    }
}
```

### Шаг 3. Form использует ChoiceType

```php
// src/Deals/Form/CreateDealType.php
$builder->add('counterpartyId', ChoiceType::class, [
    'label'       => 'Контрагент',
    'required'    => false,
    'placeholder' => 'Без контрагента',
    'choices'     => array_column($options['counterparty_choices'], 'id', 'name'),
]);
```

### Отображение имени по ID в шаблоне

```php
// В контроллере
$counterpartyNames = $this->counterpartyFacade->getNamesByIds($counterpartyIds);
```

```twig
{# В шаблоне #}
{{ counterparty_names[deal.counterpartyId] ?? '—' }}
```

---

## 9. Tagged Services (Strategy / Chain)

Для набора однотипных обработчиков где новый добавляется без изменения ядра.

```yaml
# config/services.yaml
App\Marketplace\Service\CostCalculator\WbCommissionCalculator:
    tags:
        - { name: 'app.marketplace.cost_calculator', priority: 110 }

App\Marketplace\Service\CostCalculator\WbLogisticsCalculator:
    tags:
        - { name: 'app.marketplace.cost_calculator', priority: 108 }

App\Marketplace\Application\ProcessWbCostsAction:
    arguments:
        $costCalculators: !tagged_iterator app.marketplace.cost_calculator
```

```php
// src/Marketplace/Application/ProcessWbCostsAction.php
final class ProcessWbCostsAction
{
    /** @param iterable<CostCalculatorInterface> $costCalculators */
    public function __construct(
        private readonly iterable $costCalculators,
    ) {}

    public function __invoke(Sale $sale): void
    {
        foreach ($this->costCalculators as $calculator) {
            $calculator->calculate($sale);
        }
    }
}
```

**Текущие tag-группы в проекте:**

| Тег | Назначение |
|---|---|
| `app.marketplace.cost_calculator` | Калькуляторы WB-затрат |
| `app.marketplace.adapter` | Адаптеры маркетплейсов (WB, Ozon) |
| `app.balance.value_provider` | Провайдеры значений баланса |
| `marketplace.data_source` | Источники данных для закрытия месяца |
| `app.notification.sender` | Каналы отправки уведомлений |

**Когда использовать:**
- Набор однотипных обработчиков (калькуляторы, адаптеры, провайдеры)
- Новый обработчик = новый класс + тег, без изменения ядра (Open/Closed Principle)

---

## 10. Messenger: Message и Handler

### Когда sync, когда async

| Критерий | Sync (Action) | Async (Messenger) |
|---|---|---|
| Время < 2 сек, пользователь ждёт | ✅ | — |
| Время > 3 сек, внешний API, импорт | — | ✅ |
| Побочный эффект (email, уведомление) | — | ✅ |
| Может временно упасть (retry нужен) | — | ✅ |
| Тяжёлая цепочка шагов | — | ✅ (каждый шаг = Message) |

### Message — только scalar ID

```php
// src/Marketplace/Message/SyncWbReportMessage.php
final readonly class SyncWbReportMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,    // ID, не объект
        public string $actorUserId,     // кто инициировал — передавать явно
    ) {}
}
```

После создания → добавить в `config/packages/messenger.yaml`:
```yaml
App\Marketplace\Message\SyncWbReportMessage: async
```

### Handler

```php
// src/Marketplace/MessageHandler/SyncWbReportMessageHandler.php
#[AsMessageHandler]
final class SyncWbReportMessageHandler
{
    public function __construct(
        private readonly MoySkladConnectionRepository $connectionRepository,
        private readonly SyncWbReportAction $syncAction,
        private readonly AppLogger $logger,
    ) {}

    public function __invoke(SyncWbReportMessage $message): void
    {
        // Загружаем Entity заново — данные могли измениться с момента dispatch
        $connection = $this->connectionRepository
            ->findByIdAndCompany($message->connectionId, $message->companyId);

        if ($connection === null) {
            $this->logger->warning('Соединение не найдено', [
                'connectionId' => $message->connectionId,
            ]);
            return;
        }

        try {
            ($this->syncAction)($connection, $message->actorUserId);
        } catch (\Exception $e) {
            $this->logger->error('Ошибка синхронизации WB', [
                'error' => $e->getMessage(),
                'connectionId' => $message->connectionId,
            ]);
            throw $e; // перебросить чтобы Messenger мог сделать retry
        }
    }
}
```

**Правила Handler:**
- Нет `Request`, `Session`, `Security->getUser()` — CLI-контекст воркера
- Всегда загружать Entity заново по ID из Message
- Оборачивать в try/catch → логировать → перебрасывать для retry

---

## 11. Entity: конструктор и guard-методы

```php
// src/Marketplace/Entity/MarketplaceMonthClose.php
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_month_closes')]
class MarketplaceMonthClose
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column]
    private string $status = 'open';

    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $companyId, MarketplaceType $marketplace)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        $this->id          = $id;
        $this->companyId   = $companyId;
        $this->marketplace = $marketplace;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
    }

    // Guard-метод — инвариант: нельзя закрыть уже закрытый
    public function close(): void
    {
        if ($this->status === 'closed') {
            throw new \DomainException('Месяц уже закрыт.');
        }
        $this->status    = 'closed';
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Guard-метод — инвариант: нельзя открыть уже открытый
    public function reopen(): void
    {
        if ($this->status === 'open') {
            throw new \DomainException('Месяц уже открыт.');
        }
        $this->status    = 'open';
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

**Правила:**
- `new Entity(...)` — только в Application-слое (Action)
- Guard-методы бросают `\DomainException` при нарушении инварианта
- Не помещать в Entity: Repository, Messenger dispatch, другие сервисы
- `DateTimeImmutable` везде

---

## 12. DTO

### Command DTO (входные данные для Action)

```php
// src/Catalog/Application/DTO/CreateProductCommand.php
final readonly class CreateProductCommand
{
    public function __construct(
        public string $name,
        public string $sku,
        public ?string $barcode = null,
    ) {}
}
```

### Filter DTO (параметры списка)

```php
// src/Catalog/DTO/ProductListFilter.php
final class ProductListFilter
{
    public string $companyId = '';
    public ?ProductStatus $status = null;
    public ?string $search = null;
    public int $page = 1;
    public int $perPage = 20;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $filter->search   = $request->query->get('search');
        $filter->status   = ProductStatus::tryFrom($request->query->get('status', ''));
        $filter->page     = max(1, $request->query->getInt('page', 1));
        $filter->perPage  = min(100, $request->query->getInt('per_page', 20));
        return $filter;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;
        return $clone;
    }
}
```

**Правила:**
- `readonly class` для Command DTO
- Без логики — только данные
- Не использовать Entity как DTO — всегда маппить
- `fromRequest()` допустим только в Filter DTO

---

## 13. Обработка ошибок

### Стратегия по слоям

| Слой | Как сигнализирует | Пример |
|---|---|---|
| Domain / Entity | `throw new \DomainException(...)` | «SKU уже занят» |
| Application (Action) | `throw new \DomainException(...)` | «Компания не найдена» |
| Infrastructure | Оборачивает в `DomainException` | `UniqueConstraintViolation → DomainException` |
| Controller | Ловит `\DomainException` → flash или JSON | — |

### Обёртка инфраструктурной ошибки в Action

```php
try {
    $this->entityManager->flush();
} catch (UniqueConstraintViolationException) {
    throw new \DomainException('Товар с таким SKU уже существует.');
}
```

### Контроллер ловит доменную ошибку

```php
// Web-контроллер
try {
    ($this->createAction)($companyId, $cmd);
} catch (\DomainException $e) {
    $this->addFlash('error', $e->getMessage());
    return $this->redirectToRoute('catalog_products_new');
}

// API-контроллер
try {
    $id = ($this->createAction)($companyId, $cmd);
    return $this->json(['data' => ['id' => $id]], 201);
} catch (\DomainException $e) {
    return $this->json(['error' => ['message' => $e->getMessage()]], 422);
}
```

### Кастомные исключения

Создавать в `{Module}/Exception/` когда нужно ловить конкретный тип:

```php
// src/Deals/Exception/DealNotFoundException.php
final class DealNotFoundException extends \DomainException
{
    public function __construct(string $dealId)
    {
        parent::__construct(sprintf('Сделка %s не найдена.', $dealId));
    }
}
```

---

## 14. Multi-tenancy: изоляция данных

Три уровня защиты от IDOR:

### Entity-уровень

```php
// Новые модули — string $companyId
#[ORM\Column(type: 'guid')]
private string $companyId;

// Старые модули — ManyToOne (допустимо, не переписывать без причины)
#[ORM\ManyToOne(targetEntity: Company::class)]
#[ORM\JoinColumn(nullable: false)]
private Company $company;
```

### Repository-уровень

```php
// ✅ Всегда с companyId
public function findByIdAndCompany(string $id, string $companyId): ?Product
{
    return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
}

// ❌ IDOR — никогда
public function findById(string $id): ?Product
{
    return $this->find($id);
}
```

### Controller-уровень

```php
$company = $this->activeCompanyService->getActiveCompany();
$product = $this->productRepository->findByIdAndCompany($id, $company->getId());
if ($product === null) {
    throw $this->createNotFoundException();
}
```

### В Messenger Handler (нет сессии)

`companyId` передаётся через Message, не берётся из сессии.

---

## 15. Doctrine Listeners

```php
// ✅ Атрибут вместо интерфейса EventSubscriber
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AuditLogSubscriber
{
    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CashTransaction) {
            return; // реагируем только на нужные Entity
        }
        // создать AuditLog — но не вызывать flush()!
    }
}
```

**Когда использовать:**
- Аудит и логирование (side-effect, не влияет на бизнес-поток)
- Автоматическое обновление `updatedAt`
- Денормализация в read-модели

**Когда НЕ использовать:**
- Бизнес-логика которая должна быть явной → в Action
- Отправка email/уведомлений → через Messenger
- Всё что зависит от контекста запроса (текущий пользователь, URL)

**Правила:**
- Listener не вызывает `flush()` — бесконечная рекурсия
- Listener не бросает исключения прерывающие основную транзакцию (если это побочный эффект)

---

## 16. Тесты: Unit и Integration

### Unit — Domain Policy (без БД, быстро)

```php
final class ProductSkuPolicyTest extends TestCase
{
    public function testThrowsWhenSkuNotUnique(): void
    {
        $checker = $this->createMock(ProductSkuUniquenessChecker::class);
        $checker->method('isUnique')->willReturn(false);

        $policy = new ProductSkuPolicy($checker);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SKU уже занят');

        $policy->assertSkuIsUnique('SKU-001', 'company-uuid');
    }

    public function testPassesWhenSkuIsUnique(): void
    {
        $checker = $this->createMock(ProductSkuUniquenessChecker::class);
        $checker->method('isUnique')->willReturn(true);

        $policy = new ProductSkuPolicy($checker);
        $policy->assertSkuIsUnique('SKU-001', 'company-uuid'); // не бросает

        $this->addToAssertionCount(1);
    }
}
```

### Integration — Action с реальной БД

```php
final class CreateProductActionTest extends KernelTestCase
{
    public function testCreatesProduct(): void
    {
        self::bootKernel();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $action = self::getContainer()->get(CreateProductAction::class);

        $owner   = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $productId = ($action)(
            $company->getId(),
            new CreateProductCommand(name: 'Тест', sku: 'TST-001'),
        );

        $this->assertNotEmpty($productId);

        $product = $em->find(Product::class, $productId);
        $this->assertSame('TST-001', $product->getSku());
    }

    public function testThrowsOnDuplicateSku(): void
    {
        // ... создать первый продукт, затем попытаться создать с тем же SKU
        $this->expectException(\DomainException::class);
        ($action)($companyId, new CreateProductCommand(name: 'Дубль', sku: 'TST-001'));
    }
}
```

### Пирамида тестов

| Слой | Тип | Приоритет |
|---|---|---|
| Domain (Policy, ValueObject) | Unit | 🔴 Высокий |
| Application (Action) | Integration (KernelTestCase) | 🔴 Высокий |
| Facade | Integration | 🟡 Средний |
| Controller | Functional (WebTestCase) | 🟡 Средний |
| Infrastructure (Query) | Integration | 🟢 Низкий |

---

## 17. Entity Builder

Обязателен для каждой новой Entity. Расположение: `tests/Builders/{Module}/{Entity}Builder.php`.

```php
// tests/Builders/Catalog/ProductBuilder.php
final class ProductBuilder
{
    // Детерминированные UUID — по первой цифре видно что это за сущность
    public const DEFAULT_ID         = '33333333-3333-3333-3333-333333333333';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $id;
    private string $companyId;
    private string $name   = 'Тестовый товар';
    private string $sku    = 'TST-001';
    private ProductStatus $status = ProductStatus::Active;

    private function __construct()
    {
        $this->id        = self::DEFAULT_ID;
        $this->companyId = self::DEFAULT_COMPANY_ID;
    }

    public static function aProduct(): self { return new self(); }

    // with*() всегда clone — иммутабельность
    public function withIndex(int $index): self
    {
        $clone     = clone $this;
        $clone->id  = sprintf('33333333-3333-3333-3333-%012d', $index);
        $clone->sku = sprintf('TST-%03d', $index);
        return $clone;
    }

    public function withName(string $name): self
    {
        $clone       = clone $this;
        $clone->name = $name;
        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone            = clone $this;
        $clone->companyId = $companyId;
        return $clone;
    }

    // Семантические методы — читаются как бизнес-фраза
    public function asArchived(): self
    {
        $clone         = clone $this;
        $clone->status = ProductStatus::Archived;
        return $clone;
    }

    public function asDraft(): self
    {
        $clone         = clone $this;
        $clone->status = ProductStatus::Draft;
        return $clone;
    }

    public function build(): Product
    {
        $product = new Product($this->id, $this->companyId, $this->name);
        $product->setSku($this->sku);
        $product->setStatus($this->status);
        return $product;
    }
}
```

**Использование в тестах:**

```php
// Минимум — дефолты покрывают всё
$product = ProductBuilder::aProduct()->build();

// Фокус на том что важно для теста
$archived = ProductBuilder::aProduct()->asArchived()->build();

// Серия с детерминированными ID
$p1 = ProductBuilder::aProduct()->withIndex(1)->build();
$p2 = ProductBuilder::aProduct()->withIndex(2)->build();

// Компания через другой Builder
$company = CompanyBuilder::aCompany()->withIndex(1)->build();
$product = ProductBuilder::aProduct()->withCompanyId($company->getId())->build();
```

**Правила Builder:**

| Правило | Зачем |
|---|---|
| `final class` | Builder не наследуется |
| `private __construct()` | Создание только через `aProduct()` |
| `with*()` возвращают `clone $this` | Иммутабельность, повторное использование |
| `DEFAULT_*` константы | Тесты ссылаются на `ProductBuilder::DEFAULT_ID` |
| `withIndex(int)` | Серийное создание с детерминированными UUID |
| Семантические методы | Тест читается как бизнес-сценарий |

---

## 18. Decision Matrix

### Где разместить код?

| Вопрос | Размещение |
|---|---|
| Код обрабатывает HTTP? | `Controller/` |
| Код оркестрирует бизнес-процесс? | `Application/{Verb}{Noun}Action` |
| Код описывает правило предметной области? | `Domain/Policy` или `Domain/Service` |
| Код — неизменяемое значение? | `Domain/ValueObject/` |
| Код делает SQL-запрос (простой)? | `Repository/` |
| Код делает сложный SQL (отчёт, агрегация)? | `Infrastructure/Query/` |
| Код общается с внешним API? | `Infrastructure/Api/` |
| Код нужен другому модулю? | Обернуть в `Facade/` |
| Код нужен всем модулям? | `Shared/` |

### Как общаться между модулями?

| Нужно | Решение |
|---|---|
| Прочитать данные другого модуля | `{Module}/Facade` → read-метод |
| Изменить данные другого модуля | `{Module}/Facade` → command-метод |
| Реагировать на событие | Messenger: dispatch из источника → Handler в получателе |
| Использовать Enum другого модуля | Напрямую (допустимо) |

### Sync vs Async?

| Критерий | Sync | Async |
|---|---|---|
| < 2 сек, пользователь ждёт | ✅ | — |
| > 3 сек или внешний API | — | ✅ |
| Побочный эффект (email, пуш) | — | ✅ |
| Нужен retry при падении | — | ✅ |
| Цепочка тяжёлых шагов | — | ✅ каждый шаг = Message |