# CLAUDE.md — VashFinDir

> Этот файл читается Claude Code автоматически при старте.
> Паттерны с примерами кода → `PATTERNS.md`.
> **Режим работы — автономный.** Claude выполняет задачу этапами, каждый этап завершает self-review + Stage Report. Высокорисковые этапы — обязательная остановка для ревью Владельцем.

## Карта файлов

| Файл | Назначение | Когда читать |
|---|---|---|
| `CLAUDE.md` | Правила, запреты, **автономный workflow** (backend PHP/Symfony) | Всегда автоматически |
| `CLAUDE.frontend.md` | Правила React / TypeScript / Tabler | При фронтенд-задаче |
| `PATTERNS.md` | Паттерны с примерами кода | По задаче, нужный раздел |
| `ARCHITECTURE.md` | Живые данные: Facade, Enum, Entity | Перед написанием кода |

---

## 🤖 Автономный режим — workflow

### Источник задачи

Каждая задача начинается со **спецификации**:
- либо `docs/tasks/<id>/TASK.md` в ветке,
- либо чёткий бриф от Владельца в чате (scope + ограничения + acceptance).

Нет спецификации → **STOP**, попросить её. Догадки и расширение scope автономно — запрещены.

### Фазы работы над задачей

```
Phase 0 (Plan)  →  Phase 1..N (Execute by Stages)  →  Phase Final (Handoff)
                          ↑
              после каждого этапа: self-review + Stage Report
              если этап high-risk → 🛑 STOP, ждать Владельца
              если self-review red → fix или 🛑 STOP, не идти дальше
```

### Phase 0 — Plan (всегда первая, всегда заканчивается ревью)

1. Прочитать: `CLAUDE.md`, релевантные разделы `PATTERNS.md`, `ARCHITECTURE.md`, спецификацию задачи.
2. Найти 2–3 похожих модуля в репозитории, опереться на их паттерны.
3. Составить план:
   - список этапов (Stage 1..N) с целью каждого,
   - карта изменений: какие Entity / Repository / Action / Facade / Controller / Message / миграции,
   - риск-классификация каждого этапа (см. таблицу ниже),
   - какие тесты потребуются,
   - какие записи в `ARCHITECTURE.md` нужно обновить.
4. Сохранить план в `docs/tasks/<id>/plan.md`.
5. 🛑 **STOP. Дождаться одобрения плана Владельцем.** Без подтверждения — не писать код.

### Классификация этапов по риску

| Риск | Примеры этапов | Поведение после self-review |
|---|---|---|
| 🟢 **LOW** | Чистый внутренний рефакторинг внутри одного Action/Service; добавление unit-тестов; обновление документации; косметика | Self-review зелёный → **продолжать автономно** к следующему этапу |
| 🟡 **MEDIUM** | Новая Entity без миграции схемы; новый Action; новый Facade-метод; новый Message + Handler | Self-review зелёный → **продолжать автономно**, Stage Report сохранить в `docs/tasks/<id>/stages/` |
| 🔴 **HIGH** | Миграция БД; изменение API-контракта; новый публичный эндпоинт; изменение auth/RBAC/Voter; новая composer/npm зависимость; работа в legacy-зоне; удаление чего-либо; новый Messenger-транспорт | Self-review зелёный → 🛑 **STOP, обязательное ревью Владельцем перед следующим этапом** |

Если затрудняешься классифицировать — считай **HIGH** и остановись.

### Обязательные точки STOP (никогда не продолжать без Владельца)

- Перед любой миграцией БД (генерация + перед запуском)
- Перед изменением публичного API (URL, поля ответа, статус-коды, типы)
- Перед добавлением зависимости (`composer require`, `npm i`)
- Перед удалением: файла, класса, метода, поля БД, эндпоинта
- Перед изменениями в `src/Entity/`, `src/Service/`, `src/Repository/`, `src/Controller/` (legacy-зона)
- Перед изменением `config/packages/messenger.yaml` (роутинг, транспорты)
- Перед изменением auth, Security, Voter
- Если self-review нашёл проблему, которую не удалось починить за 1 итерацию
- Если задача требует выйти за изначальный scope
- Если нет нужного Facade/Enum в `ARCHITECTURE.md` (не выдумывай — спроси)
- Финальный handoff (всегда STOP)

### Self-review checklist (выполнять в конце КАЖДОГО этапа)

Запускать в строгом порядке. Если хоть один пункт красный — этап не закрыт.

**Соответствие правилам:**
- [ ] Изменения строго в рамках цели этапа (нет out-of-scope правок)
- [ ] Структура файлов — раздел «Структура файлов» соблюдён
- [ ] Naming, модификаторы классов (`final` / `final readonly` / `class`) — раздел «Правила PHP» соблюдён
- [ ] Использованы **только** Facade и Enum из `ARCHITECTURE.md`
- [ ] Не задет ни один пункт раздела «Глобальные запреты»

