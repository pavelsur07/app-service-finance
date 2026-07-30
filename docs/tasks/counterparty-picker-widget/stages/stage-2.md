## Stage 2: Перевод остальных форм и снятие fail-open tenant-фильтра — DONE

**Риск:** 🟠 HIGH-LOCAL (правится legacy-код четырёх модулей)
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** continue autonomously (Stage 3)

### Scope Stage
- Stage base commit: `3cec3648d6ac480b86c88c7993eb54bc889b96d6`
- Work items completed: `2.1`, `2.2`, `2.3`, `2.4`

### Что сделано
- Переведены на виджет: `DocumentType`, `DocumentOperationType` (`Finance`),
  `CreateDealType` (`Deals`), `PaymentPlanType`, `CashTransactionAutoRuleType` и
  `CashTransactionAutoRuleConditionType` (`Cash`).
- `EntityType` с `Counterparty` в кодовой базе больше не встречается — `grep` пустой.
- Из контроллеров убраны выборки справочника: `DocumentController` (три места) и
  `CashTransactionAutoRuleController` (два места) больше не тянут
  `findBy(['company' => ...])`, вместе с ними ушли неиспользуемые аргументы
  `CounterpartyRepository`.
- Опция `counterparties`, которую формы принимали извне, удалена вместе с пробросом
  во вложенные коллекции (операции документа, условия автоправила).

### Закрытый дефект
`CreateDealType:55` и `PaymentPlanType:86` фильтровали по компании внутри
`query_builder` под `if ($company)`, при `'company' => null` в `configureOptions`.
Забытая опция открыла бы справочник всех компаний, а `EntityType` принял бы любой id
из этого списка. Теперь в обеих формах `setRequired('company')` и
`setAllowedTypes('company', Company::class)`, а внутри виджета — `setRequired('company_id')`.
Проверка «форма без company не собирается» покрыта тестом.

### Затронутые файлы
- `src/Finance/Form/DocumentType.php`, `src/Finance/Form/DocumentOperationType.php` — modified
- `src/Deals/Form/CreateDealType.php` — modified
- `src/Cash/Form/PaymentPlan/PaymentPlanType.php` — modified
- `src/Cash/Form/Transaction/CashTransactionAutoRuleType.php`, `CashTransactionAutoRuleConditionType.php` — modified
- `src/Finance/Controller/DocumentController.php`, `src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php` — modified

### Self-review
- [x] Scope compliance — только выбор контрагента, остальные поля форм не тронуты
- [x] Security — единственный запрос за списком живёт в фасаде, tenant-фильтр обязателен
- [x] Поведение форм сохранено: `placeholder`, `required: false`, предзаполнение через
      `keep_id`, атрибуты `data-document-counterparty` / `data-operation-counterparty`
      оставлены — на них опирается существующий JS
- [x] Прямых импортов `CounterpartyRepository` в формах не осталось
- [x] CS-Fixer — чисто
- [x] Tests — полный прогон см. stage-3

### Открытые вопросы
- Прямые импорты `CounterpartyRepository` вне форм (`DealManager`,
  `CreatePLDocumentAction`, `FinanceFacade`, `ScoreCompanyCounterpartiesAction`)
  сознательно не тронуты — отдельная задача, как и записано в плане §10.
