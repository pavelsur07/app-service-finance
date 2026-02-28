# Module Development Standard v2.2 — Symfony

> **Для кого:** Разработчики + AI-ассистенты  
> **Контекст:** Legacy проект, строгая изоляция модулей, Worker/CLI safety

---

## ⚡ Quick Start Checklist

Используй перед каждым PR:

- [ ] ✅ Код только в `src/<ModuleName>/`
- [ ] ✅ `companyId` передан как `string` в Command
- [ ] ✅ `ActiveCompanyService` только в Controller
- [ ] ✅ Write через Application Action
- [ ] ✅ Read через Infrastructure/Query (DBAL)
- [ ] ✅ Нет Entity в Command/Message
- [ ] ✅ Нет прямых вызовов чужих Repository
- [ ] ✅ Работает в Worker/CLI (нет зависимости от HTTP)

---

## 📖 Глоссарий (простыми словами)

| Термин | Что это значит |
|--------|----------------|
| **Module** | Изолированная папка с функциональностью (напр. `Sales`, `Inventory`) |
| **Command** | DTO для записи данных (Create/Update/Delete) |
| **Query** | Чтение данных через чистый SQL (DBAL), возвращает массивы |
| **Application Action** | Обработчик команды, содержит бизнес-логику записи |
| **Domain** | Бизнес-правила и Entity (не знают про HTTP/Symfony) |
| **Infrastructure** | Техническая реализация (SQL, API клиенты, файлы) |
| **Facade** | Публичный API модуля для других модулей |
| **Worker/CLI** | Код, который работает вне HTTP (очереди, cron) |

---

## 🎯 Принципы (приоритеты)

### 🔴 КРИТИЧНО: Company Context Safety

**Правило:** `companyId` всегда scalar string, передаётся явно

```php
// ✅ ПРАВИЛЬНО
public string $companyId;

// ❌ НЕПРАВИЛЬНО
public Company $company;
```

**Почему:** Worker/CLI не имеют HTTP сессии, Entity сломает сериализацию

---

### 🟡 Важно: Изоляция модулей

- Один модуль = одна граница ответственности
- Межмодульное общение только через Facade
- Нельзя трогать чужие таблицы напрямую

---

### 🟢 Производительность: Fast Read

- Запрещено `findAll()` для списков
- Query слой: DBAL + конкретные колонки
- Нет гидрации Entity ради 2-3 полей

---

## 🚫 Запретные зоны (НЕ добавлять код)

```
src/Service/           ❌ Legacy, не расширяем
src/Controller/        ❌ Только внутри модуля
src/Entity/            ❌ Только внутри модуля
src/Repository/        ❌ Только внутри модуля
templates/_partials/   ❌ Старые шаблоны
```

---

## 📁 Структура модуля

### Где живёт код

```
src/<ModuleName>/           ← Весь код модуля здесь
templates/<module_name>/    ← UI шаблоны
templates/partials/         ← Разрешённые shared (новые)
```

### Внутренняя структура

```
src/<ModuleName>/
├── Application/         ← Actions (обработчики команд)
│   └── Command/         ← DTO для записи
├── Controller/          ← Тонкие контроллеры (только HTTP)
│   └── Api/             ← API контроллеры
├── Domain/              ← Бизнес-правила, валидация
├── Infrastructure/
│   ├── Repository/      ← ORM для записи
│   ├── Query/           ← DBAL для чтения
│   └── Clients/         ← Внешние API
├── Entity/              ← Doctrine сущности
├── Facade/              ← Публичный API для других модулей
├── DTO/                 ← Data Transfer Objects
├── Enum/                ← Перечисления
└── Form/                ← Symfony формы
```

---

## 🔄 Потоки данных (CQRS-light)

### ✍️ WRITE (изменение данных)

```
┌──────────┐    ┌─────────┐    ┌─────────────┐    ┌────────┐
│Controller│───▶│ Command │───▶│Application  │───▶│ Domain │
│          │    │  (DTO)  │    │   Action    │    │   +    │
│          │    │         │    │             │    │  Repo  │
└──────────┘    └─────────┘    └─────────────┘    └────────┘
```

