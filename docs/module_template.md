# Module Development Standard (v2) — Symfony

**Цель:** Добавлять новую функциональность **только через изолированные модули**, без расползания legacy/shared и без “зоопарка” API/логики.

---

## 0. Базовые принципы

1. **Модуль = граница ответственности**, а не просто папка.
2. **React — острова**, основной UI — Twig + Tabler.
3. **Контроллеры тонкие**, use-case в Application.
4. **Бизнес-правила живут в Domain**, инфраструктура отдельно.
5. **Никаких массовых рефакторов** вне задачи.

---

## 1. Где нельзя писать код

Запрещено добавлять новый код в:

* `src/Service`
* `src/Controller`
* `src/Entity`
* `src/Repository`
* `templates/_partials`

Legacy/shared не расширяем.

---

## 2. Где живёт модуль

Весь код модуля — строго в:

* `src/<ModuleName>/...`

UI-шаблоны:

* `templates/<module_name>/...`
* общие элементы (разрешено): `templates/partials/...`

---

## 3. Слои внутри модуля и структура папок

Обязательная структура внутри `src/<ModuleName>/`:

```text
src/<ModuleName>/
├── Application/        # [ОБЯЗАТЕЛЬНО] Оркестрация (Use-cases, Actions, Handlers)
├── Controller/         # [ОБЯЗАТЕЛЬНО] Точки входа HTTP (Web UI, API)
├── Domain/             # [ОБЯЗАТЕЛЬНО] Чистая бизнес-логика (Правила, Политики)
├── Infrastructure/     # [ОБЯЗАТЕЛЬНО] Внешний мир (БД, API, Очереди)
├── Entity/             # [ОБЯЗАТЕЛЬНО] Сущности и маппинг Doctrine
├── Api/                # [Опционально] Контракты Request/Response для JSON API
├── DTO/                # [Опционально] Внутренние структуры данных
├── Enum/               # [Опционально] Перечисления (Статусы, Типы)
├── Facade/             # [Опционально] Публичные сервисы для вызова из ДРУГИХ модулей
└── Form/               # [Опционально] Классы Symfony Forms для Web UI

```

**Правила слоев:**

* **Controller:** Только принимает Request, мапит в DTO, вызывает Application, отдает Response. Без бизнес-логики и DQL.
* **Application:** Классы одного действия (Action). Координируют доменные правила и инфраструктуру (БД, шину сообщений).
* **Domain:** Чистый PHP. Policy, Validator, Calculator. Не знает про Symfony, Doctrine, HTTP.
* **Infrastructure:** Doctrine Repositories (DQL), Query (чистый DBAL для сложных выборок), Clients (внешние API). Никаких бизнес-правил.
* **Facade:** Единственный разрешенный способ для других модулей получить данные из этого модуля.

---

## 4. Правило вызовов (поток)

Поток выполнения строго однонаправленный:
`Controller → Application → (Domain + Infrastructure)`

* Контроллер **не содержит** бизнес-логики и запросов к БД.
* Domain **не знает** про Symfony/Doctrine/HTTP.
* Infrastructure **не содержит** бизнес-правил.

---

## 5. Интеграция между модулями

**Запрещено:**

* Использовать `Repository` другого модуля напрямую.
* Строить `QueryBuilder` на чужих таблицах “изнутри” модуля.

**Разрешено:**

* Вызывать публичные сервисы другого модуля через его фасад: `src/<OtherModule>/Facade/*` (или `Public/*`).

---

## 6. Entity и зависимости (важно)

* `Entity` и Doctrine mapping находятся строго в `src/<Module>/Entity/*`.
* Связи с core-сущностями допустимы только с явно разрешенными: `Company`, `User`.
* Доменные сущности других модулей **не тянуть** в логику (общаться только DTO через фасады).

---

## 7. Роутинг (разделение зон)

Строго через атрибуты Symfony (Attributes):

* Web UI: `/<module_name>/...`
* API: `/api/<module_name>/...`
* Backoffice: `/backoffice/...` (отдельная зона/ACL)

