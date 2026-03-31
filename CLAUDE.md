# CLAUDE.md — VashFinDir

> Этот файл читается Claude Code автоматически при старте.
> Содержит правила и запреты. Паттерны с примерами кода → `PATTERNS.md`.

---

## Файлы проекта

| Файл | Назначение | Когда читать |
|---|---|---|
| `CLAUDE.md` | Правила, запреты, чеклисты (backend) | Всегда автоматически |
| `CLAUDE.frontend.md` | Правила React / TypeScript / Tabler | При любой frontend-задаче |
| `PATTERNS.md` | Паттерны с примерами кода (backend) | По задаче, нужный раздел |
| `ARCHITECTURE.md` | Живые данные: Facade, Enum, Entity | Перед написанием кода |

---

## Задача на фронтенд?

Если задача касается React, TypeScript, Vite, Tabler, хуков, компонентов, entrypoints —
пользователь укажет это явно в начале задачи одной из фраз:

```
Фронтенд задача. Читай CLAUDE.frontend.md
Задача на React. Читай CLAUDE.frontend.md
UI: читай CLAUDE.frontend.md
```

При получении такой фразы:
1. Прочитай `CLAUDE.frontend.md` полностью
2. Правила ниже в этом файле для этой задачи **не применяются** — они только для PHP/Symfony

---

## Перед написанием любого кода (backend)

1. Прочитай `ARCHITECTURE.md` — актуальные Facade-методы, Enum-значения, статус Entity
2. Уточни модуль если не указан явно
3. Используй **только** Facade и Enum из `ARCHITECTURE.md` — не выдумывай
4. Нет нужного Facade/метода — **спроси**, не создавай самостоятельно
5. Нужен паттерн реализации → читай соответствующий раздел `PATTERNS.md`

---

## Куда класть новый код

### ✅ Разрешено
```
src/{Module}/Controller/
src/{Module}/Controller/Api/
src/{Module}/Entity/
src/{Module}/Repository/
src/{Module}/Application/
src/{Module}/Application/Command/
src/{Module}/Application/DTO/
src/{Module}/Application/Processor/
src/{Module}/Application/Service/
src/{Module}/Application/Source/
src/{Module}/Domain/
src/{Module}/Domain/ValueObject/
src/{Module}/Domain/Service/
src/{Module}/Infrastructure/
src/{Module}/Infrastructure/Api/
src/{Module}/Infrastructure/Query/
src/{Module}/Infrastructure/Normalizer/
src/{Module}/DTO/
src/{Module}/Enum/
src/{Module}/Facade/
src/{Module}/Form/
src/{Module}/Message/
src/{Module}/MessageHandler/
src/{Module}/EventSubscriber/
src/{Module}/Exception/
tests/Builders/{Module}/
```

### ❌ Запрещено — legacy-зона, не создавать новые файлы
```
src/Entity/
src/Service/
src/Repository/
src/Controller/
```

---

## Обязательные правила

### Каждый PHP-файл
```php
<?php

declare(strict_types=1);
```
`final class` по умолчанию · `readonly class` для DTO и stateless-сервисов · constructor injection `private readonly`

**Исключение — Entity: только `class`, не `final class`.**
Doctrine генерирует proxy-класс наследованием от Entity.
`final` блокирует это → ошибка `"Cannot generate lazy ghost: class X is final"`.

Итого по модификаторам:
- `class` — Entity
- `final class` — Builder, Action, Policy, Controller, Facade, Repository, Query, Handler
- `final readonly class` — DTO, Message, stateless-сервисы

### Entity — новые модули
- UUID v7: `Uuid::uuid7()->toString()` — генерируется в **конструкторе Entity**
- `#[ORM\Table(name: '...')]` — явное имя таблицы **всегда**
- `string $companyId` вместо `#[ManyToOne] Company $company`
- `companyId` неизменяем (нет setter'а), валидируется через `Assert::uuid()`
- Ссылки на Entity других модулей: `string $counterpartyId`, не `#[ManyToOne]`
- `DateTimeImmutable` везде, не `DateTime`
- Паттерн полностью → `PATTERNS.md` раздел 11

### Безопасность — IDOR (критично)
- Каждый Repository-метод обязан принимать `string $companyId`
- В контроллере всегда: `$company = $this->activeCompanyService->getActiveCompany()`
- `$repo->find($id)` без company — **запрещено**, это IDOR-уязвимость
- Паттерн полностью → `PATTERNS.md` раздел 14

### Controller
- Один контроллер = один action = метод `__invoke`
- Маршруты через `#[Route]` атрибуты, не YAML
- Ноль бизнес-логики — только HTTP in/out
- Паттерн полностью → `PATTERNS.md` раздел 2

### Action
- `final class`, метод `__invoke`, без `Request`, без `Response`
- `flush()` — только в Action, не в Repository
- Паттерн полностью → `PATTERNS.md` раздел 3

### Facade
- Единственная точка входа между модулями
- Запрещено импортировать `Service/`, `Repository/`, `Application/`, `Infrastructure/` чужого модуля
- Паттерн полностью → `PATTERNS.md` раздел 7

### Message (Messenger)
- `readonly class` только с scalar ID — не Entity
- Новый Message → добавить routing в `config/packages/messenger.yaml`
- Handler: нет `Request`/`Session`/`Security` — CLI-контекст
- Паттерн полностью → `PATTERNS.md` раздел 10

### Формы с данными чужого модуля
- `ChoiceType` с данными из Facade — не `EntityType` с чужой Entity
- Паттерн полностью → `PATTERNS.md` раздел 8

---

## Глобальные запреты

```
dump() / dd() / var_dump()               — нельзя в коммитах
new SomeService()                        — только constructor injection
flush() в Repository                     — только в Action
хардкод секретов / URL / API-ключей     — только через .env
бизнес-логика в Controller               — вынести в Action
бизнес-логика в Entity                   — только инварианты в конструкторе
import Service/Repository из чужого модуля — только через Facade
ManyToOne на Entity чужого модуля        — только string $entityId
EntityType с чужой Entity в формах       — только ChoiceType + Facade
SELECT * в raw SQL                        — явное перечисление колонок
циклические зависимости между модулями   — нельзя
getRepository() чужого модуля            — только через Facade
```

---

## Тесты — минимальные требования перед merge

- Новый Action → минимум один happy-path тест
- Новый Domain Policy → unit-тесты на все ветки
- Новая Entity → Builder в `tests/Builders/{Module}/`
- Исправление бага → регрессионный тест

Паттерны тестов и Builder → `PATTERNS.md` разделы 16, 17

---

## Новый модуль — чеклист конфигурации

```yaml
# 1. config/routes.yaml
newmodule_controllers:
    resource:
        path: ../src/NewModule/Controller/
        namespace: App\NewModule\Controller
    type: attribute

# 2. config/packages/doctrine.yaml
NewModule:
    type: attribute
    is_bundle: false
    dir: '%kernel.project_dir%/src/NewModule/Entity'
    prefix: 'App\NewModule\Entity'
    alias: NewModule

# 3. config/packages/messenger.yaml (если есть async Messages)
App\NewModule\Message\SomeMessage: async

# 4. config/packages/twig.yaml (если есть шаблоны)
paths:
    '%kernel.project_dir%/templates/newmodule': NewModule
```

---

## После реализации — обязательно

Добавил новый Facade, Facade-метод или Enum → **обнови `ARCHITECTURE.md`**.
Это источник правды для Projects-чатов. Без обновления — Projects будет выдумывать интерфейсы.