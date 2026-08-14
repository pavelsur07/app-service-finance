# План приведения модуля Balance в соответствие правилам проекта

> Статус: аналитический план, модуль `App\Balance` не используется в продакшене.  
> База для проверки: `AGENTS.md`, `PATTERNS.md`, `ARCHITECTURE.md`, `CLAUDE.md`, `CLAUDE.frontend.md`.

---

## 1. Резюме

Модуль `Balance` (`site/src/Balance`) реализован в legacy-стиле: Entity хранят ссылку `Company $company`, бизнес-логика находится в Controller, финансовые суммы передаются как `float`, отсутствуют слои `Application/Action`, `Domain/Policy`, `Infrastructure/Query` и `Facade`. Это противоречит актуальным правилам проекта и создаёт риски IDOR, неподдерживаемой архитектуры и потери точности денежных расчётов.

Предлагается провести рефакторинг до применения модуля, поскольку модуль ещё не задействован и изменения не затронут рабочие данные.

---

## 2. Применимые правила проекта

| Правило | Источник | Суть |
|---|---|---|
| `companyId` — `string`, не `Company` Entity | `PATTERNS.md` §14, `ARCHITECTURE.md` | Изоляция по компании через UUID; `findByIdAndCompany($id, $companyId)` |
| Слои: Controller → Action → Domain → Infrastructure | `PATTERNS.md` §1-3 | Controller содержит только HTTP; flush только в Action |
| Entity: `createdAt`/`updatedAt`, guard-методы, `DomainException` | `PATTERNS.md` §11 | `DateTimeImmutable`, валидация инвариантов в Entity |
| Финансовые данные — decimal strings / Money VO | `PATTERNS.md` §24, примеры Cash/MarketplaceAds | Не использовать `float`/`int` для денег |
| Формы — scalar IDs, не EntityType | `PATTERNS.md` §8 | ChoiceType со скалярными id через Facade |
| DTO Command — `final readonly`, `fromRequest()` только в Filter | `PATTERNS.md` §12 | Не использовать Entity как DTO |
| Тесты: Unit Domain, Integration Action, Functional Controller | `PATTERNS.md` §16 | Регрессионные тесты обязательны |
| `declare(strict_types=1)` и `final class` | Имеющийся код новых модулей | Строгая типизация и запрет наследования |
| UUIDv7 для новых сущностей | MarketplaceAds, Inventory | `Ramsey\Uuid\Uuid::uuid7()` |
| Facade для межмодульного чтения | `PATTERNS.md` §7, §18 | Публичный API модуля — минимальный, read/command-методы |
| Frontend: нет inline styles | `CLAUDE.frontend.md` | Использовать CSS-классы / токены |

---

## 3. Найденные несоответствия (по значимости)

### P0 — Критичные (безопасность, архитектура, изоляция)

1. **`Company $company` в Entity вместо `string $companyId`.**
   - Файлы: `Entity/BalanceCategory.php`, `Entity/BalanceCategoryLink.php`.
   - Проблема: нарушает multi-tenancy паттерн, усложняет сериализацию, увеличивает риск случайной утечки данных между компаниями.
   - Правило: `PATTERNS.md` §14, `ARCHITECTURE.md` (Balance отмечен как `legacy`).

2. **Repository принимают `Company` Entity и используют `find($id)`.**
   - Файлы: `Repository/BalanceCategoryRepository.php`, `Repository/BalanceCategoryLinkRepository.php`.
   - Проблема: отсутствует обязательная фильтрация по `companyId` в методах поиска по ID; возможен IDOR.
   - Правило: `PATTERNS.md` §14.

3. **Бизнес-логика в Controller.**
   - Файл: `Controller/BalanceStructureController.php`.
   - Проблема: создание категорий, проверка максимальной вложенности, перемещение, удаление, связывание с источниками — всё внутри Controller. Нарушает эталонный поток Controller → Action → Domain.
   - Правило: `PATTERNS.md` §1-3.

### P1 — Высокие (финансовая корректность, качество кода)

4. **Финансовые суммы передаются как `float`.**
   - Файлы: `DTO/BalanceRowView.php`, `ReadModel/BalanceReport.php`, `Provider/CashTotalsProvider.php`, `Provider/FundsTotalsProvider.php`, `Service/BalanceBuilder.php`.
   - Проблема: потеря точности, некорректное округление, несоответствие правилам денежной арифметики проекта.
   - Правило: `PATTERNS.md` §24, `ARCHITECTURE.md` (денежная арифметика через BCMath / `Money`).

