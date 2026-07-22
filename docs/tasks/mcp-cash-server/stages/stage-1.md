## Stage 1: Write-логика ДДС в Action — DONE

**Риск:** 🟠 HIGH-LOCAL (правки в финансовых контроллерах)
**Следующее действие:** continue autonomously → Stage 2

### Что сделано

- `SaveCashflowCategoryAction` — единственная точка сохранения статьи ДДС:
  проверка вложенности (≤5), принадлежности родителя и статьи ОПиУ компании,
  `syncFlowKindSubtree()`, валидация сущности, persist + flush.
- `SaveCashTransactionAutoRuleAction` — единственная точка сохранения автоправила:
  проверка пары проект/ЦФО, валидация сущности, аудит-штамп `recordUpdate` при
  реальных изменениях, persist + flush.
- Оба контроллера переключены на Action; `\DomainException` рендерится как flash
  (статьи) или ошибка формы (автоправила).
- Устранено дублирование: проверка «5 уровней» была скопирована в `new` и `edit`,
  блок аудита жил только в `edit`.

Actions не зависят от `Request` и формы — это тот шов, через который Stage 3
подключит MCP-инструменты, не создавая вторую реализацию бизнес-правил.

### Проверки компанией (новое)

Форма ограничивала выбор родителя и статьи ОПиУ деревом активной компании, то есть
проверки на уровне данных не было. Для MCP этого недостаточно — Action проверяет
`companyId` родителя и `plCategory` сам.

### Затронутые файлы

- `site/src/Cash/Application/SaveCashflowCategoryAction.php` — new
- `site/src/Cash/Application/SaveCashTransactionAutoRuleAction.php` — new
- `site/src/Cash/Controller/Transaction/CashflowCategoryController.php` — modified
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php` — modified
- `site/tests/Unit/Cash/Application/SaveCashflowCategoryActionTest.php` — new
- `site/tests/Unit/Cash/Application/SaveCashTransactionAutoRuleActionTest.php` — new

Миграций нет.

### Self-review

- [x] Scope compliance — только вынос write-логики
- [x] Patterns / naming — `final class`, `__invoke`, `\DomainException` как в `CreateDocumentFromTransactionAction`
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR) — добавлены проверки принадлежности родителя и статьи ОПиУ
- [x] CS-Fixer по изменённым файлам — чисто (25 нарушений в `src/Cash` — pre-existing, не тронуты)
- [x] `--testsuite unit` — 1529 тестов зелёные
- [x] `bin/console lint:container` — OK
- [x] ARCHITECTURE.md — N/A, новых Facade/Enum/Entity нет

### External Claude Code review

- N/A — задача выполняется Claude Code напрямую по поручению Владельца, а не Codex;
  внешний reviewer в этом режиме не применяется. Проведён внутренний review полного diff.

### Команды для проверки

- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit`
- `docker compose run --rm -T site-php-cli php bin/console lint:container`

### Риски / на что обратить внимание ревьюеру

- Путь редактирования автоправила теперь дважды вызывает `CashTransactionAutoRuleTargetValidator`:
  контроллер — чтобы показать ошибку у поля ЦФО, Action — чтобы гарантировать
  инвариант при вызове из MCP. Проверка in-memory + один lookup фасада.
- В Action добавлен вызов `ValidatorInterface::validate()`. На HTTP-пути форма уже
  провалидировала сущность, поэтому новых отказов быть не должно.
- `edit` статьи ДДС теперь вызывает `persist()` на managed-сущности — no-op в Doctrine.

### Открытые вопросы

- У `CashflowCategory::$name` нет `NotBlank` на уровне сущности — обязательность
  держит только форма. Для MCP имя будет обязательным в JSON-схеме tool (Stage 3);
  добавление констрейнта в сущность вынесено из scope, чтобы не ломать существующие данные.
