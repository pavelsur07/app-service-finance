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

## После реализации

Добавил Facade, Facade-метод или Enum → **обнови `ARCHITECTURE.md`**.
Это источник правды для Projects-чатов. Без обновления — Projects будет выдумывать интерфейсы.

---

## Что стоит добавить (рекомендации)

### 1. Политика именования

Явные соглашения убирают разночтения на code review:

```
Классы:    PascalCase
Методы:    camelCase
Таблицы:   snake_case (например: company_document)
Enum кейсы: SCREAMING_SNAKE_CASE
Файлы конфигов: kebab-case
```

### 2. Обработка ошибок

```
- Домен бросает доменные исключения (src/{Module}/Exception/)
- Controller перехватывает через ExceptionListener → HTTP-ответ
- В Action: не глотать исключения молча — либо бросить, либо залогировать
- Logger инжектируется через LoggerInterface (Monolog PSR-3)
```

### 3. Валидация

```
- Валидация входных данных — через Symfony Validator (#[Assert\...]) в DTO
- Доменные инварианты — в конструкторе / методах Entity
- Нельзя валидировать бизнес-правила в Controller
```

### 4. Производительность и запросы

```
- N+1 запрос — запрещён; использовать JOIN или batch-загрузку
- Raw SQL в Query-классах только с явным перечислением колонок
- Индексы: создавать миграцией при добавлении нового FK-поля
- Пагинация обязательна для любого списочного endpoint (limit/offset или cursor)
```

### 5. Миграции

```
- Только через Doctrine Migrations (bin/console doctrine:migrations:generate)
- Нельзя менять уже применённую миграцию — создавать новую
- Деструктивные операции (DROP COLUMN, DROP TABLE) — отдельная миграция с комментарием
- Миграция не должна содержать бизнес-логику
```

### 6. API-контракт

```
- Изменение формата ответа — версионирование (/api/v2/...)
- Новые поля — добавлять backward-compatible
- Удаление полей — только через deprecation-период
- Формат ошибок: {"error": {"code": "...", "message": "..."}}
```

### 7. Авторизация

```
- Проверка прав — через Symfony Voter, не if ($user->isAdmin()) в Controller
- Новый ресурс → новый Voter в src/{Module}/Security/
- Никогда не доверять ID из тела запроса для определения владельца (только из токена/сессии)
```

### 8. Логирование

```
- Уровни: DEBUG (отладка), INFO (бизнес-события), WARNING (нештатное), ERROR (сбой)
- Логировать: старт/финиш async-задач, внешние HTTP-запросы, изменения критичных сущностей
- Не логировать: пароли, токены, персональные данные
```