## Stage 3: Переключение всех читателей и контрактов — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** yes — Release Gate со сверкой отчётов на проде до перехода к Stage 4
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 Release Gate — Владелец сверяет отчёт ДДС и ведомость на проде

### Scope Stage

- Stage base commit: `9320fe4a606a16d050526209c8ccac5880b383a6`
- Work items completed: `3.1`–`3.10`
- Колонка `cash_transaction.cashflow_category_id` остаётся только на пути записи
  (dual-write) до отдельной contract-задачи.

### Что сделано

Все читатели категории транзакции переведены на строки `cash_transaction_split`:

| Потребитель | Решение |
|---|---|
| `ReportCashflowOpsCheckController` | категории склеены lateral-подзапросом в одну ячейку |
| `ReportTransactionsStatementController` | D3: одна строка на split, фильтр по строкам |
| `CashTransactionRepository` | D4: выгрузка, фильтр, суммы по виду деятельности, оттоки по категориям, capex |
| `CashTransactionAutoRuleCandidateQuery` | кандидатом может быть только транзакция ровно с одной категорией |
| `CashFacade::serializeTransaction` | контракт расширен массивом `splits` |
| `CashTransactionAutoRulePrefiller` | предзаполнение только при однозначной категории |
| `CashflowAgent` | агрегация по строкам |
| `PaymentPlanMatcher` | D2: мультиразбивка исключена из автомэтча |
| `CashTransactionToDocumentService`, `CreateDocumentFromTransactionAction` | D1: единственная строка |
| `CashflowReportBuilder` | агрегация по категории и сумме строки |
| Шаблоны списка, карточки и удалённых | категории из строк |

### Ключевые решения

1. **Транзакция без категории строк не имеет.** Это легальное зеркало пустой колонки,
   поэтому везде `LEFT JOIN`, а где такие транзакции обязаны учитываться —
   `COALESCE(split.amount, t.amount)`. С `INNER JOIN` они молча исчезли бы из ведомости,
   выгрузки и дашборда.
2. **`CashFacade` не выбирает «первую попавшуюся» категорию.** Поле `category` заполняется
   только при одной строке, иначе `null`; правда лежит в новом массиве `splits`. Вернуть
   одну категорию из нескольких значило бы соврать интеграции, которая на неё завязана.
3. **Правило «однозначная категория» живёт в одном месте** — `CashTransaction::getSingleSplitCategory()`.
   До внутреннего review та же логика была в четырёх местах в трёх вариантах написания.
4. **Кандидат в автоправило обязан иметь ровно одну категорию.** Правило проставляет одну,
   и мультикатегорийная транзакция чистым образцом быть не может.

### Затронутые файлы

- `src/Cash/Entity/Transaction/CashTransaction.php` — доменный метод `getSingleSplitCategory()`
- `src/Cash/Repository/Transaction/CashTransactionRepository.php` — экспорт, фильтр, 4 агрегата
- `src/Cash/Facade/CashFacade.php` — публичный контракт
- `src/Cash/Infrastructure/Query/CashTransactionAutoRuleCandidateQuery.php`
- `src/Cash/Application/Service/CashTransactionAutoRulePrefiller.php`
- `src/Cash/Application/CreateDocumentFromTransactionAction.php`
- `src/Cash/Service/PaymentPlan/PaymentPlanMatcher.php`
- `src/Cash/Service/Transaction/CashTransactionToDocumentService.php`
- `src/Finance/Controller/ReportCashflowOpsCheckController.php`
- `src/Finance/Controller/ReportTransactionsStatementController.php`
- `src/Report/Cashflow/CashflowReportBuilder.php`
- `src/Ai/Service/Agent/CashflowAgent.php`
- `templates/transaction/index.html.twig`, `show.html.twig`, `cash/transaction/deleted_index.html.twig`
- `tests/Builders/Cash/CashTransactionBuilder.php` — зеркальная строка, как в бою
- 2 теста, конструирующих транзакцию напрямую
- `ARCHITECTURE.md`

