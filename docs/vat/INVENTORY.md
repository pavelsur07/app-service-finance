# PROMPT-00: Инвентаризация точек внедрения НДС (по текущему коду)

Цель: зафиксировать реальные сущности/поля/сервисы, куда можно безопасно встроить управленческий НДС без изменения текущей логики.

## Шаг 1. Текущие ядра данных

### Company (`companies`)
* Конструктор: `__construct(string $id, User $user)`.
* Поля: `name`, `inn`, `wildberriesApiKey`, `ozonSellerId`, `ozonApiKey`, `financeLockBefore`.
* Резюме: сущность компании уже содержит настройки и может быть расширена дополнительными полями (например, `taxSystem`) при сохранении совместимости по умолчанию. 【F:site/src/Entity/Company.php†L17-L177】

### ДДС-операция: `CashTransaction` (`cash_transaction`)
* Конструктор: `__construct(string $id, Company $company, MoneyAccount $account, CashDirection $direction, string $amount, string $currency, \DateTimeImmutable $occurredAt)`.
* Сумма хранится в `amount` (`decimal(18,2)`), направление — `direction` (`CashDirection::INFLOW|OUTFLOW`).【F:site/src/Cash/Entity/Transaction/CashTransaction.php†L20-L116】
* Документы ОПиУ связаны через `documents` (OneToMany).【F:site/src/Cash/Entity/Transaction/CashTransaction.php†L51-L59】

### ОПиУ-операция: `DocumentOperation` (`document_operations`)
* Сумма: `amount` (`decimal(15,2)`), направления отдельным полем нет — знак/природа определяются категорией (`PLCategory`).【F:site/src/Entity/DocumentOperation.php†L24-L52】

### Документ ОПиУ: `Document` (`documents`)
* Привязка к ДДС-транзакции через `cashTransaction` (nullable).【F:site/src/Entity/Document.php†L23-L31】
* Итоговая сумма вычисляется как сумма `DocumentOperation.amount`.【F:site/src/Entity/Document.php†L136-L160】【F:site/src/Entity/Document.php†L200-L216】

## Шаг 2. Как считается ДДС-отчёт сейчас

`CashflowReportBuilder::build()` выбирает `t.direction`, `t.amount`, `t.currency`, `t.occurredAt` и приводит расход к отрицательному по `CashDirection::OUTFLOW`.【F:site/src/Report/Cashflow/CashflowReportBuilder.php†L34-L91】

## Шаг 3. Как считается ОПиУ сейчас

* `PLRegisterUpdater::aggregateDocuments()` берёт `abs((float)$operation->getAmount())` и раскладывает в `income/expense` по `PlNatureResolver->forOperation($operation)`.【F:site/src/Service/PLRegisterUpdater.php†L96-L171】
* `RawPlReportController` строит таблицу операций документов и использует `PlNature->sign()` для `amount_signed`.【F:site/src/Controller/Finance/RawPlReportController.php†L28-L110】
* Определение доход/расход происходит через категорию операции: `PlNatureResolver::forOperation()` возвращает `PLCategory->nature()`.【F:site/src/Service/PlNatureResolver.php†L14-L26】

## Шаг 4. Места создания/обновления операций

### ДДС CRUD
* `CashTransactionService::add()` и `update()` создают/обновляют `CashTransaction` и сохраняют `amount`, `direction`, `currency`, `occurredAt`.【F:site/src/Cash/Service/Transaction/CashTransactionService.php†L32-L199】

### Генерация ОПиУ из ДДС
* `CashTransactionToDocumentService` создаёт `DocumentOperation` и кладёт `amount` (из транзакции или отформатированной суммы).【F:site/src/Cash/Service/Transaction/CashTransactionToDocumentService.php†L31-L109】

### Другие генераторы `DocumentOperation`
* `LoanScheduleToDocumentService::createDocumentFromSchedule()` формирует `DocumentOperation` на сумму процентов/комиссий/тела займа (если включено).【F:site/src/Loan/Service/LoanScheduleToDocumentService.php†L26-L80】
* `WildberriesWeeklyPnlGenerator::createWeeklyDocumentFromTotals()` формирует операции по агрегированным итогам WB. 【F:site/src/Marketplace/Wildberries/Service/WildberriesWeeklyPnlGenerator.php†L99-L158】
* `DocumentController::duplicateDocument()` копирует операции при клонировании документа. 【F:site/src/Controller/DocumentController.php†L287-L310】

## Шаг 5. Точки внедрения (без рефакторинга)

Ниже — допустимые точки расширения, соответствующие текущей архитектуре и не меняющие поведение по умолчанию.

1. **Company**: добавить настройку (например, `taxSystem` + опционально ставка/флаг) и UI в `CompanyType`/`company/edit.html.twig`. При отсутствии значения трактовать как legacy/no VAT (поведение = как сейчас).【F:site/src/Entity/Company.php†L17-L177】【F:site/src/Form/CompanyType.php†L13-L46】【F:site/templates/company/edit.html.twig†L1-L49】
2. **Политика НДС**: внедрить сервис, который по `company × направление` возвращает ставку/применимость. Точка подстановки — в местах расчёта сумм (см. далее). (Сервис не требует новых сущностей.)
3. **ДДС отчёт**: корректировать суммы в `CashflowReportBuilder` при сборке строк (где уже нормализуется знак).【F:site/src/Report/Cashflow/CashflowReportBuilder.php†L34-L91】
4. **ОПиУ агрегаты**: корректировать суммы при агрегации в `PLRegisterUpdater::aggregateDocuments()` (до записи в `PLDailyTotal`).【F:site/src/Service/PLRegisterUpdater.php†L96-L171】
5. **ОПиУ детализация**: корректировать `amount_signed` в `RawPlReportController` при построении строк отчёта.【F:site/src/Controller/Finance/RawPlReportController.php†L28-L110】

## Ограничения (из запроса)

* Никаких новых сущностей/модулей «с нуля», только точечные расширения текущих.
* Если `Company.taxSystem` отсутствует/NULL — трактуем как legacy/no VAT, поведение = как сейчас.

