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

### 3.1. РАЗРЕШЕНО

- Модуль **может** зависеть от `Shared/`.
- Модуль **может** зависеть от **Facade** другого модуля.
- Модуль **может** зависеть от **Entity** другого модуля (ORM-связи). Это допустимый компромисс для Doctrine.
- Модуль **может** зависеть от **Enum** другого модуля.
- Модуль **может** зависеть от **DTO** другого модуля, если это публичный DTO.

### 3.2. ЗАПРЕЩЕНО

- **Никогда** не импортировать `Service`, `Application`, `Repository`, `Infrastructure`, `Form`, `Controller` из чужого модуля напрямую.
- **Никогда** не вызывать `EntityManager->getRepository(SomeOtherModuleEntity::class)` из чужого модуля. Только через Facade.
- **Никогда** не создавать циклических зависимостей между модулями.

### 3.3. Facade — публичный API модуля

Facade — единственная точка входа для межмодульного взаимодействия (кроме Entity и Enum).

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

- **Каждая бизнес-сущность** ОБЯЗАНА иметь связь `#[ORM\ManyToOne] private Company $company`.
- Уникальные индексы — **всегда** с учётом `company_id`.
- Репозиторий: все методы выборки **обязаны** принимать `companyId` или `Company`.

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
- **Минимальные требования:** тесты на все Action-классы и критический бизнес-код.

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
9. **Миграция:** проверен SQL, нет destructive-изменений без плана.
10. **Нет:** `dump()`, `dd()`, хардкод-секретов, raw SQL без параметров.

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
6. Всегда добавлять связь с `Company` в бизнес-сущностях.
7. Создавать тонкие контроллеры (один action = `__invoke`).
8. Бизнес-логику выносить в Action-классы.
9. Для межмодульного взаимодействия использовать Facade.
10. Для async-задач: Message с ID, Handler с `__invoke`.
11. Добавлять `#[ORM\Table(name: '...')]` к каждой Entity.
12. Следовать конвенциям именования из раздела 2.1.
13. При создании нового Message — добавлять routing в `messenger.yaml`.
14. Не использовать `dump()`, `dd()`, `var_dump()`.
15. Комментировать код и PR-описания на **русском языке**.