# Stage 1 — правило в агрегате и форма операции без контрагента

## 1. Какую задачу решаем
Сделать так, чтобы контрагент операции документа ОПиУ всегда был контрагентом
шапки — на уровне сущности, для всех путей записи (форма создания/правки,
копия, создание из ДДС, `CreatePLDocumentAction`, займы, фикстуры) — и убрать
поле контрагента из формы операции.

## 2. Что будет результатом
- `DocumentOperation`: публичного `setCounterparty()` нет; контрагент
  берётся из документа при привязке (`setDocument()`), метод
  `inheritCounterparty()` (`@internal` для `Document`) читает его только из
  своего документа — передать постороннее значение нельзя.
- `Document::setCounterparty()` переносит контрагента на все операции.
- Проверка справочников операции (`assertReferencesBelongTo`) больше не
  проверяет контрагента отдельно — он берётся из шапки, которую проверяет сам
  документ.
- Пути записи без отдельного контрагента у операции: копия
  (`DocumentController::duplicateDocument`), `FinanceFacade`,
  `CashTransactionToDocumentService` (включая неиспользуемый
  `createOperationFromTransaction`), `CreatePLDocumentAction`.
- `CreatePLDocumentOperationCommand` без `counterpartyId`; контрагент — только
  у `CreatePLDocumentCommand`.
- `DocumentOperationType` без поля `counterparty` и опции
  `header_counterparty_id`; `DocumentType` её не передаёт; ячейка и подпись
  «Контрагент» в строке операции `_page.html.twig` убраны. Поле шапки,
  имена полей, маршруты, CSRF — без изменений.
- Тесты:
  - unit `DocumentCounterpartyRuleTest`: новая операция получает контрагента
    шапки; смена шапки переносится на все операции; сброс шапки сбрасывает
    операции; привязка через `setDocument()` напрямую; у операции нет
    сеттера контрагента;
  - functional: правка документа со сменой контрагента шапки → у всех
    операций новый; новая строка при архивном контрагенте шапки; POST с
    `operations[n][counterparty]` игнорируется (поля нет); в прототипе
    строки нет поля контрагента;
  - integration `CreatePLDocumentActionTest`: операции получают контрагента
    команды документа;
  - копия: контрагент операций копии = контрагент шапки копии.
- Существующие тесты, проверявшие «свой» контрагент операции (экспорт JSON,
  журнал операций, копия, правка, фикстуры списков), переводятся на новое
  правило: отличающийся контрагент операции теперь построить нельзя; сценарии
  «чужая компания через сырой SQL» остаются.

## 3. Что считаем выполненной работой
Правило держит сущность; все пути записи на нём; форма операции без поля;
затронутые тесты зелёные; PHPStan level 8 по изменённым файлам без новых
ошибок; cs-fixer чист.

## 4. Чек-лист приёмки
- [x] Через публичный API нельзя дать операции контрагента, отличного от шапки
- [x] Новая операция (форма, копия, ДДС, `CreatePLDocumentAction`) получает контрагента шапки
- [x] Смена/сброс контрагента шапки переносится на все операции
- [x] `CreatePLDocumentOperationCommand` без `counterpartyId`, вызывающие обновлены
- [x] В форме операции поля «Контрагент» нет; поле шапки на месте, имя и CSRF прежние
- [x] Unit-тесты правила — все ветки
- [x] Functional: смена шапки при правке → у всех операций новый контрагент
- [x] Затронутые тесты переведены на правило, остальные не менялись
- [x] PHPStan (изменённые файлы), cs-fixer, strict-types — чисто

---

## Итог (29.10.2026)
- `DocumentOperation::setCounterparty()` удалён; `inheritCounterparty()` (`@internal`) берёт
  значение только из своего документа; `setDocument()` вызывает его при привязке;
  `Document::setCounterparty()` — для всех операций. Проверка справочников операции больше
  не смотрит контрагента: его проверяет шапка.
- Пути записи: копия, `FinanceFacade::createDocumentFromCashTransaction`,
  `CashTransactionToDocumentService` (оба метода), `CreatePLDocumentAction` — без контрагента
  операции. `CreatePLDocumentOperationCommand::$counterpartyId` удалён (единственный вызывающий
  передавал `null`).
- Форма: поле операции и опция `header_counterparty_id` удалены, ячейка в шаблоне убрана.
  **Отступление от плана:** POST со старым полем `operations[n][counterparty]` (страница,
  открытая до выкладки; так же шлёт его `DocumentResponsibilityCenterControllerTest`) не
  роняет форму «лишним полем» — поле отбрасывается в `PRE_SUBMIT`, операция всё равно
  получает контрагента шапки. Тест `testOperationCounterpartyInPostIsIgnored`.
- Тесты: новый unit `DocumentCounterpartyRuleTest` (7); functional правки — смена шапки
  переносится во все операции, архивный контрагент шапки у новой строки, старое поле
  игнорируется, в прототипе строки нет поля; `CreatePLDocumentActionTest` — операция берёт
  контрагента команды документа. Переведены на правило (своего контрагента операции больше
  не построить): `DocumentCompanyReferenceTest`, `DocumentListFixturesTrait` +
  `DocumentOperationRepositoryListTest`, `PlOperationJsonExportControllerTest`,
  `DocumentCopyControllerTest`, `DocumentOperationIndexViewTest`, `DocumentEditPageTest`.
- Прогоны: Finance (unit+integration+functional) 541/541, Cash 712/712; PHPStan по 19
  изменённым файлам — без ошибок; cs-fixer — 0; `lint:twig` — OK.
