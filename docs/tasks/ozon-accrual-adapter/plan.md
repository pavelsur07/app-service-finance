# ozon-accrual-adapter: перевести Marketplace на /v1/finance/accrual/by-day

Ozon снял `POST /v3/finance/transaction/list` между 08.09 и 09.09.2026. Ответ —
HTTP 400 `{"code":9, "message":"obsolete method cannot be used"}`. Подтверждено на
PROD прогоном `app:marketplace:ozon-daily-sync`: все 56 сообщений ушли в
failed-транспорт, бизнес-день 08.09 отсутствует у всех четырёх кабинетов.

Цель: вернуть дневную загрузку финансовых данных Ozon в модуле Marketplace,
переведя `OzonAdapter` на живой `/v1/finance/accrual/by-day`. Ingestion остаётся
на месте: из него берётся только справочник категорий, read-only, через Facade.

Baseline: `make site-test-unit` — 2356 тестов зелёные; `make site-stan` — No errors;
`make site-cs-check` / `make site-cs-strict-types` — 0 из 2497. Pre-existing:
4 deprecations в unit-наборе, к задаче отношения не имеют.

## Что известно и что нет

Форма ответа зафиксирована в `tests/Unit/Ingestion/Application/Source/Ozon/OzonAccrualByDayMapperTest.php`
и `tests/Integration/Ingestion/Fixtures/FakeOzonAccrualClient.php`:

```
accrual_id, date, unit_number, accrued_category
posting.products[]
  ├─ sku, offer_id, name
  ├─ commission.{sale_amount, commission, bonus}
  └─ delivery.services[].{type_id, accrued:{amount,currency}}
```

Сэмпл рукописный и покрывает только `accrued_category = POSTING` с одной продажей.
Не отвечает на: какие ещё значения принимает `accrued_category` и какое означает
возврат; есть ли `quantity` в `posting.products[]`; полный список `type_id`.
Поэтому Stage 1 — снятие реальной выгрузки, и только после него пишется код.

Закрыто чтением PROD, гадать не нужно: `marketplace_sale_mappings.operation_type`
принимает ровно два значения — `sale` и `return`. Это внутренний домен, а не
`operation_type` из API Ozon. Существующие пользовательские маппинги P&L
миграцию переживают и правок не требуют.

## Требование сосуществования форматов

Старые записи обрабатываются старым обработчиком, новые загрузки — новым.
`OzonAdapter` не переписывается на месте: 967 существующих документов с
`api_endpoint = ozon::v3/finance/transaction/list` (01.01–07.09.2026) обязаны
остаться обрабатываемыми, иначе `ReprocessMarketplaceCommand` и
`OzonMonthRawRefreshCommand` перестанут работать по истории.

Дискриминатор уже существует и уже заполнен: `MarketplaceRawDocument.apiEndpoint`.
Реестр `MarketplaceRawProcessorRegistry::get($type, $marketplace, $kind)` третий
аргумент принимает, но `ProcessMarketplaceRawDocumentAction` сейчас его не
передаёт — сюда и встраивается выбор формата.

Прецедент в проекте уже есть: у Wildberries на одном `document_type = sales_report`
живут два формата — `wildberries::reportDetailByPeriod` (до 14.05.2026) и
`wildberries::finance-sales-reports-detailed` (текущий). Идём тем же путём.

Ловушка, которую обязан проверить ревьюер: `supports()` у существующих
Ozon-процессоров возвращает `true` для `StagingRecordType::SALE` и `OZON`
независимо от `$kind`, а реестр берёт **первое** совпадение. Пока оба процессора
не станут явно различать формат, новый будет затенён старым в зависимости от
порядка сервисов в контейнере.

## Граница: путь создания ОПиУ не трогаем

