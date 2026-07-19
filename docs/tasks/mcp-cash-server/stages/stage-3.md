## Stage 3: Пять инструментов MVP — DONE

**Риск:** 🟠 HIGH-LOCAL (запись в финансовые данные через новый канал)
**Следующее действие:** continue autonomously → Stage 4

### Что сделано

`CashFacade` расширен пятью методами — это единственная дверь, через которую
модуль Mcp касается данных ДДС:

- `listTransactions()` — Pagerfanta, `per_page` ≤ 200, по умолчанию 50
- `listCashflowCategories()` — плоское дерево с `level`/`parentId`
- `listAutoRules()` — правила вместе с условиями
- `upsertCashflowCategory()` / `upsertAutoRule()` — через Action из Stage 1

`CompanyFacade::findCounterpartyByIdAndCompany()` — контрагент строго в рамках
компании, чтобы Cash не тянул `CounterpartyRepository` чужого модуля напрямую.

Инструменты: `cash_transactions_list`, `cash_categories_tree`, `cash_categories_upsert`,
`cash_autorules_list`, `cash_autorule_upsert`.

### Решения

- **`null` во входном DTO = «не менять».** Позволяет менять одно поле, не пересылая
  весь объект. Обратная сторона: очистить описание или вынести статью в корень
  через MCP нельзя — это делается в UI.
- **`conditions` заменяют набор условий целиком.** Частичное редактирование условий
  потребовало бы их идентификаторов в протоколе; замена набора проще и предсказуемее.
- **Проект и ЦФО автоправила через MCP не задаются.** Это единственное место с
  проверкой допустимости пары; отдав его UI, инструмент остаётся простым. При
  редактировании существующие значения передаются в Action как текущие и не теряются.
- **Статья ОПиУ, `operationType` и `allowPlDocument` в MVP не редактируются** —
  связка с ОПиУ отдельная тема, потребовала бы ещё одного фасада (Finance).
- **Ответ инструмента — pretty-JSON.** Модели одинаково удобно читать JSON и таблицу,
  а JSON не требует отдельного форматирования и однозначно парсится.

### Затронутые файлы

- `site/src/Cash/Facade/CashFacade.php` — modified (5 методов + сериализация)
- `site/src/Cash/Application/DTO/{CashflowCategoryInput,AutoRuleInput,AutoRuleConditionInput}.php` — new
- `site/src/Company/Facade/CompanyFacade.php` — modified (+1 метод, новая зависимость добавлена последним аргументом)
- `site/src/Mcp/Application/Tool/*.php` — new (5 инструментов + трейты `JsonToolOutput`, `EnumArgument`)
- `site/tests/Integration/Cash/CashFacadeMcpSurfaceTest.php` — new
- `site/tests/Unit/Admin/Application/CreateAccountActionTest.php` — modified (новый аргумент `CompanyFacade`)
- `site/tests/Integration/Ingestion/Command/OzonAccrualRollingRefreshCommandTest.php` — modified (см. ниже)
- `ARCHITECTURE.md` — modified

Миграций нет.

### Чужой упавший тест

`OzonAccrualRollingRefreshCommandTest` читал «родительскую» задачу запросом
`WHERE company_id = :c AND parent_job_id IS NULL` без фильтра по `kind`. Под условие
подходят две строки — BACKFILL `ACCRUAL_BY_DAY` и INCREMENTAL `ACCRUAL_TYPES`, — а
какая вернётся, зависит от физического порядка в куче. Новые интеграционные тесты
сдвинули этот порядок, и скрытая недетерминированность стала видимой.

Проверено: на master в изоляции тест зелёный, полный integration-прогон на master
тоже зелёный (739 тестов). Добавлен фильтр `AND kind = 'BACKFILL'` — ровно то, что
утверждает соседняя строка теста. Правка в тесте, продакшен-код Ingestion не тронут.

### Self-review

- [x] Scope compliance — инструменты и контракт фасада, ничего сверх
- [x] Patterns — Mcp обращается только к `CashFacade`, репозиториев чужого модуля не импортирует
- [x] Security — `companyId` во всех методах фасада; статьи, автоправила, контрагенты и счета ищутся строго в рамках компании; неизвестная компания отклоняется
- [x] Пагинация — `per_page` ≤ 200 на списке транзакций; `rawData` в выдачу не попадает
- [x] CS-Fixer по изменённым файлам — чисто
- [x] `--testsuite unit` — 1539 зелёных, `--testsuite integration` — 744 зелёных
- [x] `bin/console lint:container` — OK
- [x] ARCHITECTURE.md обновлён (CashFacade, CompanyFacade, changelog 1.53)

### Живая проверка

Локальная БД, миграции и фикстуры, реальная компания:

| Проверка | Результат |
|---|---|
| `cash_categories_tree` | 5 статей с уровнями и родителями |
| `cash_transactions_list` с `limit=2` | items, total, pages, page, per_page |
| `cash_category_upsert` (создание) | новая статья, `saved: true` |
| `cash_autorule_upsert` с условием | новое правило, видно в `cash_autorules_list` |
| `flowKind: "ЧТО-ТО"` | `isError` + перечисление допустимых значений |
| Условие `INN CONTAINS "12"` | `isError` + «ИНН должен содержать 10 или 12 цифр» |
| Пишущий инструмент без `--allow-write` | JSON-RPC `-32602` с объяснением |

### Риски / на что обратить внимание ревьюеру

- Это первый канал записи в ДДС мимо HTTP-формы. Инварианты держат Action из Stage 1
  и валидация сущностей; форма как слой защиты здесь отсутствует по построению.
- `CompanyFacade` получил новую зависимость. Аргумент добавлен последним, чтобы не
  ломать позиционные вызовы; один тест всё равно обновлён.
- Отключение автоправила (`isActive: false`) необратимо — это инвариант сущности,
  вынесен в описание инструмента.

### Открытые вопросы

- нет