### 📖 READ (чтение данных)

```
┌──────────┐    ┌─────────────────┐    ┌──────────┐
│Controller│───▶│Infrastructure/  │───▶│ DTO /    │
│          │    │ Query (DBAL)    │    │ array    │
└──────────┘    └─────────────────┘    └──────────┘
```

**Ключевое:**
- Controller не содержит логику
- Domain не знает про Symfony
- Application не знает про HTTP/Session

---

## 🏢 Company Context — КРИТИЧНОЕ ПРАВИЛО

### ⚠️ Единственный способ получить компанию

```php
use App\Shared\Service\ActiveCompanyService;

// ✅ ТОЛЬКО в Controller
$company = $this->companyService->getActiveCompany();
```

### 🚨 Где ЗАПРЕЩЕНО использовать ActiveCompanyService

```
❌ Application/Action
❌ MessageHandler
❌ Worker
❌ CLI Command
❌ Domain
❌ Infrastructure/Query
```

**Почему:** В worker/CLI нет HTTP сессии → fatal error

---

### ✅ Правильный поток Company Context

```
┌────────────┐
│ Controller │  1. Получает company через ActiveCompanyService
└─────┬──────┘
      │
      ▼
┌─────────────┐
│   Command   │  2. Передаёт companyId как string
│ companyId:  │
│   string    │
└─────┬───────┘
      │
      ▼
┌─────────────┐
│ Application │  3. Использует companyId напрямую
│   Action    │
└─────────────┘
```

---

### 🎯 Обязательный формат Command

```php
final class CreateOrderCommand
{
    public function __construct(
        public readonly string $companyId,      // ✅ scalar string
        public readonly string $actorUserId,    // ✅ scalar string
        public readonly int $customerId,
        public readonly int $amount,
    ) {}
}
```

**Запрещено:**

```php
// ❌ НЕПРАВИЛЬНО
public Company $company;        // Entity сломает Worker
public User $actor;             // Entity сломает сериализацию
```

---

## 🔗 Интеграция между модулями

### ❌ Запрещено

```php
// ❌ Прямой вызов чужого Query
$this->salesQuery->getOrders($companyId);

// ❌ SQL на чужих таблицах
SELECT * FROM sales_orders WHERE company_id = ?;

// ❌ Чужой Repository
$this->salesRepository->find($id);
```

### ✅ Разрешено

```php
// ✅ Только через Facade
$orders = $this->salesFacade->getOrdersForCompany($companyId);
```

---

## 🗄️ Работа с БД — Fast Read

### ❌ Антипаттерны

```php
// ❌ findAll для списков (N+1)
$orders = $this->orderRepository->findAll();

// ❌ Гидрация Entity ради 2 полей
$order = $this->orderRepository->find($id);
return ['id' => $order->getId(), 'status' => $order->getStatus()];
```

### ✅ Правильно

```php
// ✅ DBAL + конкретные колонки
public function getOrdersList(string $companyId): array
{
    return $this->connection->fetchAllAssociative(
        'SELECT id, order_number, status, total 
         FROM sales_orders 
         WHERE company_id = :companyId 
         LIMIT 100',
        ['companyId' => $companyId]
    );
}
```

---

## 🎨 Роутинг

| Тип | Паттерн |
|-----|---------|
| Web UI | `/<module_name>/...` |
| API | `/api/<module_name>/...` |
| Backoffice | `/backoffice/...` |

---

## 🔒 Безопасность

- Все операции в контексте `company_id`
- Backend проверяет роли + lock-period
- Фронтенд НЕ источник истины

---

## 📦 Entity и зависимости

### ✅ Разрешено

```php
use App\Core\Entity\Company;
use App\Core\Entity\User;
```

### ❌ Запрещено

```php
// ❌ Тянуть Entity другого модуля
use App\Sales\Entity\Order;  // в модуле Inventory
```