5. **Отсутствуют `createdAt`/`updatedAt` в Entity.**
   - Файлы: `Entity/BalanceCategory.php`, `Entity/BalanceCategoryLink.php`.
   - Правило: `PATTERNS.md` §11.

6. **Отсутствие `declare(strict_types=1)` и `final class` в большинстве файлов модуля.**
   - Все PHP-файлы модуля Balance не содержат `declare(strict_types=1)`. Классы Entity, Repository, Service, Controller не `final`.
   - Правило: общий кодстайл новых модулей (MarketplaceAds, Inventory, Ingestion).

7. **`BalanceEquationChecker` и `BalanceStructureValidator` — пустые заглушки.**
   - Файлы: `Service/Validation/BalanceEquationChecker.php`, `Service/Validation/BalanceStructureValidator.php`.
   - Проблема: контроль балансового уравнения `Активы = Обязательства + Капитал` и целостности дерева не реализован.

8. **Использование `Uuid::uuid4()` вместо `uuid7()`.**
   - Файлы: `Controller/BalanceStructureController.php`, `Service/BalanceStructureSeeder.php`.
   - Правило: новые модули используют UUIDv7.

### P2 — Средние (структура, тесты, интеграция)

9. **Отсутствует слой `Application/Action`.**
   - Файлы: `Service/BalanceStructureSeeder.php`, `Service/BalanceBuilder.php`.
   - Проблема: `BalanceStructureSeeder` смешивает сидирование и создание ссылок; `BalanceBuilder` смешивает построение отчёта с чтением данных. Нужно выделить `Application/CreateCategoryAction`, `Application/UpdateCategoryAction`, `Application/BuildBalanceReportAction` и т.д.

10. **Отсутствует слой `Infrastructure/Query` для отчёта.**
    - Файл: `Service/BalanceBuilder.php`.
    - Проблема: сложная read-модель строится через ORM-гидрацию и рекурсию в сервисе; по правилам сложные отчёты делаются через DBAL QueryBuilder.

11. **Отсутствует `Facade` модуля.**
    - Проблема: другие модули не могут безопасно читать структуру баланса.
    - Правило: `PATTERNS.md` §7, §18.

12. **Отсутствие тестов.**
    - Нет Unit-, Integration- и Functional-тестов для Balance.
    - Правило: `PATTERNS.md` §16, `AGENTS.md` (регрессионные тесты).

13. **Форма `BalanceCategoryFormType` привязывается к Entity и использует `ChoiceType` с Entity-объектами.**
    - Файл: `Form/BalanceCategoryFormType.php`.
    - Проблема: форма работает с Entity, родитель выбирается как объект; по правилам формы должны принимать scalar IDs.

14. **`BalanceBuilder` работает с `Company` Entity, а не `string $companyId`.**
    - Файл: `Service/BalanceBuilder.php`.
    - Проблема: тянет legacy-зависимость в ReadModel.

### P3 — Низкие (стилистика, UI)

15. **Inline styles в Twig-шаблонах.**
    - Файлы: `templates/balance/index.html.twig`, `templates/balance_structure/index.html.twig`.
    - Правило: `CLAUDE.frontend.md` (нет inline styles).

16. **Неконсистентные type hints: `?string $id` в Entity, но конструктор требует UUID.**
    - Файлы: `Entity/BalanceCategory.php`, `Entity/BalanceCategoryLink.php`.
    - Проблема: `getId(): ?string` после конструктора никогда не null; типизация может быть `string`.

17. **Отсутствие кастомных исключений.**
    - Проблема: в коде нет `Balance/Exception/`. Используются generic `\DomainException`.
    - Правило: `PATTERNS.md` §13.

---

## 4. План устранения (в порядке значимости)

### Этап 1. Архитектурный каркас и изоляция (P0)

1.1. Перевести `BalanceCategory` и `BalanceCategoryLink` на `string $companyId`.
- Убрать `#[ORM\ManyToOne]` на `Company`.
- Добавить `#[ORM\Column(type: 'guid')]` для `companyId`.
- Удалить `use App\Company\Entity\Company` из Entity.

1.2. Переписать Repository: методы принимают `string $companyId`.
- `findByIdAndCompany(string $id, string $companyId): ?BalanceCategory`
- `findRootByCompany(string $companyId): array`
- `findTreeByCompany(string $companyId): array`
- Убрать `Company` из параметров; фильтровать по `company_id`.

