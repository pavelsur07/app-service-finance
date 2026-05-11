# CLAUDE.md — VashFinDir

> Этот файл читается Claude Code автоматически при старте.
> Паттерны с примерами кода → `PATTERNS.md`.

## Карта файлов

| Файл | Назначение | Когда читать |
|---|---|---|
| `CLAUDE.md` | Правила и запреты (backend PHP/Symfony) | Всегда автоматически |
| `CLAUDE.frontend.md` | Правила React / TypeScript / Tabler | При фронтенд-задаче |
| `PATTERNS.md` | Паттерны с примерами кода | По задаче, нужный раздел |
| `ARCHITECTURE.md` | Живые данные: Facade, Enum, Entity | Перед написанием кода |

---

## Фронтенд-задача?

Если задача касается React / TypeScript / Vite / Tabler — пользователь укажет:

```
Фронтенд задача. Читай CLAUDE.frontend.md
```

→ Прочитай `CLAUDE.frontend.md` полностью. Правила ниже **не применяются** (только для PHP/Symfony).

---

## До написания любого backend-кода

1. Прочитай `ARCHITECTURE.md` — актуальные Facade-методы, Enum-значения, статус Entity
2. Уточни модуль, если не указан явно
3. Используй **только** Facade и Enum из `ARCHITECTURE.md` — не выдумывай
4. Нет нужного Facade/метода → **спроси**, не создавай самостоятельно
5. Нужен паттерн → читай соответствующий раздел `PATTERNS.md`

---

## Структура файлов

### ✅ Разрешено

```
src/{Module}/Controller/          src/{Module}/Application/
src/{Module}/Controller/Api/      src/{Module}/Application/Command/
src/{Module}/Entity/              src/{Module}/Application/DTO/
src/{Module}/Repository/          src/{Module}/Application/Processor/
src/{Module}/Facade/              src/{Module}/Application/Service/
src/{Module}/Enum/                src/{Module}/Application/Source/
src/{Module}/Form/                src/{Module}/Domain/
src/{Module}/DTO/                 src/{Module}/Domain/ValueObject/
src/{Module}/Message/             src/{Module}/Domain/Service/
src/{Module}/MessageHandler/      src/{Module}/Infrastructure/
src/{Module}/EventSubscriber/     src/{Module}/Infrastructure/Api/
src/{Module}/Exception/           src/{Module}/Infrastructure/Query/
tests/Builders/{Module}/          src/{Module}/Infrastructure/Normalizer/
```

### ❌ Запрещено — legacy-зона, не создавать новые файлы

```
src/Entity/   src/Service/   src/Repository/   src/Controller/
```

---

## Правила PHP

### Каждый файл

```php
<?php

declare(strict_types=1);
```

**Модификаторы классов:**

| Модификатор | Когда |
|---|---|
| `class` | Entity (Doctrine генерирует proxy наследованием — `final` ломает это) |
| `final class` | Builder, Action, Controller, Facade, Repository, Query, Handler |
| `final readonly class` | DTO, Message, stateless-сервисы |
| `enum` | без `final` — PHP enum implicitly final, `final enum` — синтаксическая ошибка |

Конструктор: `private readonly`, только constructor injection.

---

### Entity — новые модули