**Решение:** DTO или Facade

---

## 🧪 Тесты (минимум)

```
✅ 1 Unit test на Domain логику
✅ 1 Integration test на Query или сохранение
```

---

## 📋 Эталонные примеры

### 1️⃣ Command (DTO для записи)

```php
namespace App\Sales\Application\Command;

final class CreateOrderCommand
{
    public function __construct(
        public readonly string $companyId,       // ✅ string UUID
        public readonly string $actorUserId,     // ✅ string UUID
        public readonly int $customerId,
        public readonly int $amount,
    ) {}
}
```

---

### 2️⃣ Controller (единственное место ActiveCompanyService)

```php
namespace App\Sales\Controller;

use App\Sales\Application\Command\CreateOrderCommand;
use App\Sales\Application\CreateOrderAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class CreateOrderController extends AbstractController
{
    public function __construct(
        private readonly CreateOrderAction $action,
        private readonly ActiveCompanyService $companyService
    ) {}

    #[Route('/api/sales/orders', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        // 1. Получаем компанию (ТОЛЬКО здесь)
        $company = $this->companyService->getActiveCompany();
        $user = $this->getUser();

        // 2. Создаём команду с scalar ID
        $command = new CreateOrderCommand(
            companyId: (string) $company->getId(),
            actorUserId: (string) $user->getId(),
            customerId: 123,
            amount: 10000
        );

        // 3. Передаём в Application
        $orderId = ($this->action)($command);

        return $this->json(['id' => $orderId], 201);
    }
}
```

---

### 3️⃣ Application Action (бизнес-логика записи)

```php
namespace App\Sales\Application;

use App\Sales\Application\Command\CreateOrderCommand;
use App\Sales\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

final class CreateOrderAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(CreateOrderCommand $cmd): int
    {
        // Никаких ActiveCompanyService здесь!
        // companyId уже передан в команде
        
        $order = new Order(
            companyId: $cmd->companyId,
            customerId: $cmd->customerId,
            amount: $cmd->amount,
            createdByUserId: $cmd->actorUserId,
        );

        $this->em->persist($order);
        $this->em->flush();

        return $order->getId();
    }
}
```

---

### 4️⃣ Query (быстрое чтение)

```php
namespace App\Sales\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final class OrderListQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getActiveOrders(string $companyId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT 
                id, 
                order_number, 
                status, 
                total,
                created_at
             FROM sales_orders 
             WHERE company_id = :companyId 
               AND status != :cancelled
             ORDER BY created_at DESC 
             LIMIT 100',
            [
                'companyId' => $companyId,
                'cancelled' => 'cancelled',
            ]
        );
    }
}
```

---

### 5️⃣ Worker-safe Message

```php
namespace App\Sales\Message;

final class RecalculateOrdersMessage
{
    public function __construct(
        public readonly string $companyId,      // ✅ scalar
        public readonly string $actorUserId,    // ✅ scalar
    ) {}
}
```

---

### 6️⃣ Facade (публичный API модуля)

```php
namespace App\Sales\Facade;

use App\Sales\Infrastructure\Query\OrderListQuery;

final class SalesFacade
{
    public function __construct(
        private readonly OrderListQuery $orderQuery,
    ) {}

    /**
     * Публичный метод для других модулей
     */
    public function getOrdersForCompany(string $companyId): array
    {
        return $this->orderQuery->getActiveOrders($companyId);
    }
}
```

---

## ❌ Частые ошибки (антипаттерны)

### 1. ActiveCompanyService в Application

```php
// ❌ НЕПРАВИЛЬНО
class CreateOrderAction
{
    public function __construct(
        private readonly ActiveCompanyService $companyService  // ❌
    ) {}
    
    public function __invoke(CreateOrderCommand $cmd): int
    {
        $company = $this->companyService->getActiveCompany();  // ❌ Сломает Worker
    }
}
```

**Решение:** Передавай `companyId` через Command

---

### 2. Entity в Command