Указание Владельца от 09.09.2026. Marketplace отдаёт `PLEntryDTO` в
`FinanceFacade::createPLDocument()` из `CloseMonthStageAction` и
`ReopenMonthStageAction`, после чего `MarkProcessedQuery` помечает обработанное.
Ни один из этих файлов, ни `FinanceFacade`, ни `PLCategoryFacade` в задаче не
меняются.

Практическое следствие для Stage 4: новые процессоры обязаны писать в
`marketplace_sales` и `marketplace_costs` в **той же форме**, что легаси. Форма
этих таблиц — контракт, а не деталь реализации: за ним стоит закрытие месяца и
формирование ОПиУ. Если by-day не ложится в существующие поля без потерь, это
STOP по `AGENTS.md` §3.4 п.1, а не повод править контракт на ходу.

Отдельно: `marketplace_sale_mappings.operation_type` (`sale` / `return`) —
пользовательские маппинги категорий ОПиУ. Проверено на PROD, миграции не
требуют, значения не меняются.

## Stage 1: реальная выгрузка by-day как основа контракта
Risk: LOW
stage_base_commit: <записать перед первым Work item>
Definition of Done:
- `site/bin/capture-ozon-accrual.sh` снимает `/v1/finance/accrual/by-day`,
  `/v1/finance/accrual/types` и `/v1/finance/accrual/postings` за указанный день;
- снимок лежит в `tests/Fixtures/Marketplace/Ozon/captured/` (каталог под
  `.gitignore` — реальные данные продавца в репозиторий не попадают);
- в `tests/Fixtures/Marketplace/Ozon/` закоммичена сокращённая обезличенная
  фикстура, покрывающая продажу, комиссию, услугу доставки и возврат;
- в этом файле записаны ответы на три открытых вопроса выше — с цифрами;
- исключено: любые правки `OzonAdapter` и процессоров.
Work items:
- 1.1 — скрипт захвата по образцу `bin/capture-ozon-listings.sh`
- 1.2 — снятие выгрузки (ключи вводит Владелец, в репозиторий не попадают)
- 1.3 — разбор: значения `accrued_category`, наличие `quantity`, список `type_id`
- 1.4 — сокращённая обезличенная фикстура + запись выводов в plan.md
Stage checks:
- `bash -n site/bin/capture-ozon-accrual.sh`
- ручной просмотр снимка на предмет полноты кейсов
Reviewer focus:
- не утекли ли ключи и реальные данные продавца в отслеживаемые файлы

## Stage 2: справочник type_id → категория затрат через Facade
Risk: HIGH-LOCAL
stage_base_commit: <записать перед первым Work item>
Definition of Done:
- в `IngestionFacade` добавлен read-only метод разрешения `type_id` в категорию
  затрат; `ARCHITECTURE.md` обновлён в этом же Stage;
- Marketplace получает категорию только через Facade, своей копии справочника не
  заводит — одно доменное понятие в одном месте (`docs/workflow/health-gates.md`);
- неизвестный `type_id` деградирует в видимую очередь на ручной разбор, а не в
  `NULL` и не в «прочее»;
- unit-тесты на известный id, неизвестный id и пустой справочник;
- исключено: изменение логики самого Ingestion, его загрузчиков и таблиц.
Work items:
- 2.1 — метод Facade + DTO контракта
- 2.2 — потребитель в Marketplace
- 2.3 — обработка неизвестного id
Stage checks:
- `make site-test-unit`, `make site-stan`, `make site-cs-check`
- `site/tests/Architecture/ModuleBoundaryRules.php` — границы модулей
Reviewer focus:
- не протёк ли импорт `Application/`/`Service/` Ingestion мимо Facade
- поведение на неизвестном `type_id`

## Stage 3: сосуществование форматов в конвейере обработки
Risk: HIGH-LOCAL
stage_base_commit: <записать перед первым Work item>
Definition of Done:
- формат документа выводится из `MarketplaceRawDocument.apiEndpoint` и передаётся
  в реестр процессоров как `$kind`;
- существующие Ozon-процессоры явно заявляют легаси-формат в `supports()`;
  затенения по порядку сервисов не остаётся;