1.3. Ввести слой `Application/Action`.
- `CreateBalanceCategoryAction`
- `UpdateBalanceCategoryAction`
- `DeleteBalanceCategoryAction`
- `MoveBalanceCategoryAction`
- `LinkBalanceCategoryAction`
- `BuildBalanceReportAction`
- Перенести flush, валидацию и оркестрацию из Controller в Action.

1.4. Упростить Controller до HTTP-адаптеров.
- `BalanceStructureController`: один `__invoke` на маршрут или несколько методов, но без бизнес-логики.
- `BalanceController`: только десериализация запроса, вызов Action, рендер.

### Этап 2. Финансовая корректность и доменные инварианты (P1)

2.1. Заменить `float` на decimal strings / `Money` Value Object.
- `BalanceRowView::$amountsByCurrency`: `array<string,string>` (decimal).
- `BalanceReport::$totals`: `array<string,string>` (decimal).
- Провайдеры возвращают decimal strings; `CashTotalsProvider` конвертирует из `string` без потери точности.
- `FundsTotalsProvider` использует `Money::fromMinor(...)->toDecimalString()` вместо `convertMinorToDecimal(float)`.

2.2. Добавить `createdAt`/`updatedAt` в Entity.
- `\DateTimeImmutable`, автоматическое обновление `updatedAt` в guard-методах.

2.3. Реализовать `BalanceEquationChecker`.
- Проверка `Активы = Обязательства + Капитал` по валютам.
- Возврат списка ошибок; Action может логировать/отображать предупреждение.

2.4. Реализовать `BalanceStructureValidator`.
- Проверка отсутствия циклов в дереве.
- Проверка максимальной глубины (5 уровней).
- Проверка уникальности `companyId + code`.

2.5. Добавить кастомные исключения.
- `BalanceCategoryNotFoundException`
- `BalanceCategoryCycleException`
- `BalanceDepthExceededException`
- `BalanceEquationViolationException`

2.6. Добавить `declare(strict_types=1)` и `final class` для всех новых/изменённых файлов.

### Этап 3. ReadModel, Query и Facade (P2)

3.1. Вынести построение отчёта в `Infrastructure/Query/BalanceReportQuery`.
- QueryBuilder возвращает сырой массив/данные для построения `BalanceReport`.
- `BalanceBuilder` / `BuildBalanceReportAction` используют Query.

3.2. Создать `Balance\Facade\BalanceFacade`.
- Методы для чтения структуры баланса другими модулями (`getCategoriesForCompany`, `getReportForCompany`).
- Принимает `string $companyId`, возвращает DTO/массивы.

3.3. Перевести `BalanceValueProviderInterface` на `string $companyId`.
- `getTotalsForCompanyUpToDate(string $companyId, \DateTimeImmutable $date): array<string,string>`.
- Обновить `CashTotalsProvider` и `FundsTotalsProvider`.
- При необходимости добавить тонкую обёртку для вызова legacy-сервисов Cash, принимающих `Company` (через `getReference` или временный адаптер).

3.4. Переписать `BalanceCategoryFormType`.
- `data_class` — Command/DTO, не Entity.
- `parentId` — `ChoiceType` со scalar choices (`id => name`), полученными через Facade/Repository.

### Этап 4. Тесты и UI (P2-P3)

4.1. Добавить тестовые Builders.
- `tests/Builders/Balance/BalanceCategoryBuilder.php`
- `tests/Builders/Balance/BalanceCategoryLinkBuilder.php`

4.2. Unit-тесты.
- `BalanceEquationCheckerTest`
- `BalanceStructureValidatorTest`
- `BalanceCategoryTest` (guard-методы, level, cycle)

4.3. Integration-тесты.
- `CreateBalanceCategoryActionTest`
- `UpdateBalanceCategoryActionTest`
- `MoveBalanceCategoryActionTest`
- `DeleteBalanceCategoryActionTest`
- `BuildBalanceReportActionTest`

4.4. Functional-тесты.
- `BalanceStructureControllerTest`
- `BalanceControllerTest`

4.5. Исправить Twig-шаблоны.
- Убрать inline styles; заменить на CSS-классы/утилиты проекта.

### Этап 5. Миграции и завершение

