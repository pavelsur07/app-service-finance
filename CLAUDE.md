# CLAUDE.md — VashFinDir

> Читается Claude Code автоматически. Workflow и полномочия — `AGENTS.md`,
> паттерны с кодом — `PATTERNS.md`, живые контракты — `ARCHITECTURE.md`.
> Здесь только backend-правила и то, чего нет в `AGENTS.md`.

## Карта файлов

| Файл | Назначение | Когда читать |
|---|---|---|
| `AGENTS.md` | Канонический workflow обоих агентов: полномочия, STOP, ревью, git, PROD | Всегда |
| `CLAUDE.md` | Backend PHP/Symfony правила | Всегда автоматически |
| `CLAUDE.frontend.md` | React / TypeScript / UI Kit | Фронтенд-задача |
| `PATTERNS.md` | Паттерны с примерами кода | Нужный раздел по задаче |
| `ARCHITECTURE.md` | Живые Facade, Enum, Entity | Перед написанием кода |
| `docs/workflow/external-review.md` | Внешнее ревью: скрипт, промпт, сбои | Перед вызовом второго агента |
| `docs/workflow/templates.md` | plan, checkpoint, Stage Report, handoff, STOP | Large-задача |
| `docs/workflow/stage-report.md` | Репозиторный self-review checklist Stage | Закрытие Stage |
| `docs/workflow/stage-report-frontend.md` | Фронтовый self-review checklist Stage | Закрытие фронтового Stage |
| `docs/workflow/git-housekeeping.md` | База PR, удаление веток | `gh pr create`, удаление ветки |
| `docs/workflow/data-migrations.md` | Миграции данных: замеры, слияния, сверка после прода | Миграция с данными |
| `docs/workflow/health-gates.md` | Мониторы и гейты: охват проверки = охват починки | Cron-проверка, верификатор |
| `docs/workflow/new-module.md` | Конфигурация нового модуля | Создание `src/{Module}` |
| `docs/maintenance/quality-gates.md` | PHPStan-baseline, cs-check, branch protection: устройство и история | Красный гейт, CI |
| `docs/maintenance/prod-access.md` | PROD-доступ: правила, wrappers, формы вызова | До обращения к production |

## Workflow и полномочия — `AGENTS.md`

- Иерархия: `Work item → Stage → Handoff → merge + deploy`.
- **Один вопрос Владельцу за задачу** — «merge and deploy #N» на handoff
  (`AGENTS.md` §3.2). Одобрение покрывает весь конвейер: Ready for review →
  merge → CI → авто-деплой → post-deploy acceptance → отчёт. Внутри конвейера
  ничего не переспрашивать. Отдельное одобрение только для действий вне
  конвейера (§3.3): ручные мутации прода, инфра, секреты, необратимые данные,
  remote-ветки, расширение прав.
- **PR с миграцией по этому одобрению не деплоится.** Миграции на проде —
  отдельный `workflow_dispatch`, а push-деплой не идёт, пока схема не
  актуальна, и гейт блокирует все последующие деплои тоже. Просить оба
  действия сразу — `AGENTS.md` §3.2.
- Всё до handoff pre-authorized (§3.1): правки в scope, локальные миграции,
  проверки, оба ревью, commit, push, Draft PR, Ready на handoff.
- STOP только по списку §3.4. HIGH-метка, легаси-зона, красные тесты, итерации
  фикса, отказ в правах — не STOP: доделать остальное и отдать точную команду.
- Приоритет источников — §2. `CLAUDE.md` ниже `AGENTS.md` и не может вводить
  ручной STOP, которого там нет.
- Внешнее ревью: когда и сколько раундов — §7.2; запуск —
  `site/bin/external-review.sh <base_commit>` (`docs/workflow/external-review.md`).
- Задача по React / TypeScript / Vite / UI Kit → прочитать `CLAUDE.frontend.md`;
  правила ниже к фронту не применяются, workflow — тот же.

## Перед кодом

1. `ARCHITECTURE.md` — актуальные Facade-методы, Enum, Entity. Существующие
   контракты не выдумывать.
2. Модуль вывести из соседнего кода; спросить только при материальной
   неоднозначности.
3. Новый Facade, метод, Enum или Entity, однозначно следующий из ТЗ, — создать
   в scope и обновить `ARCHITECTURE.md` в том же Stage.
4. Нужен паттерн — соответствующий раздел `PATTERNS.md`.