- регрессионный тест: документ с `ozon::v3/finance/transaction/list`
  обрабатывается тем же процессором, что и до правки, с тем же результатом;
- поведение WB не меняется ни на одном из двух её форматов;
- исключено: новые процессоры Ozon и любые правки `OzonAdapter` — только каркас.
Work items:
- 3.1 — вывод формата из `apiEndpoint`, передача в `registry->get()`
- 3.2 — явный `supports()` по формату у существующих Ozon-процессоров
- 3.3 — регрессионные тесты на легаси-документах, включая оба формата WB
Stage checks:
- `make site-test`, `make site-stan`, `make site-cs-check`
Reviewer focus:
- затенение процессоров порядком в контейнере
- документ с неизвестным `apiEndpoint`: внятная ошибка, а не молчаливый пропуск

## Stage 4: новый путь загрузки и обработки by-day
Risk: HIGH-LOCAL
stage_base_commit: <записать перед первым Work item>
Definition of Done:
- клиент `/v1/finance/accrual/by-day` и загрузчик пишут документы с
  `api_endpoint = ozon::v1/finance/accrual/by-day`;
- новые процессоры продаж, затрат и возвратов работают на by-day; затраты
  классифицируются через Facade из Stage 2;
- ключ дедупа `MarketplaceStaging.externalId` — составной из `accrual_id`,
  индекса товара и `type_id` услуги; повторный прогон дня дублей не создаёт;
- тесты на фикстуре из Stage 1: продажа, комиссия, услуга, возврат, пустой день,
  повторный прогон;
- `OzonTransactionTotalsClient` (`/v3/finance/transaction/totals`, мёртвый код,
  снят Ozon тем же решением) удалён вместе со своим тестом;
- старый путь остаётся рабочим на старых документах — проверяется тестом;
- исключено: удаление легаси-процессоров и legacy-кода `OzonAdapter`.
Work items:
- 4.1 — клиент нового эндпоинта в `Infrastructure/Api/Ozon/`
- 4.2 — загрузчик: документ с новым `api_endpoint`, составной ключ дедупа
- 4.3 — процессор продаж
- 4.4 — процессор затрат через Facade-справочник
- 4.5 — процессор возвратов
- 4.6 — удаление мёртвого `OzonTransactionTotalsClient`
Stage checks:
- `make site-test`, `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`
Reviewer focus:
- идемпотентность повторного прогона дня
- знаки сумм: by-day отдаёт расходы отрицательными, легаси DTO ждёт положительные
- company scope во всех новых запросах
- что легаси-путь не задет ни одной строкой

## Stage 5: восстановление истории и сверка
Risk: HIGH-LOCAL
stage_base_commit: <записать перед первым Work item>
Definition of Done:
- замеры «до» сняты и записаны: документы в окне, строки `marketplace_sales`;
- один кабинет перезалит за один день, суммы сверены с независимым источником —
  `ingest_financial_transactions` за тот же день;
- расхождение объяснено до массового перезалива, а не после;
- исключено: массовый перезалив без сверки на одном кабинете.
Work items:
- 5.1 — сверочный запрос Marketplace против Ingestion за день
- 5.2 — перезалив одного кабинета за один день, сверка
- 5.3 — перезалив 08.09 и далее по всем кабинетам
- 5.4 — вернуть строку `ozon-daily-sync` в `docker/cron/app.cron` (отключена 09.09.2026)
Stage checks:
- read-only сверка через `codex-psql-ro`
Reviewer focus:
- совпадение сумм с Ingestion; отсутствие дублей после повторного прогона

## Открытые вопросы Владельцу

- ~~Крон в 04:00~~ — закрыто 09.09.2026: строка закомментирована по указанию
  Владельца, возврат запланирован Work item 5.4.
- 56 сообщений, уже лежащих в `failed`: оставить как опись для Stage 4 или снести.
