* положить в **root репозитория**
* подключать в **AI промтах**
* использовать в **Codex / Cursor / ChatGPT**

Файл учитывает ваши правила:

* модульная архитектура
* `companyId` как `string UUID`
* запрет `ActiveCompanyService` внутри Action
* CQRS (ORM write / DBAL read)
* атомарные PR

---

# AI_DEVELOPMENT_RULES.md

```md
# AI Development Rules

## Purpose

Этот документ описывает **обязательные правила**, которые должен соблюдать AI-ассистент при генерации кода для данного проекта.

Цель:

- предотвратить hallucinations
- сохранить архитектуру проекта
- обеспечить production-quality код

AI должен **строго соблюдать данные правила**.

Если задача требует нарушения правил — **AI должен остановиться и запросить уточнение**.

---

# 1. Project Context

Проект:

- Symfony SaaS application
- PHP 8+
- PostgreSQL
- Doctrine ORM
- модульная архитектура

Кодовая база **не является DDD**, но использует **строгую модульную структуру**.

---

# 2. Module Architecture

Весь код должен находиться **только внутри модулей**.

Разрешенная структура:

```

src/<ModuleName>/

```

Пример:

```

src/Cash
src/Deals
src/Catalog
src/Marketplace
src/Company
src/Shared

```

Запрещено создавать код в:

```

src/Service
src/Helper
src/Utils
src/Common

```

---

# 3. Module Structure

Каждый модуль может содержать:

```

Module
├ Entity
├ Repository
├ Application
│   ├ Command
│   ├ Action
├ Infrastructure
│   ├ Query
├ Controller

```

AI не должен изобретать новые слои.

---

# 4. Allowed Execution Flow

Разрешённый поток выполнения:

```

Controller
↓
Command
↓
Action
↓
Domain
↓
Repository

```

Controller **никогда не должен работать с Repository напрямую**.

---

# 5. CQRS Rules

Write операции:

```

Doctrine ORM
Repository

```

Read операции:

```

DBAL
Infrastructure/Query

```

AI не должен:

- использовать ORM для read-heavy операций
- использовать Repository для read-only списков

---

# 6. Company Context Rule

Контекст компании **всегда передается как scalar**.

```

companyId : string (UUID)

```

Пример:

```

CreateDealCommand
{
public string $companyId;
}

```

Запрещено передавать:

```

Company entity

```

Причина:

- сериализация в очередь
- worker compatibility
- предотвращение lazy loading

---

# 7. Active Company Access

Запрещено внутри Action:

```

ActiveCompanyService->getActiveCompany()

```

Причина:

- код может выполняться в Worker
- нет HTTP контекста

Контекст компании должен передаваться **через Command DTO**.

---

# 8. Repository Rules

Repository используется **только для persistence**.

Разрешено:

```

save()
remove()
find()

```

Запрещено:

```

complex reporting queries
analytics queries
aggregations

```

Для этого используется **Query слой**.

---

# 9. Query Layer

Все read-heavy операции должны быть реализованы через:

```

Infrastructure/Query

```

Использовать:

```

DBAL
Raw SQL

```

Причина:

- performance
- контроль SQL

---

# 10. Cross Module Dependencies

Модули должны быть **слабо связаны**.

AI должен избегать:

```

Module A → Repository Module B

```

Разрешено:

```

Facade
DTO
Application services

```

---

# 11. Database Rules

AI должен учитывать:

- PostgreSQL
- индексы для company_id
- pagination для списков

Каждый список должен иметь:

```

LIMIT
OFFSET
ORDER BY

```

---

# 12. Security Rules

AI должен проверять:

- SQL injection
- access control
- company isolation

Каждый запрос должен фильтровать:

```

company_id

```

---

# 13. Performance Rules

AI должен избегать:

- N+1 queries
- heavy ORM hydration
- отсутствующих индексов

Для списков:

```

pagination required

```

---

# 14. AI Code Generation Rules

AI должен:

- использовать существующие namespace
- использовать существующие классы
- не создавать новые архитектурные слои
- не угадывать код

Если информации недостаточно — **запросить файл**.

---

# 15. Atomic Development

Каждая задача должна быть разбита:

```

1 PR = 1 изменение

```

Пример:

```

PR1 Entity
PR2 Repository
PR3 Query
PR4 Controller

```

AI не должен генерировать большие изменения.

---

# 16. Output Requirements

При генерации кода AI должен вернуть:

1. полный код файлов
2. diff
3. commit message

---

# 17. AI Code Audit

После генерации кода AI должен провести аудит.

Проверить:

- архитектуру
- security
- performance
- соответствие правилам

---

# 18. If Rules Are Violated

Если задача требует нарушить архитектуру:

AI должен:

```

STOP
Explain conflict
Ask for clarification

```

---

# 19. Guiding Principle

AI должен:

- анализировать существующий код
- соблюдать архитектуру
- не изобретать новые решения

Цель — **production quality code**.
```

---

# Как использовать этот файл

Лучший способ:

Добавлять в начало промта:

```
Используй правила из AI_DEVELOPMENT_RULES.md
```