---

## 8. Безопасность и мульти-тенантность

* Все операции выполняются **в контексте активной компании**.
* Доступы/права проверяет backend (Role/Policy/Lock-period).
* Фронт **никогда** не является источником истины для прав.

---

## 9. API контракт (если модуль отдаёт JSON)

* Обязательно соблюдать: `docs/api/CONTRACT.md` (единые правила money/date/errors).
* **Запрещено** возвращать Doctrine Entity в JSON.
* Использование Request/Response DTO обязательно (папка `Api/`).

---

## 10. Логирование и аудит

* Изменения финансовых данных и статусов документов должны попадать в audit log по принятому в проекте механизму.
* Логика аудита инкапсулируется в `Application` или `Infrastructure`, но **не в контроллере**.

---

## 11. Тесты (минимальный DoD)

Добавлять тесты в:

* `tests/Unit/<ModuleName>/...`
* `tests/Integration/<ModuleName>/...`

Минимум для нового use-case:

* 1 Unit test на policy/validator/калькулятор (доменную логику).
* 1 Integration test на репозиторий (если есть сложные выборки/сохранения).

---

## 12. Миграции / фикстуры

Если добавлены новые Entity/поля:

* Создать миграцию Doctrine.
* Миграции должны применяться без ручных шагов (автоматически).

---

## 13. Что запрещено

* Массовые правки вне модуля.
* Перенос файлов из других модулей “по пути”.
* Изменение существующего API/контрактов без отдельной задачи.
* Добавление “общих утилит” в shared/legacy ради удобства.

---

## 14. Definition of Done (DoD) для новой фичи в модуле

* `composer test:smoke` проходит.
* Нет новых файлов в legacy/shared директориях.
* Контроллеры тонкие, use-case в Application.
* Бизнес-правила вынесены в Domain.
* Repos/интеграции — в Infrastructure.
* Если есть Entity — создана миграция.
* Если API — соблюден `docs/api/CONTRACT.md`.

---

## 15. Эталонный пример кода (Controller & Application Action)

### Пример: Controller (Тонкий HTTP-слой)

Отвечает только за прием, валидацию и передачу данных в Action.

```php
namespace App\Sales\Controller;

use App\Sales\Api\Request\CreateOrderRequest;
use App\Sales\Application\CreateOrderAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

class CreateOrderController extends AbstractController
{
    public function __construct(
        private readonly CreateOrderAction $createOrderAction
    ) {}

    #[Route('/api/sales/orders', name: 'api_sales_order_create', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] CreateOrderRequest $request
    ): JsonResponse {
        // Контроллер не знает про БД. Вся оркестрация делегируется в Action.
        $orderId = ($this->createOrderAction)($request);

        return $this->json(['id' => $orderId], 201);
    }
}

```

### Пример: Application Action (Слой оркестрации / Use-Case)

Один класс — одна бизнес-транзакция. Инкапсулирует вызовы фабрик, БД и шины событий.

```php
namespace App\Sales\Application;

use App\Sales\Api\Request\CreateOrderRequest;
use App\Sales\Domain\OrderPolicy;
use App\Sales\Entity\Order;
use App\Sales\Infrastructure\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class CreateOrderAction
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderPolicy $orderPolicy,
        private readonly MessageBusInterface $messageBus
    ) {}

    public function __invoke(CreateOrderRequest $request): int
    {
        // 1. Проверка доменных правил (Domain)
        if (!$this->orderPolicy->canCreateOrder($request->amount)) {
            throw new \DomainException('Order amount exceeds the allowed limit.');
        }

        // 2. Создание Entity
        $order = new Order(
            $request->customerId,
            $request->amount,
            $request->currency
        );

        // 3. Сохранение (Infrastructure)
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        // 4. Отправка фонового события (Application / Infrastructure)
        // $this->messageBus->dispatch(new OrderCreatedMessage($order->getId()));

        return $order->getId();
    }
}

```

---