### Две поломки, найденные тестами

1. **`toIterable()` несовместим с join коллекции.** Doctrine запрещает итерировать такой
   запрос, а одна строка выгрузки на строку разбивки без join не получается. Экспорт
   переведён на `getArrayResult()`; потолок и путь апгрейда записаны в коде.
2. **`min(uuid)` в PostgreSQL не существует** — в candidate-query потребовался каст через text.

### Внутренний review — четыре исправления

- Экспорт с фильтром по категории отдавал все строки разбитой транзакции, а не только
  совпавшие. В expand-фазе незаметно, в Stage 4 стало бы багом.
- Дублированный хелпер «категория из единственной строки» вынесен на агрегат.
- `CashFacade` трижды создавал снимок коллекции на каждую сериализацию.
- Проверен импорт `Query` в репозитории — используется.

### Self-review

- [x] Scope compliance — только переключение читателей, путь записи не тронут
- [x] Patterns / naming — доменное правило на агрегате, не в вызывающих местах
- [x] Forbidden actions — none
- [x] Security — company-скоуп сохранён во всех переписанных запросах
- [x] Тесты — ожидания не менялись нигде; совпадение вывода и было проверкой
- [x] ARCHITECTURE.md обновлён

### External review

- Reviewer: Codex CLI 0.146.0, `codex exec -s read-only --ephemeral`
- Iterations: 2
- Result: **REVIEW_GREEN**
- Ограничения ревьюера: без доступа к шеллу и БД; факты о проде, версиях и результатах
  прогонов передавались в промпте, дифф — через stdin.

**Подтверждённые находки, исправленные:**

| Уровень | Находка | Исправление |
|---|---|---|
| IMPORTANT | Карточка транзакции показывала пустую статью при мультиразбивке — условие «только если строка одна» | Выводятся все строки, при нескольких — каждая со своей суммой; прочерк только для пустой коллекции |
| MINOR | Шаблоны списков обходят `tx.splits`, ленивая коллекция даёт запрос на транзакцию | `CashTransactionRepository::warmSplits()` — второй шаг по идентификаторам страницы, вызывается из обоих списочных экшенов |

**Отклонённая находка с обоснованием:**

- IMPORTANT про `sumNetByFlowKindExcludeTransfers`: утверждалось, что транзакции без
  категории раньше попадали в группу `flowKind = NULL`, а теперь теряются. Опровергнуто
  по коду — в запросе стоит `andWhere('category.flowKind IS NOT NULL')`, и стоял он до
  правки. Группы `NULL` не существовало ни до, ни после; `COALESCE` был бы no-op.

Fetch-join коллекции в пагинированный запрос не добавлялся сознательно: join вместе с
`LIMIT` режет строки, а не транзакции, и страница поехала бы.

### Результаты проверок

- **Полный прогон (после исправлений ревью):** 2945 тестов, 16007 утверждений, 1 падение —
  `DashboardSnapshotGoldenTest`, предсуществующее и доказанное на базовом коммите
  (зависит от календарной даты).
- **CS:** прогнан точечно по семи изменённым PHP-файлам; чужие нарушения не трогали.
- **Twig:** `lint:twig` — 25 файлов валидны.

### Команды для проверки

- `make site-test`
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/transaction templates/cash`

### Риски / на что обратить внимание ревьюеру

- Задвоение сумм в агрегатах, если join коллекции размножит строки.
- Транзакции без категории: не исчезли ли из ведомости, выгрузки, дашборда.
- Поведение переведённого кода при мультиразбивке, которая появится в Stage 4.
- Экспорт больше не потоковый — потолок по памяти записан в коде.

### Открытые вопросы

- Release Gate: сверка отчёта ДДС и ведомости на проде за закрытый период. Тесты доказали
  совпадение вывода на фикстурах, но реальные 6030 строк — другое дело.
