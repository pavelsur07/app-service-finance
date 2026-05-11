# PATTERNS.md — VashFinDir

> Читай нужный раздел по задаче, не весь файл.
> Версия: 1.1 / 2026-05-11

## Навигация

- [1. Слои и ответственность](#1-слои-и-ответственность)
- [2. Controller](#2-controller)
- [3. Action](#3-action)
- [4. Domain: Policy и Value Object](#4-domain-policy-и-value-object)
- [5. Infrastructure: Contracts](#5-infrastructure-contracts)
- [6. Infrastructure: Query](#6-infrastructure-query)
- [7. Facade](#7-facade)
- [8. Формы с данными чужого модуля](#8-формы-с-данными-чужого-модуля)
- [9. Tagged Services](#9-tagged-services)
- [10. Messenger: Message и Handler](#10-messenger-message-и-handler)
- [11. Entity](#11-entity)
- [12. DTO](#12-dto)
- [13. Обработка ошибок](#13-обработка-ошибок)
- [14. Multi-tenancy: изоляция данных](#14-multi-tenancy-изоляция-данных)
- [15. Doctrine Listeners](#15-doctrine-listeners)
- [16. Тесты](#16-тесты)
- [17. Entity Builder](#17-entity-builder)
- [18. Decision Matrix](#18-decision-matrix)
- [19. OpenAPI / Nelmio](#19-openapi--nelmio)
- [20. Событийная модель: Доменные события](#20-событийная-модель-доменные-события)
- [21. Оптимистичная блокировка](#21-оптимистичная-блокировка)
- [22. Idempotency в Messenger](#22-idempotency-в-messenger)

---

## 1. Слои и ответственность

| Слой | Отвечает за | НЕ отвечает за |
|---|---|---|
| **Controller** | HTTP: десериализация → Action → Response | Бизнес-логика, SQL, валидация |
| **Action** | Оркестрация: Entity, Domain-политики, flush | HTTP, Session, шаблоны |
| **Domain** | Бизнес-правила, инварианты, Value Objects | Doctrine, HTTP, внешние API |
| **Infrastructure** | БД-запросы, HTTP-клиенты, внешние системы | Бизнес-решения |
| **Facade** | Публичный API модуля для других модулей | Собственная бизнес-логика |

Эталонный поток:
```
Controller → Action → Domain Policy → Infrastructure (Checker/Query)
```

---

## 2. Controller

```php
// src/Catalog/Controller/Api/ProductCreateController.php
#[OA\Tag(name: 'Catalog')]
final class ProductCreateController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CreateProductAction $action,
    ) {}

    #[Route('/api/products', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] CreateProductRequest $request): JsonResponse
    {
        $companyId = $this->companyService->getActiveCompany()->getId();

        try {
            $id = ($this->action)($companyId, CreateProductCommand::fromRequest($request));
            return $this->json(['data' => ['id' => $id]], 201);
        } catch (\DomainException $e) {
            return $this->json(['error' => ['code' => 'domain_error', 'message' => $e->getMessage()]], 422);
        }
    }
}
```

**Правила:** один контроллер = один `__invoke` · маршруты через `#[Route]` · ноль бизнес-логики

---

## 3. Action

```php
// src/Catalog/Application/CreateProductAction.php
final class CreateProductAction
{
    public function __construct(
        private readonly ProductSkuPolicy $skuPolicy,
        private readonly CompanyFacade $companyFacade,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(string $companyId, CreateProductCommand $cmd): string
    {
        $company = $this->companyFacade->findById($companyId)
            ?? throw new \DomainException('Компания не найдена.');

        $this->skuPolicy->assertSkuIsUnique($cmd->sku, $companyId);

        $product = new Product(Uuid::uuid7()->toString(), $companyId, $cmd->name);
        $product->setSku($cmd->sku);

        $this->em->persist($product);
        $this->em->flush(); // flush — только здесь

        return $product->getId();
    }
}
```

**Правила:** без `Request`/`Response` · flush только в Action · максимум ~100 строк · побочные эффекты → Messenger dispatch

---

## 4. Domain: Policy и Value Object

### Policy

```php
final class ProductSkuPolicy
{
    public function __construct(
        private readonly ProductSkuUniquenessChecker $checker,
    ) {}

    public function assertSkuIsUnique(string $sku, string $companyId): void
    {
        if (!$this->checker->isUnique($sku, $companyId)) {
            throw new \DomainException('SKU уже занят в рамках компании.');
        }
    }
}
```

### Value Object

```php
final readonly class ListingKey
{
    public function __construct(
        public readonly string $marketplaceSku,
        public readonly string $size,
    ) {}

    public function toString(): string { return "{$this->marketplaceSku}:{$this->size}"; }

    public static function fromString(string $key): self
    {
        [$sku, $size] = explode(':', $key, 2);
        return new self($sku, $size);
    }
}
```

**Когда выделять:** логика нужна в нескольких Actions · правило сложнее одного `if` · есть понятие из предметной области

---

## 5. Infrastructure: Contracts

Domain объявляет интерфейс — Infrastructure реализует.

```php
// Domain — что нужно
interface ProductSkuUniquenessChecker
{
    public function isUnique(string $sku, string $companyId): bool;
}

// Infrastructure — как
final class ProductSkuUniquenessCheckerDoctrine implements ProductSkuUniquenessChecker
{
    public function __construct(private readonly Connection $connection) {}

    public function isUnique(string $sku, string $companyId): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM products WHERE sku = :sku AND company_id = :companyId',
            compact('sku', 'companyId'),
        );
        return (int) $count === 0;
    }
}
```

```yaml
# config/services.yaml
App\Catalog\Domain\ProductSkuUniquenessChecker:
    '@App\Catalog\Infrastructure\ProductSkuUniquenessCheckerDoctrine'
```

Для внешних API — та же структура: `Infrastructure/Api/Contract/` → `Infrastructure/Api/Wildberries/`.

---

## 6. Infrastructure: Query

Для сложных read-моделей — DBAL QueryBuilder, минуя ORM hydration.
**Query-класс возвращает `QueryBuilder`, не массив** — обязательно для Pagerfanta.

```php
final class ProductQuery
{
    public function __construct(private readonly Connection $connection) {}

    public function createByCompanyQB(string $companyId, ProductListFilter $filter): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.name', 'p.sku', 'p.status')
            ->from('products', 'p')
            ->where('p.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('p.created_at', 'DESC');

        if ($filter->status !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $filter->status->value);
        }

        if ($filter->search !== null) {
            $qb->andWhere('p.name ILIKE :search')->setParameter('search', '%'.$filter->search.'%');
        }

        return $qb;
    }
}
```

**Когда Query вместо Repository:** сложные JOIN · агрегации (COUNT, SUM, GROUP BY) · отчёты · нужны только скалярные данные

---

## 7. Facade

```php
// src/Company/Facade/CounterpartyFacade.php
final readonly class CounterpartyFacade
{
    public function __construct(private readonly CounterpartyRepository $repository) {}

    /** @return list<array{id: string, name: string}> */
    public function getChoicesForCompany(string $companyId): array
    {
        return $this->repository->findChoicesForCompany($companyId);
    }

    /** @return array<string, string> uuid => name */
    public function getNamesByIds(array $ids): array
    {
        return $this->repository->findNamesByIds($ids);
    }
}
```

**Правила:** `final readonly class` · принимает скаляры/DTO, не Entity чужого модуля · без бизнес-логики · минимальный публичный интерфейс

---

## 8. Формы с данными чужого модуля

```php
// Controller: получить данные через Facade
$choices = $this->counterpartyFacade->getChoicesForCompany($companyId);
$form = $this->createForm(CreateDealType::class, null, ['counterparty_choices' => $choices]);

// Form: ChoiceType, не EntityType
$builder->add('counterpartyId', ChoiceType::class, [
    'label'       => 'Контрагент',
    'placeholder' => 'Без контрагента',
    'choices'     => array_column($options['counterparty_choices'], 'id', 'name'),
]);

// Шаблон: имена по ID
$counterpartyNames = $this->counterpartyFacade->getNamesByIds($ids);
// {{ counterparty_names[deal.counterpartyId] ?? '—' }}
```

---

## 9. Tagged Services

```yaml
# config/services.yaml
App\Marketplace\Service\WbCommissionCalculator:
    tags: [{ name: 'app.marketplace.cost_calculator', priority: 110 }]

App\Marketplace\Application\ProcessWbCostsAction:
    arguments:
        $costCalculators: !tagged_iterator app.marketplace.cost_calculator
```

```php
final class ProcessWbCostsAction
{
    /** @param iterable<CostCalculatorInterface> $costCalculators */
    public function __construct(private readonly iterable $costCalculators) {}

    public function __invoke(Sale $sale): void
    {
        foreach ($this->costCalculators as $calculator) {
            $calculator->calculate($sale);
        }
    }
}
```

**Текущие теги:**

| Тег | Назначение |
|---|---|
| `app.marketplace.cost_calculator` | Калькуляторы WB-затрат |
| `app.marketplace.adapter` | Адаптеры маркетплейсов |
| `app.balance.value_provider` | Провайдеры значений баланса |
| `marketplace.data_source` | Источники данных для закрытия месяца |
| `app.notification.sender` | Каналы отправки уведомлений |

**Когда:** набор однотипных обработчиков · новый обработчик = новый класс + тег (OCP)

---

## 10. Messenger: Message и Handler

### Выбор транспорта

| Транспорт | Когда | Примеры |
|---|---|---|
| `async_sync` | Внешние HTTP (marketplace, банк, email) | `SyncWbReportMessage`, `SendEmailMessage` |
| `async_pipeline` | Локальная обработка, DB/CPU-heavy | `ProcessRawDocumentMessage`, `RecalcSnapshotsMessage` |
| `async_ads` | Ozon Performance polling (до 10 мин) | `FetchOzonAdStatisticsMessage` |

### Message

```php
final readonly class SyncWbReportMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId, // ID, не Entity
        public string $actorUserId,  // кто инициировал — явно
    ) {}
}
```

```yaml
# config/packages/messenger.yaml
App\Marketplace\Message\SyncWbReportMessage: async_sync
```

### Handler

```php
#[AsMessageHandler]
final class SyncWbReportMessageHandler
{
    public function __construct(
        private readonly ConnectionRepository $connectionRepository,
        private readonly SyncWbReportAction $action,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SyncWbReportMessage $message): void
    {
        $connection = $this->connectionRepository
            ->findByIdAndCompany($message->connectionId, $message->companyId);

        if ($connection === null) {
            $this->logger->warning('Соединение не найдено', ['connectionId' => $message->connectionId]);
            return;
        }

        try {
            ($this->action)($connection, $message->actorUserId);
        } catch (\Exception $e) {
            $this->logger->error('Ошибка синхронизации WB', [
                'error'        => $e->getMessage(),
                'connectionId' => $message->connectionId,
            ]);
            throw $e; // перебросить для retry
        }
    }
}
```

**Правила:** нет `Request`/`Session`/`Security` · Entity загружать заново по ID из Message · catch → log → rethrow

---

## 11. Entity

```php
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_month_closes')]
class MarketplaceMonthClose
{
    #[ORM\Id, ORM\Column(type: 'guid')]
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

    public function close(): void
    {
        if ($this->status === 'closed') {
            throw new \DomainException('Месяц уже закрыт.');
        }
        $this->status    = 'closed';
        $this->updatedAt = new \DateTimeImmutable();
    }

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

**Правила:** `new Entity()` только в Action · guard-методы бросают `\DomainException` · нет Repository/Messenger внутри · `DateTimeImmutable` везде

---

## 12. DTO

### Command DTO

```php
final readonly class CreateProductCommand
{
    public function __construct(
        public string $name,
        public string $sku,
        public ?string $barcode = null,
    ) {}

    public static function fromRequest(CreateProductRequest $request): self
    {
        return new self($request->name, $request->sku, $request->barcode);
    }
}
```

### Filter DTO

```php
final class ProductListFilter
{
    public string $companyId     = '';
    public ?ProductStatus $status = null;
    public ?string $search        = null;
    public int $page              = 1;
    public int $perPage           = 50;

    public static function fromRequest(Request $request): self
    {
        $f          = new self();
        $f->search  = $request->query->get('search');
        $f->status  = ProductStatus::tryFrom($request->query->get('status', ''));
        $f->page    = max(1, $request->query->getInt('page', 1));
        $f->perPage = min(200, $request->query->getInt('limit', 50));
        return $f;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone            = clone $this;
        $clone->companyId = $companyId;
        return $clone;
    }
}
```

**Правила:** `readonly` для Command · `fromRequest()` только в Filter DTO · не использовать Entity как DTO

---

## 13. Обработка ошибок

### Стратегия по слоям

| Слой | Действие |
|---|---|
| Domain / Entity | `throw new \DomainException(...)` |
| Action | `throw new \DomainException(...)` или кастомное из `Exception/` |
| Infrastructure | Оборачивает техническое в `\DomainException` |
| Controller | Ловит `\DomainException` → JSON 422 или flash-redirect |

### Инфраструктурная ошибка → доменная

```php
try {
    $this->em->flush();
} catch (UniqueConstraintViolationException) {
    throw new \DomainException('Товар с таким SKU уже существует.');
}
```

### Кастомные исключения

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

### Формат ответа (новый код — только так)

```json
{ "error": { "code": "deal_not_found", "message": "Сделка не найдена." } }
```

`code` — snake_case, стабильный идентификатор (фронт завязывается на него).

---

## 14. Multi-tenancy: изоляция данных

### Entity

```php
#[ORM\Column(type: 'guid')]
private string $companyId; // string, не ManyToOne на Company
```

### Repository

```php
// ✅ всегда с companyId
public function findByIdAndCompany(string $id, string $companyId): ?Product
{
    return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
}

// ❌ IDOR — запрещено
public function findById(string $id): ?Product { return $this->find($id); }
```

### Controller

```php
$company = $this->activeCompanyService->getActiveCompany();
$product = $this->productRepository->findByIdAndCompany($id, $company->getId());
if ($product === null) {
    throw $this->createNotFoundException();
}
```

### Handler (нет сессии)

`companyId` передаётся через Message, не берётся из Security.

---

## 15. Doctrine Listeners

```php
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AuditLogSubscriber
{
    public function postPersist(LifecycleEventArgs $args): void
    {
        if (!$args->getObject() instanceof CashTransaction) {
            return;
        }
        // side-effect: не вызывать flush()!
    }
}
```

**Когда:** аудит · автообновление `updatedAt` · денормализация read-моделей

**Нельзя:** flush() · бросать исключения · зависеть от Request/Session

---

## 16. Тесты

### Unit — Domain Policy

```php
final class ProductSkuPolicyTest extends TestCase
{
    public function testThrowsWhenSkuNotUnique(): void
    {
        $checker = $this->createMock(ProductSkuUniquenessChecker::class);
        $checker->method('isUnique')->willReturn(false);

        $this->expectException(\DomainException::class);
        (new ProductSkuPolicy($checker))->assertSkuIsUnique('SKU-001', 'company-uuid');
    }

    public function testPassesWhenSkuIsUnique(): void
    {
        $checker = $this->createMock(ProductSkuUniquenessChecker::class);
        $checker->method('isUnique')->willReturn(true);

        (new ProductSkuPolicy($checker))->assertSkuIsUnique('SKU-001', 'company-uuid');
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

        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $em->persist($company);
        $em->flush();

        $id = ($action)($company->getId(), new CreateProductCommand('Тест', 'TST-001'));

        $product = $em->find(Product::class, $id);
        $this->assertSame('TST-001', $product->getSku());
    }

    public function testThrowsOnDuplicateSku(): void
    {
        // создать первый → пытаться создать с тем же SKU
        $this->expectException(\DomainException::class);
        ($action)($companyId, new CreateProductCommand('Дубль', 'TST-001'));
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

```php
// tests/Builders/Catalog/ProductBuilder.php
final class ProductBuilder
{
    public const DEFAULT_ID         = '33333333-3333-3333-3333-333333333333';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $id;
    private string $companyId;
    private string $name             = 'Тестовый товар';
    private string $sku              = 'TST-001';
    private ProductStatus $status    = ProductStatus::Active;

    private function __construct()
    {
        $this->id        = self::DEFAULT_ID;
        $this->companyId = self::DEFAULT_COMPANY_ID;
    }

    public static function aProduct(): self { return new self(); }

    public function withIndex(int $i): self
    {
        $c = clone $this;
        $c->id  = sprintf('33333333-3333-3333-3333-%012d', $i);
        $c->sku = sprintf('TST-%03d', $i);
        return $c;
    }

    public function withCompanyId(string $id): self { $c = clone $this; $c->companyId = $id; return $c; }
    public function withName(string $name): self    { $c = clone $this; $c->name = $name; return $c; }
    public function asArchived(): self              { $c = clone $this; $c->status = ProductStatus::Archived; return $c; }
    public function asDraft(): self                 { $c = clone $this; $c->status = ProductStatus::Draft; return $c; }

    public function build(): Product
    {
        $p = new Product($this->id, $this->companyId, $this->name);
        $p->setSku($this->sku);
        $p->setStatus($this->status);
        return $p;
    }
}
```

**Правила:** `private __construct()` · `with*()` всегда clone · `DEFAULT_*` константы · `withIndex()` для серийного создания · семантические методы (`asArchived()`) читаются как бизнес-фраза

---

## 18. Decision Matrix

### Куда положить код?

| Вопрос | Размещение |
|---|---|
| Обрабатывает HTTP? | `Controller/` |
| Оркестрирует бизнес-процесс? | `Application/{Verb}{Noun}Action` |
| Описывает правило предметной области? | `Domain/Policy` или `Domain/Service` |
| Неизменяемое значение? | `Domain/ValueObject/` |
| Простой SQL-запрос? | `Repository/` |
| Сложный SQL (отчёт, агрегация)? | `Infrastructure/Query/` |
| Общается с внешним API? | `Infrastructure/Api/` |
| Нужен другому модулю? | `Facade/` |
| Нужен всем модулям? | `Shared/` |

### Межмодульное взаимодействие

| Нужно | Решение |
|---|---|
| Прочитать данные другого модуля | `{Module}/Facade` → read-метод |
| Изменить данные другого модуля | `{Module}/Facade` → command-метод |
| Реагировать на событие | Messenger: dispatch из источника → Handler |
| Использовать Enum другого модуля | Напрямую (допустимо) |

### Sync vs Async

| < 2 сек, пользователь ждёт | → Sync (Action) |
|---|---|
| > 3 сек или внешний API | → Async (Messenger) |
| Побочный эффект (email, пуш) | → Async |
| Нужен retry при падении | → Async |
| Цепочка тяжёлых шагов | → Async, каждый шаг = Message |

---

## 19. OpenAPI / Nelmio

Инструмент: `nelmio/api-doc-bundle` + `zircote/swagger-php`. UI: `/api/doc`.

### Область

- Документируем: `src/{Module}/Controller/Api/`
- НЕ документируем: `/api/public/`, debug/admin-эндпоинты, Facade-методы

### Response DTO — когда есть `toArray()` со snake_case

```php
// ❌ #[Model(type: Dto::class)] даст неправильную схему
// ✅ Описать вручную
#[OA\Schema(
    schema: 'SnapshotResponse',
    required: ['id', 'company_id'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'company_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
final readonly class SnapshotResponse {}
```

### Вложенные ссылки — только `new Model(type: X::class)`

```php
// ❌ Nelmio не зарегистрирует схему
new OA\Property(property: 'cost', ref: '#/components/schemas/CostBreakdown'),

// ✅
new OA\Property(property: 'cost', ref: new Model(type: CostBreakdown::class)),

// ✅ массивы
new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: new Model(type: Dto::class))),
```

### Контроллер

```php
#[OA\Tag(name: 'Marketplace Analytics')]
final class SnapshotShowController extends AbstractController
{
    #[OA\Get(summary: 'Снэпшот по ID', tags: ['Marketplace Analytics'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Найдено', content: new OA\JsonContent(ref: '#/components/schemas/SnapshotResponse'))]
    #[OA\Response(response: 404, description: 'Не найдено')]
    #[Route('/api/marketplace-analytics/snapshots/{id}', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse { /* логика не меняется */ }
}
```

### Чеклист нового эндпоинта

- [ ] `#[OA\Tag]` на классе, `#[OA\Get|Post|...]` с `summary` на методе
- [ ] `#[OA\RequestBody]` если принимает body
- [ ] `#[OA\Parameter]` для path/query (кроме `companyId` — из сессии, не документировать)
- [ ] Все HTTP-коды ответа включая 401, 422
- [ ] Response-DTO имеет `#[OA\Schema]` с полями как в JSON
- [ ] Новый тег зарегистрирован в `nelmio_api_doc.yaml`
- [ ] Запущен `make api-types`, `schema.d.ts` закоммичен

### TypeScript-контур

```typescript
// site/assets/api/client.ts
import { api } from '@/api/client';
const { data, error } = await api.GET('/api/marketplace-analytics/snapshots', {
    params: { query: { page: 1 } },
});
```

`schema.d.ts` генерируется через `make api-types`, коммитится в git. CI проверяет соответствие.
**Нельзя:** править `schema.d.ts` руками · использовать сырой `fetch()`.

### Анти-паттерны

```
#[Model(type: Dto::class)] при наличии toArray() со snake_case  — неправильная схема
Документировать companyId как query-параметр                    — он из сессии
Менять логику контроллера «заодно с документацией»             — отдельный PR
Строковый ref: '#/components/schemas/X' для PHP-классов        — Nelmio не зарегистрирует
```

---

## 20. Событийная модель: Доменные события

Когда нужно уведомить другой модуль о факте — без прямого вызова его Facade.

```php
// src/Deals/Domain/Event/DealStatusChangedEvent.php
final readonly class DealStatusChangedEvent
{
    public function __construct(
        public string $dealId,
        public string $companyId,
        public DealStatus $oldStatus,
        public DealStatus $newStatus,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
```

```php
// src/Deals/Entity/Deal.php
class Deal
{
    /** @var list<object> */
    private array $domainEvents = [];

    public function changeStatus(DealStatus $new): void
    {
        if ($this->status === $new) return;
        $old = $this->status;
        $this->status = $new;
        $this->domainEvents[] = new DealStatusChangedEvent($this->id, $this->companyId, $old, $new, new \DateTimeImmutable());
    }

    public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }
}
```

```php
// src/Deals/Application/ChangeDealStatusAction.php
public function __invoke(string $dealId, string $companyId, DealStatus $new): void
{
    $deal = $this->dealRepository->findByIdAndCompany($dealId, $companyId)
        ?? throw new DealNotFoundException($dealId);

    $deal->changeStatus($new);
    $this->em->flush();

    foreach ($deal->pullDomainEvents() as $event) {
        $this->eventDispatcher->dispatch($event);
    }
}
```

**Правила:** события — `readonly class` только со scalar ID · `pullDomainEvents()` вызывать после `flush()` · подписчики в других модулях — через `EventSubscriber`, не напрямую · тяжёлые подписчики (email, пересчёт) → dispatch через Messenger

---

## 21. Оптимистичная блокировка

Защита от race condition при параллельных обновлениях одной Entity.

```php
// Entity
use Doctrine\ORM\Mapping as ORM;

class Document
{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;
}
```

```php
// Action: передавать version из запроса
public function __invoke(string $id, string $companyId, int $expectedVersion, UpdateCommand $cmd): void
{
    $document = $this->repo->findByIdAndCompany($id, $companyId)
        ?? throw new DocumentNotFoundException($id);

    try {
        $document->update($cmd);
        $this->em->flush(); // Doctrine проверит version автоматически
    } catch (OptimisticLockException) {
        throw new \DomainException('Документ был изменён другим пользователем. Обновите страницу.');
    }
}
```

```php
// Controller: принимать version в теле запроса
// Request: { "version": 3, "name": "..." }
// Response 422 при конфликте версий
```

**Когда использовать:** Entity, которую могут редактировать несколько пользователей одновременно (документы, настройки). Не нужно для append-only Entity (транзакции, логи).

---

## 22. Idempotency в Messenger

Защита от дублирования при retry — если Handler упал после flush, но до успешного ack.

```php
// Entity для хранения обработанных сообщений
// src/Shared/Entity/ProcessedMessage.php
#[ORM\Entity]
#[ORM\Table(name: 'processed_messages')]
class ProcessedMessage
{
    #[ORM\Id, ORM\Column]
    private string $messageId;

    #[ORM\Column]
    private \DateTimeImmutable $processedAt;
}
```

```php
// src/Shared/Messenger/IdempotentHandlerTrait.php
trait IdempotentHandlerTrait
{
    private function isAlreadyProcessed(string $messageId): bool
    {
        return $this->em->find(ProcessedMessage::class, $messageId) !== null;
    }

    private function markAsProcessed(string $messageId): void
    {
        $this->em->persist(new ProcessedMessage($messageId, new \DateTimeImmutable()));
        // flush вызывает Action — не здесь
    }
}
```

```php
#[AsMessageHandler]
final class ImportBankStatementHandler
{
    use IdempotentHandlerTrait;

    public function __invoke(ImportBankStatementMessage $message): void
    {
        if ($this->isAlreadyProcessed($message->idempotencyKey)) {
            $this->logger->info('Сообщение уже обработано, пропускаем', ['key' => $message->idempotencyKey]);
            return;
        }

        ($this->action)($message->companyId, $message->statementId);
        $this->markAsProcessed($message->idempotencyKey);
    }
}
```

**Когда:** импорты из внешних систем · финансовые операции · любой Handler с деструктивными или неотменяемыми эффектами. Не нужно для read-only и вычислительных Handler.