**Безопасность:**
- [ ] Каждый Repository-метод принимает `string $companyId`
- [ ] В контроллерах есть `getActiveCompany()` перед обращением к данным
- [ ] Нет `$repo->find($id)` без companyId (IDOR-проверка)
- [ ] Нет логирования паролей / токенов / PII

**Качество кода:**
- [ ] `make stan` — чисто на изменённом коде
- [ ] `make cs` — чисто
- [ ] Нет `dump()` / `dd()` / `var_dump()`
- [ ] Нет N+1 (проверено через Profiler / `doctrine.debug` при ручном smoke-тесте)
- [ ] На списочных эндпоинтах — Pagerfanta с лимитом ≤ 200
- [ ] Новые FK-поля имеют индексы в миграции

**Тесты:**
- [ ] Минимум по таблице «Тесты — минимум перед закрытием этапа» написан
- [ ] `make test` — зелёный
- [ ] Тесты не «приглажены» под код — проверяют поведение

**Документация:**
- [ ] Добавил Facade / Facade-метод / Enum / Entity → `ARCHITECTURE.md` обновлён
- [ ] Изменения публичного API → OpenAPI / README модуля обновлены

**Stage Report:**
- [ ] Создан Stage Report по шаблону ниже, сохранён в `docs/tasks/<id>/stages/stage-<N>.md`
- [ ] Коммит сделан с Conventional Commits префиксом, сообщение отражает цель этапа

### Формат Stage Report (заполняется в конце каждого этапа)

```markdown
## Stage <N>: <название> — DONE

**Риск:** 🟢 LOW | 🟡 MEDIUM | 🔴 HIGH
**Следующее действие:** continue autonomously | 🛑 STOP, ждать Владельца

### Что сделано
- ...

### Затронутые файлы
- `src/.../X.php` — new
- `src/.../Y.php` — modified
- `migrations/Version...php` — new

### Self-review
- [x] Scope compliance
- [x] Patterns / naming
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR)
- [x] PHPStan / CS-Fixer / tests — green
- [x] ARCHITECTURE.md updated (или N/A)

### Команды для проверки
- `make test -- --filter <TestName>`
- `make stan`

### Риски / на что обратить внимание ревьюеру
- ...

### Открытые вопросы
- ... (если нет — «нет»)
```

### Phase Final — Handoff (всегда STOP)

В конце последнего этапа:
1. Прогнать полный набор: `make test && make stan && make cs`.
2. Сверить построчно все «Глобальные запреты» и ограничения из спецификации.
3. Заполнить `docs/tasks/<id>/handoff.md`: суммарный отчёт по всем этапам + список миграций + список изменённых публичных контрактов + риски + follow-ups.
4. 🛑 **STOP. Final Owner review.**

### Запрещено в автономном режиме

```
самовольно расширять scope                              — STOP и спросить
коммитить незакрытый этап                               — self-review red == этап не закрыт
пропускать self-review «потому что очевидно»            — checklist обязателен
пропускать STOP на high-risk этапе «ради скорости»     — Владелец сам решит, что пропустить
переписывать чужие модули по дороге («заодно»)         — отдельная задача
делать миграцию без отдельного STOP на ревью SQL        — обязательная остановка
merge в основную ветку                                  — никогда, только PR
force-push в shared-ветки                               — никогда
запуск миграций на staging/prod                         — никогда
```

---

## Фронтенд-задача?

Если задача касается React / TypeScript / Vite / Tabler — пользователь укажет:

```
Фронтенд задача. Читай CLAUDE.frontend.md
```

→ Прочитай `CLAUDE.frontend.md` полностью. Правила ниже **не применяются** (только для PHP/Symfony).

**Автономный workflow (фазы, классификация рисков, self-review, Stage Report, STOP-точки) — применяется и к фронтенду тоже.** Специфика этапов фронта — в `CLAUDE.frontend.md`.

---

## До написания любого backend-кода

1. Убедись, что Phase 0 (Plan) одобрена Владельцем
2. Прочитай `ARCHITECTURE.md` — актуальные Facade-методы, Enum-значения, статус Entity
3. Уточни модуль, если не указан явно
4. Используй **только** Facade и Enum из `ARCHITECTURE.md` — не выдумывай
5. Нет нужного Facade/метода → **спроси**, не создавай самостоятельно
6. Нужен паттерн → читай соответствующий раздел `PATTERNS.md`

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

Любая правка в legacy-зоне — 🔴 HIGH risk, обязательный STOP перед изменением.

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

В self-review этапа этот пункт проверяется первым — IDOR в проде = инцидент.

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

