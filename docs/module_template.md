# Module Development Standard (v2.1) — Symfony

**Цель:** Добавлять новую функциональность только через изолированные модули, с чётким разделением слоёв, высокой производительностью чтения и безопасной работой в HTTP + Worker/CLI окружении.

---

# 0. Базовые принципы

1. **Модуль = граница ответственности**, а не просто папка.
2. **React — острова**, основной UI — Twig + Tabler.
3. **Контроллеры тонкие**, write — через Application, read — через Query.
4. **Бизнес-правила живут в Domain**, инфраструктура отдельно.
5. **Никаких массовых рефакторов вне задачи.**
6. **Контекст компании передаётся явно.**
7. **Application слой не зависит от HTTP/Session.**
8. **Код должен корректно работать в Worker/CLI.**

---

# 1. Где нельзя писать код

Запрещено добавлять новый код в:

* `src/Service`
* `src/Controller` (кроме модуля)
* `src/Entity`
* `src/Repository`
* `templates/_partials`

Legacy/shared не расширяем.

---

# 2. Где живёт модуль

Весь код модуля:

```
src/<ModuleName>/
```

UI-шаблоны:

```
templates/<module_name>/
```

Разрешённые общие шаблоны:

```
templates/partials/
```

---

# 3. Структура модуля

```
src/<ModuleName>/
├── Application/
├── Controller/
├── Domain/
├── Infrastructure/
│   ├── Repository/
│   ├── Query/
│   └── Clients/
├── Entity/
├── Api/
├── DTO/
├── Enum/
├── Facade/
└── Form/
```

---

# 4. Поток данных (CQRS-light)

### WRITE

```
Controller → Command → Application Action → (Domain + Repository)
```

### READ

```
Controller → Infrastructure/Query → DTO/array
```

Контроллер не содержит бизнес-логики.
Domain не знает про Symfony.
Application не знает про HTTP/Session.

---

# 5. Интеграция между модулями

Запрещено:

* Использовать Repository/Query другого модуля напрямую
* Строить SQL на чужих таблицах

Разрешено:

* Только через `Facade` другого модуля

---

# 6. Entity и зависимости

* Entity строго внутри модуля
* Разрешённые core-сущности: `Company`, `User`
* Нельзя тянуть Entity другого модуля в доменную логику
* Межмодульная интеграция — через DTO и Facade

---

# 7. Роутинг

* Web UI: `/<module_name>/...`
* API: `/api/<module_name>/...`
* Backoffice: `/backoffice/...`

---

# 8. Безопасность и мульти-тенантность

* Все операции выполняются в контексте `company_id`
* Backend проверяет роли, lock-period, политики
* Фронт не источник истины

---

# 9. Company Context — ОБЯЗАТЕЛЬНОЕ ПРАВИЛО

## 9.1 Единственный способ получить активную компанию

Используется только:

```php
App\Shared\Service\ActiveCompanyService
$company = $companyService->getActiveCompany();
```

Разрешено ТОЛЬКО в:

* `src/<Module>/Controller/*`

Запрещено в:

* Application
* MessageHandler
* Worker
* CLI Command
* Domain
* Infrastructure

---

## 9.2 Запрещено в Application

Нельзя писать:

```php
$this->activeCompanyService->getActiveCompany();
```

Почему:

* В worker/CLI нет HTTP-сессии
* Код становится невалидным вне web-запроса
* Ломается асинхронная обработка

---

## 9.3 Контекст компании передаётся в Command

Контроллер:

1. Получает компанию через ActiveCompanyService
2. Создаёт `<Name>Command`
3. Передаёт в Application

---

## 9.4 КРИТИЧНО: companyId хранится как scalar (string UUID)

### В Command/Message запрещено:

```php
public Company $company;
```

### Разрешено только:

```php
public string $companyId;
```

Причины:

* Безопасная сериализация в очередь
* Нет lazy-loading в worker
* Нет ORM зависимостей
* Нет утечек persistence context
* Упрощается тестирование

---

## 9.5 actorUserId также scalar

```php
public string $actorUserId;
```

Никогда не передаём User entity в Command/Message.

---

# 10. Работа с БД — Fast Read

1. Запрещено `findAll()` для списков
2. Запрещено гидрировать Entity ради 2–3 полей
3. Используем DBAL + select конкретных колонок
4. Никакого N+1

---

# 11. API контракт

* Нельзя возвращать Doctrine Entity
* Только DTO или массивы
* Соблюдать единый формат money/date/errors

---

# 12. Аудит

* Финансовые изменения логируются
* Аудит не в контроллере
* Реализуется в Application или Infrastructure

---

# 13. Тесты

Минимум:

* 1 Unit test на Domain
* 1 Integration test на Query или сохранение

---

# 14. Запрещено

* Массовые правки вне модуля
* Перенос чужих файлов
* Логика в Controller
* Использование ActiveCompanyService вне Controller
* Передача Entity в Command/Message

---

# 15. Definition of Done

* [ ] Контроллер тонкий
* [ ] Write через Application
* [ ] Read через Query
* [ ] companyId передан через Command
* [ ] В Command companyId scalar string
* [ ] Нет ActiveCompanyService в Application
* [ ] Нет Entity в Message/Command
* [ ] Query слой без ORM гидрации
* [ ] Smoke tests проходят

---

# 16. Эталонные примеры

---

## 16.1 Command

```php
namespace App\Sales\Application\Command;

final class CreateOrderCommand
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $actorUserId,
        public readonly int $customerId,
        public readonly int $amount,
    ) {}
}
```

---

## 16.2 Controller (единственное место ActiveCompanyService)

```php
namespace App\Sales\Controller;

use App\Sales\Application\Command\CreateOrderCommand;
use App\Sales\Application\CreateOrderAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

final class CreateOrderController extends AbstractController
{
    public function __construct(
        private readonly CreateOrderAction $action,
        private readonly ActiveCompanyService $companyService
    ) {}

    #[Route('/api/sales/orders', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $company = $this->companyService->getActiveCompany();
        $user = $this->getUser();

        $command = new CreateOrderCommand(
            companyId: (string) $company->getId(),
            actorUserId: (string) $user->getId(),
            customerId: 123,
            amount: 10000
        );

        $orderId = ($this->action)($command);

        return $this->json(['id' => $orderId], 201);
    }
}
```

---

## 16.3 Application Action

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

## 16.4 Worker-safe Message

```php
final class RecalculateOrdersMessage
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $actorUserId
    ) {}
}
```

---

# Итог

Теперь стандарт:

* Безопасен для worker
* Предсказуем
* Без скрытого HTTP-контекста
* Без ORM утечек в очередь
* Чётко разделён по слоям

---
Подумать
??????????????????
Если хочешь, могу следующим шагом сделать отдельный **"Company Context Contract (v1)"** — короткий документ-надстройку, который стандартизирует:

* как называть поля
* где валидировать
* как логировать
* как тестировать
* как обрабатывать cross-company попытки доступа

Это уже уровень production-hardening.