## Структура модуля

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
src/{Module}/Api/Request/         src/{Module}/Api/Response/
```

Legacy-зона `src/Entity/`, `src/Service/`, `src/Repository/`, `src/Controller/`:
новые файлы не создавать. Правка там — HIGH-LOCAL: только когда прямо требуется
задачей, с усиленными тестами и review; не STOP.

## Правила PHP

- Каждый файл начинается с `declare(strict_types=1)`; гейт
  `make site-cs-strict-types`. Ветку, созданную до миграции на strict_types,
  обновить из `master` до `make site-cs-fix`, иначе fixer впишет объявление в
  чужие файлы и включит там строгую проверку скаляров.
- Конструктор: `private readonly`, только constructor injection.

| Модификатор | Когда |
|---|---|
| `class` | Entity — Doctrine proxy наследуется, `final` это ломает |
| `final class` | Builder, Action, Controller, Facade, Repository, Query, Handler |
| `final readonly class` | DTO, Message, stateless-сервисы |
| `enum` | без `final` — enum implicitly final |

### Entity

- UUID v7 в конструкторе: `Uuid::uuid7()->toString()`; `#[ORM\Table(name: ...)]` всегда.
- `string $companyId` без setter'а, `Assert::uuid()`; не `#[ManyToOne] Company`.
- Ссылки на чужие модули — `string $counterpartyId`, не `#[ManyToOne]`.
- `DateTimeImmutable`, не `DateTime`. Паттерн — `PATTERNS.md` §11.

### IDOR — проверяется в ревью первым

- Каждый метод-запрос Repository принимает `string $companyId` либо ненулевой
  объектный `$company`. Компания внутри DTO ограничением не считается.
- Контроллер: `$this->activeCompanyService->getActiveCompany()` до обращения к
  данным. `$repo->find($id)` без компании — запрещено.
- Машинно: `site/tests/PHPStan/Rules/RepositoryCompanyScopeRule.php` ловит
  публичные `find*`/`get*`/`count*`/… без параметра компании в репозиториях
  сущностей, принадлежащих компании. Единственное санкционированное исключение —
  `@companyScopeExempt <причина>` в docblock.
- Правило — эвристика: не видит неиспользуемый параметр, владение глубже одного
  уровня и унаследованные `find()`/`findBy()`/`findAll()` в местах вызова.
  Зелёный гейт не отменяет ручную проверку. Паттерн — `PATTERNS.md` §14.

### Слои

| Слой | Правила | `PATTERNS.md` |
|---|---|---|
| Controller | один action, `__invoke`, `#[Route]`-атрибуты, только HTTP in/out | §2 |
| Action | `final`, `__invoke`, без `Request`/`Response`; `flush()` только здесь | §3 |
| Facade | единственная точка входа между модулями; чужие `Service/`, `Repository/`, `Infrastructure/`, `Application/` не импортировать, кроме `Application/DTO` — это типы контракта Facade; проверяется `site/tests/Architecture/ModuleBoundaryRules.php`; новый Facade или метод → `ARCHITECTURE.md` | §7 |
| Message / Handler | `readonly class` со scalar ID; routing в `config/packages/messenger.yaml`; ровно один транспорт: `async_sync` — внешние HTTP, `async_pipeline` — DB-heavy локальная обработка, `async_ads` — Ozon Performance polling до 10 мин; Handler без `Request`/`Session`/`Security` | §10 |
| Формы | `ChoiceType` с данными из Facade, не `EntityType` с чужой Entity | §8 |

Изменение `messenger.yaml` — HIGH-LOCAL: проверить routing, retry/failure,
совместимость сообщений, усиленный review; без STOP.

## Глобальные запреты

```
dump() / dd() / var_dump()                — нельзя в коммитах
new SomeService()                         — только constructor injection
flush() в Repository                      — только в Action
хардкод секретов / URL / API-ключей       — только через .env
бизнес-логика в Controller                — вынести в Action
бизнес-логика в Entity                    — только инварианты в конструкторе
import Service/Repository чужого модуля   — только через Facade
import Application/ чужого модуля         — только Application/DTO
#[ManyToOne] на Entity чужого модуля      — только string $entityId
EntityType с чужой Entity в формах        — только ChoiceType + Facade
SELECT * в raw SQL                        — явное перечисление колонок
циклические зависимости между модулями    — нельзя
getRepository() чужого модуля             — только через Facade
```

## Тесты — минимум

| Сделано | Написать в том же Stage |
|---|---|
| Новый Action | happy-path тест + 1 негативный |
| Новый Domain Policy | unit-тесты на все ветки |
| Новая Entity | Builder в `tests/Builders/{Module}/` |
| Исправление бага | регрессионный тест, красный на старом коде и зелёный на новом |
| Новый Facade-метод | functional-тест через вызывающий код или unit на Facade |

Work item без нужных тестов не завершён. Паттерны — `PATTERNS.md` §16, §17.

## Новый модуль

`src/{Module}` требует правок в `config/routes.yaml`,
`config/packages/doctrine.yaml`, `config/packages/twig.yaml` и при async
Messages — `messenger.yaml`. Готовые блоки — `docs/workflow/new-module.md`.