Новый Facade или Facade-метод → добавить в `ARCHITECTURE.md` **в том же этапе**, не откладывать.

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
- Изменение `messenger.yaml` — всегда 🔴 HIGH risk, STOP перед изменением
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
хардкод секретов / URL / API-ключей       — только через .env
бизнес-логика в Controller                — вынести в Action
бизнес-логика в Entity                    — только инварианты в конструкторе
import Service/Repository чужого модуля   — только через Facade
#[ManyToOne] на Entity чужого модуля      — только string $entityId
EntityType с чужой Entity в формах        — только ChoiceType + Facade
SELECT * в raw SQL                        — явное перечисление колонок
циклические зависимости между модулями    — нельзя
getRepository() чужого модуля             — только через Facade
расширение scope задачи в автономе        — STOP и спросить
закрытие этапа с красным self-review      — нельзя, fix или STOP
merge / force-push                        — никогда автономно
```

---

## Тесты — минимум перед закрытием этапа

| Что сделал на этапе | Что написать в этом же этапе |
|---|---|
| Новый Action | happy-path тест + 1 негативный |
| Новый Domain Policy | unit-тесты на все ветки |
| Новая Entity | Builder в `tests/Builders/{Module}/` |
| Исправление бага | регрессионный тест, который красный на старом коде и зелёный на новом |
| Новый Facade-метод | functional-тест через вызывающий код или unit на Facade |

Этап без необходимых тестов = этап **не закрыт**, не переходить дальше.

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

Изменения в `config/packages/messenger.yaml` — 🔴 HIGH risk, STOP перед PR.

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
тело HTTP-ответа внешних API      — только ID/статус, не весь payload
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

Обнаружил N+1 → исправь в рамках того же этапа. В self-review проверяется явно.

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

## Закрытие этапа

В конце каждого этапа — строго по порядку:

1. Прогнать `make stan && make cs && make test` (или таргетированно для этапа).
2. Пройти **Self-review checklist** (раздел «Автономный режим»). Любой красный пункт — этап не закрыт.
3. Сделать коммит: Conventional Commits, сообщение отражает цель этапа.
4. Сохранить **Stage Report** в `docs/tasks/<id>/stages/stage-<N>.md`.
5. Решить по риск-классу:
   - 🟢 LOW / 🟡 MEDIUM → продолжать к следующему этапу автономно.
   - 🔴 HIGH → 🛑 STOP, ждать Владельца.
6. Добавил Facade / Facade-метод / Enum / новую Entity → **обнови `ARCHITECTURE.md` в этом же этапе**. Это источник правды для Projects-чатов. Без обновления — Projects будет выдумывать интерфейсы.

## Закрытие задачи (Phase Final)

1. Прогнать полный набор: `make test && make stan && make cs`.
2. Сверить построчно «Глобальные запреты» и ограничения из спецификации.
3. Собрать `docs/tasks/<id>/handoff.md`:
   - summary всех этапов,
   - список миграций (up/down, деструктивные операции),
   - список изменённых публичных контрактов,
   - риски,
   - follow-ups, которые сознательно вынесены за scope.
4. 🛑 **STOP. Final Owner review.** Merge — только после одобрения Владельцем.

## Design System

This project uses a custom design system. Everything visual goes through it.

**Visual reference:** `site/ui-kit/storybook.html` — open in browser to
see all components, tokens, and Money formats.

**Rules document:** `site/ui-kit/decisions.md` — read first, it's compact.

**Source audit:** `site/ui-kit/design-audit.md` — original analysis,
historical reference.

## Hard Rules

1. **Tokens only.** Use CSS variables from `storybook.html` `:root`.
   No raw hex, no out-of-scale font sizes, no arbitrary spacings.

2. **Existing components only.** Button, Input, Money, Badge, StatusPill,
   Avatar, Toggle, Table, KPI card, Card, Dropdown, Tabs, Drawer, Modal,
   Empty state, Direction indicator, Sidebar, EntityPicker, TreePicker, Tags.

   Full list with classes in `decisions.md`.

3. **Money rules are sacred.** Minus = U+2212, thin space = U+2009 between
   digit groups, ₽ as suffix, tabular-nums, color only for deltas (not
   balances). 12 canonical formats in `decisions.md` → Money rules.

4. **Icons inherit currentColor** inside components. Color is applied to
   the whole menu item by semantic role (default/primary/danger), not to
   icons individually. No emoji icons.

5. **No new components without permission.** If a pattern isn't covered:
    - First, propose adapting an existing component
    - If impossible, STOP and ask before adding to ui-kit
    - Never invent ad-hoc components in screen files

6. **Universal pickers.** For flat entity selection (contractor / project /
   company / account) — `EntityPicker`. For hierarchical (category / OPiU
   article / tagged projects) — `TreePicker`. Don't create one-off pickers.

## Where to put things

- New screens → `site/screens/<name>.html`, one screen per file
- Each screen imports tokens (copy `:root` block from storybook.html, or
  reference storybook tokens in comments)
- New UI Kit additions → `site/ui-kit/storybook.html` + update `decisions.md`
    + bump version in `README.md`

## Versioning

Current UI Kit version: v1.1
- v1.0 — initial UI Kit (Foundations, Money, base components)
- v1.1 — added Sidebar, EntityPicker, TreePicker, Tags

When updating UI Kit:
1. Update `storybook.html`
2. Regenerate `decisions.md`
3. Update README.md changelog
4. Commit with message `ui-kit: vX.X — what changed`
5. Tag: `git tag ui-kit-vX.X`