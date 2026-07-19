# MCP-сервер ДДС — handoff

Локальный MCP-сервер поверх `site-php-cli`: чтение транзакций ДДС, CRUD статей ДДС
и автоправил для одной компании. Без внешних доступов — ни портов, ни HTTP.

Инструкция по подключению — `docs/mcp.md`.

## Этапы

| Stage | Что | Риск |
|---|---|---|
| 1 | Write-логика статей и автоправил вынесена из контроллеров в Action | 🟠 HIGH-LOCAL |
| 2 | Ядро MCP: команда, JSON-RPC цикл, реестр инструментов | 🟡 MEDIUM |
| 3 | Пять инструментов поверх `CashFacade` | 🟠 HIGH-LOCAL |
| 4 | Тесты протокола на живом процессе, `docs/mcp.md` | 🟢 LOW |

Отчёты — `docs/tasks/mcp-cash-server/stages/stage-{1..4}.md`.

## Изменённые публичные контракты

`CashFacade` (`ARCHITECTURE.md` обновлён, changelog 1.53):

```php
listTransactions(string $companyId, array $filters = [], int $page = 1, int $perPage = 50): array
listCashflowCategories(string $companyId): array
listAutoRules(string $companyId): array
upsertCashflowCategory(string $companyId, CashflowCategoryInput $input): string
upsertAutoRule(string $companyId, AutoRuleInput $input, ?string $actorUserId = null): string
```

`CompanyFacade::findCounterpartyByIdAndCompany(string $counterpartyId, string $companyId): ?Counterparty`
— новая зависимость `CounterpartyRepository` добавлена **последним** аргументом
конструктора, чтобы не ломать позиционные вызовы.

Новая команда: `app:mcp:serve --company-id=<uuid> [--allow-write]`.

Новые Action, используемые и HTTP-контроллерами, и MCP:
`SaveCashflowCategoryAction`, `SaveCashTransactionAutoRuleAction`.

## Миграции

Нет. Схема БД не менялась ни на одном этапе.

## Модель безопасности

- `companyId` приходит аргументом процесса, а не параметром инструмента — у модели
  нет поля, куда подставить чужую компанию.
- Все методы фасада принимают `companyId`; статьи, автоправила, контрагенты и счета
  ищутся строго в рамках компании. Неизвестная компания отклоняется.
- Без `--allow-write` пишущие инструменты не видны в `tools/list` и отклоняются в
  `tools/call`.
- Инварианты записи держат Action и валидация сущностей: HTTP-формы в этом канале нет.

## Проверки на момент сдачи

| Набор | Результат |
|---|---|
| `--testsuite unit` | 1539 зелёных |
| `--testsuite integration` | 744 зелёных |
| `--testsuite functional` (MCP) | 5 зелёных |
| `php bin/phpunit` целиком | 2523 теста, 1 pre-existing падение |
| `bin/console lint:container` | OK |
| CS-Fixer по изменённым файлам | чисто |

Живая проверка инструментов проводилась на локальной БД с миграциями и фикстурами —
таблица сценариев в `stages/stage-3.md`.

### Pre-existing падение

`Functional/Finance/SoftDeleteExclusionRegressionTest:167` падает и на master без
изменений этой задачи (проверено через `git stash`). К MCP отношения не имеет.

### Правка в чужом тесте

`Integration/Ingestion/Command/OzonAccrualRollingRefreshCommandTest` читал родительскую
задачу запросом без фильтра по `kind` — под условие подходили две строки, и какая
вернётся, зависело от физического порядка в куче. Новые интеграционные тесты сдвинули
порядок и обнажили это. Добавлен фильтр `kind = 'BACKFILL'` — ровно то, что утверждает
соседняя строка теста. Продакшен-код Ingestion не тронут.

## Сознательно вне scope

- **Применение автоправил к транзакциям** (`applyRule`). Массовая перекатегоризация
  проводок необратима, поэтому такого инструмента нет намеренно.
- **Удаление** статей и автоправил; создание и редактирование транзакций.
- **Проект и ЦФО автоправила** — единственное место с проверкой допустимости пары,
  оставлено за UI. При редактировании через MCP существующие значения сохраняются.
- **Статья ОПиУ, `operationType`, `allowPlDocument`** у статьи ДДС — потребовали бы
  ещё одного фасада (Finance).
- **Очистка полей через MCP**: `null` во входе означает «не менять», поэтому сбросить
  описание или вынести статью в корень можно только в UI.
- HTTP-транспорт, OAuth, MCP resources и prompts.

## Follow-ups

- `CashflowCategory::$name` не имеет `NotBlank` на уровне сущности — обязательность
  держат форма и JSON-схема инструмента. Добавление констрейнта потребует проверки
  существующих строк.
- Ограничения на размер ответа инструмента нет, кроме пагинации транзакций:
  дерево статей и список автоправил отдаются целиком. Для компаний с сотнями
  автоправил это стоит померить.
- `.mcp.json` в репозиторий не добавлен намеренно — он содержал бы конкретный
  `--company-id`.