## Обработка ошибок

- Доменные исключения в `src/{Module}/Exception/`: `final class`, extends
  `\RuntimeException` или `AppException`, имя по причине
  (`DocumentNotFoundException`).
- Поток: Domain/Action бросает → ExceptionListener маппит в HTTP-статус и JSON
  → в Controller нет `try/catch`, кроме технических кейсов.
- Формат ответа для нового кода:

```json
{ "error": { "code": "document_not_found", "message": "Документ не найден" } }
```

`code` — стабильный snake_case-идентификатор, `message` — человекочитаемый,
HTTP-статус несёт семантику: 404 / 422 / 403 / 500.

```
try/catch с пустым телом          — глотать исключения нельзя
throw new \Exception('ошибка')    — только конкретные доменные классы
return null вместо исключения     — обязанная существовать сущность бросает
```

Паттерн — `PATTERNS.md` §13.

## Логирование

Sentry (GlitchTip) получает только `ERROR`+. Monolog — структурированные
INFO/DEBUG.

| Уровень | Когда |
|---|---|
| `ERROR` | Инцидент: неустранимый сбой, баг, повреждение данных — нужно будить человека |
| `WARNING` | Ожидаемо и обрабатывается само: retry, 429, таймаут, невалидный вход + skip, «данные не готовы» |
| `INFO` | Бизнес-события: старт/финиш async-задачи, отправка документа, импорт завершён |
| `DEBUG` | Только локально |

- Messenger: `warning` + `RecoverableMessageHandlingException` пока ретраим;
  `error` + `UnrecoverableMessageHandlingException` только на неустранимом.
- В циклах — один агрегированный `error` со счётчиком, не по записи.
- Обязательно: старт и финиш каждого MessageHandler с ID сообщения и
  `companyId`; внешние HTTP — метод, URL, статус, время, без тела; смена
  критичных статусов Entity.
- Запрещено: пароли, токены, ключи даже в DEBUG; ФИО и ИНН без явного
  требования; тело ответа внешних API.
- Мониторы и гейты с exit code — `docs/workflow/health-gates.md`. Примеры —
  `PATTERNS.md` §23.

## Производительность

- N+1 чинить в текущем Work item; в Stage self-review проверяется явно.
- Списки только через Pagerfanta: `?page=1&limit=50`, максимум 200, сверх →
  422; `OutOfRangeCurrentPageException` → 422 в ExceptionListener; Query-класс
  отдаёт `QueryBuilder`, не массив.
- Новое FK-поле в Entity → индекс в миграции; составной `(company_id, field)`,
  если фильтр всегда по компании.
- Batch больше 100 записей — `flush()` каждые N итераций. Query без `companyId`
  запрещён: IDOR и полный скан.

## Гейты качества

| Команда | Что |
|---|---|
| `make site-stan` | PHPStan level 8, baseline `site/phpstan-baseline.neon` |
| `make site-cs-check` | PHP CS Fixer `@Symfony` + risky |
| `make site-cs-strict-types` | только `declare_strict_types` |
| `make site-test-unit` / `make site-test` | unit / полный набор |

- Baseline только сокращать. Рост блокирует CI; осознанный рост — метка
  `[baseline-grow]` в заголовке PR. `make site-stan-baseline` начисто стирает
  ratchet — не использовать вместо починки.
- Красный baseline до задачи — не регресс и не индульгенция: проверять
  изменённые файлы точечно и записать факт с цифрой.
- На `master` обязательны три проверки: `🔬 Static analysis`,
  `🎨 PHP code style`, `🧪 Unit tests`. Имя job'а в `deploy.yml` — контракт с
  защитой ветки: переименовал — обнови список в том же PR.
- Устройство, история и почему матричный job нельзя сделать обязательным —
  `docs/maintenance/quality-gates.md`.

## Практики

- Сначала подтвердить дефект в данных, потом трогать код. Сообщение о проде
  описывает симптом; если данные дефект не подтверждают — сказать и остановиться.
- Замеры «до» снимать до изменения: счётчики, суммы денежных полей, инварианты.
  После прогона восстановить их нечем.
- Миграции с данными — `docs/workflow/data-migrations.md`. Необратимая миграция
  требует бэкапа и явного упоминания в запросе «merge and deploy».
- После деплоя миграции сверить эффект, счётчики «до», инварианты, висячие
  ссылки. Расхождение сначала объяснить, потом докладывать.

## Design System

Весь визуал через собственный UI Kit: правила и Money-форматы —
`CLAUDE.frontend.md`, референс — `site/ui-kit/storybook.html`, решения —
`site/ui-kit/decisions.md`.