```php
// ❌ НЕПРАВИЛЬНО
final class CreateOrderCommand
{
    public function __construct(
        public Company $company,     // ❌ Сломает очередь
        public User $actor,          // ❌ Проблемы с сериализацией
    ) {}
}
```

**Решение:** Только scalar типы (`string`, `int`, `bool`)

---

### 3. Прямой доступ к чужому Repository

```php
// ❌ НЕПРАВИЛЬНО
namespace App\Inventory;

use App\Sales\Infrastructure\Repository\OrderRepository;  // ❌

class StockCheck
{
    public function __construct(
        private OrderRepository $orderRepo  // ❌ Нарушение изоляции
    ) {}
}
```

**Решение:** Используй `SalesFacade`

---

### 4. findAll() для списков

```php
// ❌ НЕПРАВИЛЬНО
$orders = $this->orderRepository->findAll();  // ❌ Все записы в память
```

**Решение:** Query с LIMIT через DBAL

---

### 5. Логика в Controller

```php
// ❌ НЕПРАВИЛЬНО
public function __invoke(): Response
{
    $company = $this->companyService->getActiveCompany();
    
    // ❌ Бизнес-логика в контроллере
    if ($company->getBalance() < 1000) {
        throw new \Exception('Low balance');
    }
    
    $order = new Order(...);  // ❌
    $this->em->persist($order);  // ❌
}
```

**Решение:** Вся логика в Application Action

---

## 🤖 AI-подсказки

### Если видишь в коде:

| Паттерн | Действие |
|---------|----------|
| `$this->activeCompanyService` в Application | ❌ ОШИБКА: переместить в Controller |
| `public Company $company` в Command | ❌ ОШИБКА: заменить на `string $companyId` |
| `->findAll()` для списка | ❌ ОШИБКА: использовать Query с DBAL |
| `use App\OtherModule\Repository` | ❌ ОШИБКА: использовать Facade |
| Логика > 5 строк в Controller | ⚠️ ПРЕДУПРЕЖДЕНИЕ: вынести в Action |

---

## 📊 Быстрая справка: Где что живёт

| Что | Где |
|-----|-----|
| Получение компании | `Controller` (ActiveCompanyService) |
| Бизнес-логика записи | `Application/Action` |
| Бизнес-правила | `Domain` |
| Чтение списков | `Infrastructure/Query` (DBAL) |
| Запись в БД | `Infrastructure/Repository` (ORM) |
| Entity | `Entity/` внутри модуля |
| Публичный API модуля | `Facade/` |
| HTTP обработка | `Controller/` |
| API контроллеры | `Controller/Api/` |
| Валидация форм | `Form/` |
| DTO для команд | `Application/Command/` |

---

## 📝 Definition of Done (перед PR)

```
✅ Контроллер тонкий (< 20 строк)
✅ Write через Application Action
✅ Read через Infrastructure/Query
✅ companyId передан через Command как string
✅ Нет Entity в Command/Message
✅ Нет ActiveCompanyService вне Controller
✅ Query без ORM гидрации (DBAL)
✅ Нет прямых обращений к чужим модулям
✅ Работает в Worker/CLI
✅ Минимум 1 тест
```

---

## 🎓 Шаблоны для быстрого старта

### Создание новой фичи (write)

1. Создай Command в `Application/Command/`
2. Создай Action в `Application/`
3. Создай Controller
4. Controller получает company → создаёт Command → вызывает Action

### Создание списка (read)

1. Создай Query в `Infrastructure/Query/`
2. Используй DBAL + `fetchAllAssociative()`
3. Возвращай массив/DTO
4. Добавь в Facade если нужен другим модулям

---

## 🔗 Связанные документы

- [ ] Company Context Contract (отдельный документ)
- [ ] Naming Conventions
- [ ] Security Policies
- [ ] Audit Requirements

---

**Версия:** 2.2  
**Обновлено:** 2025  
**Для вопросов:** См. примеры в `src/Sales/` (эталонный модуль)