- UUID v7: `Uuid::uuid7()->toString()` — в конструкторе Entity
- `#[ORM\Table(name: '...')]` — явное имя таблицы всегда
- `string $companyId` вместо `#[ManyToOne] Company $company`
- `companyId` неизменяем (нет setter'а), валидируется через `Assert::uuid()`
- Ссылки на Entity других модулей: `string $counterpartyId`, не `#[ManyToOne]`
- `DateTimeImmutable` везде, не `DateTime`
- Паттерн → `PATTERNS.md` §11

---

### Безопасность — IDOR (критично)

- Каждый Repository-метод **обязан** принимать `string $companyId`
- В контроллере всегда: `$company = $this->activeCompanyService->getActiveCompany()`
- `$repo->find($id)` без companyId — **запрещено** (IDOR-уязвимость)
- Паттерн → `PATTERNS.md` §14

---

### Controller

- Один контроллер = один action = метод `__invoke`
- Маршруты через `#[Route]` атрибуты, не YAML
- Ноль бизнес-логики — только HTTP in/out
- Паттерн → `PATTERNS.md` §2

---

### Action

- `final class`, метод `__invoke`, без `Request`, без `Response`
- `flush()` — только в Action, не в Repository
- Паттерн → `PATTERNS.md` §3

---

### Facade

- Единственная точка входа между модулями
- Запрещено импортировать `Service/`, `Repository/`, `Application/`, `Infrastructure/` чужого модуля
- Паттерн → `PATTERNS.md` §7

---

### Message (Messenger)

- `readonly class` только со scalar ID — не Entity
- Новый Message → добавить routing в `config/packages/messenger.yaml`
- **Транспорт — ровно один:**

| Транспорт | Когда |
|---|---|
| `async_sync` | Внешние HTTP-запросы (marketplace/банк API, email) |
| `async_pipeline` | Локальная обработка данных, DB-heavy (импорты, analytics recalc) |
| `async_ads` | Ozon Performance polling (handler может висеть до 10 мин) |

- Handler: нет `Request`/`Session`/`Security` — CLI-контекст
- Паттерн → `PATTERNS.md` §10

---

### Формы

- `ChoiceType` с данными из Facade — не `EntityType` с чужой Entity
- Паттерн → `PATTERNS.md` §8

---

## Глобальные запреты

```
dump() / dd() / var_dump()                — нельзя в коммитах
new SomeService()                         — только constructor injection
flush() в Repository                      — только в Action
хардкод секретов / URL / API-ключей      — только через .env
бизнес-логика в Controller                — вынести в Action
бизнес-логика в Entity                    — только инварианты в конструкторе
import Service/Repository чужого модуля   — только через Facade
#[ManyToOne] на Entity чужого модуля      — только string $entityId
EntityType с чужой Entity в формах        — только ChoiceType + Facade
SELECT * в raw SQL                         — явное перечисление колонок
циклические зависимости между модулями    — нельзя
getRepository() чужого модуля             — только через Facade
```

---

## Тесты — минимум перед merge

| Что сделал | Что написать |
|---|---|
| Новый Action | happy-path тест |
| Новый Domain Policy | unit-тесты на все ветки |
| Новая Entity | Builder в `tests/Builders/{Module}/` |
| Исправление бага | регрессионный тест |

Паттерны → `PATTERNS.md` §16, §17

---

## Новый модуль — конфигурация

```yaml
# config/routes.yaml
newmodule_controllers:
    resource:
        path: ../src/NewModule/Controller/
        namespace: App\NewModule\Controller
    type: attribute

# config/packages/doctrine.yaml
NewModule:
    type: attribute
    is_bundle: false
    dir: '%kernel.project_dir%/src/NewModule/Entity'
    prefix: 'App\NewModule\Entity'
    alias: NewModule

# config/packages/messenger.yaml (если есть async Messages)
App\NewModule\Message\SomeMessage: async_pipeline

# config/packages/twig.yaml (если есть шаблоны)
paths:
    '%kernel.project_dir%/templates/newmodule': NewModule
```

---

## Обработка ошибок

### Где живут исключения

- Доменные исключения → `src/{Module}/Exception/`
- Каждое исключение — `final class`, extends `\RuntimeException` или базовый `AppException`
- Имя отражает причину: `DocumentNotFoundException`, `InsufficientBalanceException`

### Поток исключений

```
Domain / Action бросает исключение
    → ExceptionListener ловит (src/EventSubscriber/ или src/Shared/)
    → Маппит в HTTP-статус + JSON-ответ
    → Controller не содержит try/catch (кроме технических кейсов)
```

### Стандарт формата ошибок (вводим сейчас — новый код только так)

```json
{
  "error": {
    "code": "document_not_found",
    "message": "Документ не найден"
  }
}
```

- `code` — snake_case-идентификатор, стабильный (фронт и интеграции на него завязываются)
- `message` — человекочитаемый текст (можно показывать пользователю)
- HTTP-статус несёт семантику: 404 / 422 / 403 / 500 — не всё в 200

### Запрещено

```
try/catch с пустым телом          — глотать исключения молча нельзя
throw new \Exception('ошибка')    — только конкретные доменные классы
return null вместо исключения     — если сущность обязана существовать, бросить исключение
```

---

## Логирование

Стек: **Sentry** для ошибок. Monolog — только для структурированных INFO/DEBUG-событий локально.

### Уровни

| Уровень | Когда |
|---|---|
| `ERROR` | Необработанное исключение, сбой внешнего сервиса — Sentry отправляет алерт |
| `WARNING` | Нештатное, но ожидаемое (retry, таймаут API, невалидный входящий вебхук) |
| `INFO` | Бизнес-события: старт/финиш async-задачи, отправка документа, импорт завершён |
| `DEBUG` | Только для локальной отладки, не должен попадать в прод |

### Что логировать обязательно

```
- Старт и финиш каждого MessageHandler (с ID сообщения и companyId)
- Внешние HTTP-запросы: метод, URL, HTTP-статус ответа, время (без тела)
- Изменение критичных статусов Entity (смена статуса документа, закрытие периода)
```

### Что запрещено логировать

```
пароли, токены, API-ключи         — даже в DEBUG
персональные данные (ФИО, ИНН)    — только если явно требуется и задокументировано
тело HTTP-ответа внешних API       — только ID/статус, не весь payload
```

### Как инжектировать

```php
use Psr\Log\LoggerInterface;

public function __construct(
    private readonly LoggerInterface $logger,
) {}
```

Sentry подхватывает ERROR автоматически через Monolog-handler — отдельно бросать в Sentry не нужно.

---

## Производительность

### N+1 — запрещено

```php
// ❌ N+1: запрос в цикле
foreach ($documents as $document) {
    $counterparty = $this->counterpartyRepo->find($document->getCounterpartyId());
}

// ✅ Загрузить всё одним запросом через Query-класс с JOIN или WHERE IN
$counterparties = $this->counterpartyQuery->findByIds($counterpartyIds, $companyId);
```

Обнаружил N+1 → исправь, прежде чем отдавать на ревью. Используй Symfony Profiler / `doctrine.debug` локально.

### Пагинация — обязательна для списков

Используем **Pagerfanta** (`pagerfanta/doctrine-dbal-adapter` или `doctrine-orm-adapter`).

```php
// Query-класс возвращает QueryBuilder, не массив:
public function createByCompanyQueryBuilder(string $companyId): QueryBuilder
{
    return $this->connection->createQueryBuilder()
        ->select('d.id, d.number, d.status')
        ->from('document', 'd')
        ->where('d.company_id = :companyId')
        ->setParameter('companyId', $companyId);
}
```

```php
// В Controller:
$qb = $this->documentQuery->createByCompanyQueryBuilder($company->getId());

$adapter    = new DoctrineDbalAdapter($qb, /* countQueryModifier */);
$pagerfanta = new Pagerfanta($adapter);
$pagerfanta->setMaxPerPage(min((int) $request->query->get('limit', 50), 200));
$pagerfanta->setCurrentPage(max(1, (int) $request->query->get('page', 1)));

return $this->json([
    'items'    => iterator_to_array($pagerfanta->getCurrentPageResults()),
    'total'    => $pagerfanta->getNbResults(),
    'pages'    => $pagerfanta->getNbPages(),
    'page'     => $pagerfanta->getCurrentPage(),
    'per_page' => $pagerfanta->getMaxPerPage(),
]);
```

Параметры запроса: `?page=1&limit=50`

- `limit` — максимум 200, дефолт 50; значения сверх лимита → 422
- `setCurrentPage()` бросает `OutOfRangeCurrentPageException` — ловить в ExceptionListener → 422
- Списочный endpoint без пагинации — **запрещено** (риск OOM на больших данных)
- Query-класс отдаёт `QueryBuilder`, не `array` — иначе Pagerfanta не сможет сделать COUNT

### Индексы при новых FK-полях

Добавил `string $counterpartyId` в Entity → в миграции обязательно:

```sql
CREATE INDEX idx_document_counterparty_id ON document (counterparty_id);
-- Составной индекс если фильтруем всегда по companyId + полю:
CREATE INDEX idx_document_company_counterparty ON document (company_id, counterparty_id);
```

### Прочее

```
batch-операции (>100 записей)  — flush() каждые N итераций, не в конце цикла
raw SQL с SELECT *             — запрещено (явное перечисление колонок)
Query без companyId            — запрещено (IDOR + полный скан таблицы)
```

---

## После реализации

Добавил Facade, Facade-метод или Enum → **обнови `ARCHITECTURE.md`**.
Это источник правды для Projects-чатов. Без обновления — Projects будет выдумывать интерфейсы.