5.1. Создать миграцию для перехода схемы.
- Убрать FK `balance_categories.company_id → companies` и `balance_category_links.company_id → companies`, если проект переходит на string-`companyId` без FK (как в MarketplaceAds/Inventory).
- Либо сохранить FK, но изменить тип/маппинг Entity на `string` — зависит от глобальной политики проекта.
- Добавить `created_at`/`updated_at` в обе таблицы.
- Обновить индексы: `uniq_balance_cat_company_code`, `uniq_balance_link` должны включать `company_id` как scalar column.

5.2. Обновить `ARCHITECTURE.md`.
- Изменить статус `Balance` с `legacy` на актуальный `string $companyId`.
- Добавить описание Facade и провайдеров значений.

5.3. Обновить `services.yaml`.
- Убедиться, что тегирование `app.balance.value_provider` сохранено.
- Зарегистрировать новые Action.

---

## 5. Риски

| Риск | Описание | Митигация |
|---|---|---|
| Модуль ещё не используется | Нет пользовательских данных для миграции | Провести рефакторинг до включения в UI/бизнес-процессы |
| Зависимость от legacy `Company` в Cash | `CashTotalsProvider`/`FundsTotalsProvider` используют Cash-сервисы, принимающие `Company` | Временный адаптер `CompanyRepository::getReference()` или договорённость о переводе Cash на `string $companyId` |
| Изменение публичных маршрутов | URL `/balance` и `/balance/structure` могут быть уже известны фронтенду | Сохранить маршруты; изменить только обработчики |
| Потеря точности при переходе с float | Существующие расчёты используют float | Перевести всё на decimal strings; добавить тесты на округление |

---

## 6. Целевая структура модуля после рефакторинга

```
site/src/Balance/
├── Controller/
│   ├── BalanceController.php
│   └── BalanceStructureController.php
├── Application/
│   ├── CreateBalanceCategoryAction.php
│   ├── UpdateBalanceCategoryAction.php
│   ├── DeleteBalanceCategoryAction.php
│   ├── MoveBalanceCategoryAction.php
│   ├── LinkBalanceCategoryAction.php
│   ├── SeedBalanceStructureAction.php
│   └── BuildBalanceReportAction.php
├── Domain/
│   ├── Policy/
│   │   ├── BalanceStructurePolicy.php
│   │   └── BalanceEquationPolicy.php
│   ├── Exception/
│   │   ├── BalanceCategoryNotFoundException.php
│   │   ├── BalanceCategoryCycleException.php
│   │   ├── BalanceDepthExceededException.php
│   │   └── BalanceEquationViolationException.php
│   └── ValueObject/
│       └── Money.php (или использовать Shared\Domain\ValueObject\Money)
├── Entity/
│   ├── BalanceCategory.php
│   └── BalanceCategoryLink.php
├── Repository/
│   ├── BalanceCategoryRepository.php
│   └── BalanceCategoryLinkRepository.php
├── Infrastructure/
│   └── Query/
│       └── BalanceReportQuery.php
├── Provider/
│   ├── BalanceValueProviderInterface.php
│   ├── BalanceValueProviderRegistry.php
│   ├── BalanceValueProviderTag.php
│   ├── CashTotalsProvider.php
│   └── FundsTotalsProvider.php
├── Facade/
│   └── BalanceFacade.php
├── Form/
│   └── BalanceCategoryFormType.php
├── Enum/
│   ├── BalanceCategoryType.php
│   └── BalanceLinkSourceType.php
├── DTO/
│   ├── BalanceRowView.php
│   ├── BalanceReportView.php
│   └── CreateBalanceCategoryCommand.php
└── ReadModel/
    └── BalanceReport.php
```

---

## 7. Чеклист завершения

- [ ] Все Entity используют `string $companyId` без `Company` Entity.
- [ ] Все Repository фильтруют по `companyId`.
- [ ] Controller не содержит бизнес-логики; flush только в Action.
- [ ] Финансовые суммы — decimal strings / `Money`, не `float`.
- [ ] `createdAt`/`updatedAt` присутствуют в Entity.
- [ ] `BalanceEquationChecker` и `BalanceStructureValidator` реализованы и покрыты тестами.
- [ ] Добавлены Unit/Integration/Functional тесты.
- [ ] Создан `BalanceFacade`.
- [ ] Twig-шаблоны без inline styles.
- [ ] Миграция обновляет схему безопасно (модуль не используется).
- [ ] `ARCHITECTURE.md` обновлён: Balance — не legacy.
