# Marketplace M1: граница Source Normalization ↔ Financial Processing

Срез: `master` @ `5a8c36d9` + ветка M1, 2026-10-08. Аудит исходников, без рефакторинга. Цель —
дать основание для **раздельных** решений о выносе загрузки, парсинга и source normalization в
Go/Python, оставив Symfony владельцем финансовых операций, правил, закрытия периодов и posting
([05](05-target-architecture.md)). Пути ниже — от `site/src/Marketplace/`, если не указано иное.
Замеры этих этапов — [09](09-m1-diagnostics.md).

## Термины

| Слой | Что делает | Выход |
|---|---|---|
| **Fetch** | HTTP к API, пагинация, rate limit, курсор | тело ответа |
| **Source parsing** | JSON → массив строк источника | строки как в API |
| **Source normalization** | строки источника → нормализованные факты: тип записи (продажа/возврат/затрата/прочее), количества, суммы, даты, ключи, **код** категории услуги | факты без ссылок на сущности и справочники компании |
| **Financial mapping** | факты → сущности и справочники компании: листинг, категория затрат (сущность), себестоимость, привязка возврата к продаже, решение «вставить/пропустить/присвоить» | готовые к записи строки учёта |
| **Financial processing / posting** | запись `marketplace_sales/returns/costs/ozon_realizations`, замена строк документа, закрытие месяца, ОПиУ | строки учёта, документы Finance |

## 1. Фактическая цепочка

```text
Sync*Handler ─ fetch ─► MarketplaceRawDocument.rawData (JSON-колонка PostgreSQL, не S3)
ProcessDayReportHandler ─► 3 × ProcessRawDocumentStepMessage (sales / returns / costs)
ProcessMarketplaceRawDocumentAction
   ├─ классификатор строки (StagingRecordType) ─ PURE
   ├─ sales/returns: processor->processBatch(500 строк)   ┐ normalize + mapping + persist
   └─ costs:        processor->process(rawDoc)            ┘ внутри одного класса
CloseMonthStageAction ─► Application/Source/* ─► FinanceFacade::createPLDocument (ОПиУ)
```

Raw хранится в PostgreSQL (`Entity/MarketplaceRawDocument.php:65-66`); S3 использует Ingestion и
MarketplaceAds. Это важно для выноса: внешний сервис не может «положить raw туда же», не получая
прямого доступа к таблице Symfony, — нужен handoff-контракт из [05](05-target-architecture.md).

## 2. Source parsing

| Провайдер | Где | Отделимость |
|---|---|---|
| Ozon by-day | `OzonAccrualByDayClient::request()` → `toArray()` | парсинг совмещён с чтением тела; отделим только заменой `toArray()` |
| Ozon realization | `OzonRealizationFetcher::fetch()` → `toArray()` | то же |
| WB | `WbFinanceSalesReportClient::fetchDetailedPage()` — `getContent()` + `json_decode` с проверками списка и монотонности `rrdId` | отделён, чистая функция над телом |
| raw из БД | гидрация Doctrine `find()` | совмещён с чтением; повторяется на каждом шаге (3 шага × документ) |

Парсинг не зависит от Doctrine, справочников и состояния периодов. Пагинация WB растянута по
сообщениям (`SyncWbFinancialReportDayHandler`, `rrdId`, `DelayStamp`), raw дописывается к документу
постранично с повторным `json_encode` всего документа на каждой странице.

## 3. Source normalization: что уже чистое

| Код | Зависимости | Чистота | Выход |
|---|---|---|---|
| `Ozon/Infrastructure/Normalizer/OzonAccrualByDayRowClassifier.php` | нет | PURE | `StagingRecordType` (по `accrued_category`, знаку `sale_amount`; float-приведение :74) |
| `Wildberries/Infrastructure/Normalizer/WbReportRowClassifier.php` | нет | PURE | `StagingRecordType` по ключевым словам `sellerOperName/docTypeName` (:21-46) |
| `Infrastructure/Normalizer/RowClassifierRegistry.php` | tagged iterable | PURE | выбор классификатора по провайдеру и формату |
| `Wildberries/Infrastructure/Normalizer/WbSalesReportRowNormalizer.php` | нет | PURE | аксессоры camelCase/snake_case; **деньги — float** (:247-266, :111, :204-209), даты — `DateTimeImmutable` |
| `OzonAccrualSalesRawProcessor::extractSales` (:194-269) | только logger | PURE + warning | `externalId`, `accrualId`, sku, дата, количество, `pricePerUnit/totalRevenue` строками |
| `OzonAccrualReturnsRawProcessor::extractReturns` (:197-274) | только logger | PURE + warning | то же + `saleExternalId` |
| `OzonAccrualCostsRawProcessor::extractEntries/serviceEntry` (:258-397) | `OzonAccrualServiceCategoryResolver` | PURE; бросает на непустых `container_fees` | `externalId`, **код** категории, сумма строкой, `operationType`, дата, sku |
| `Ozon/Application/Service/OzonAccrualServiceCategoryResolver.php` | статический каталог `Ozon/Domain/OzonCostCategory.php` | PURE | код категории по `type_name`; знак `Compensation` выбирает код; неизвестное → `ozon_unknown_<type_id>`, `known:false` |
| `Ozon/Application/Service/OzonAccrualRecordKey::decide()` | — | PURE, но **требует штампов из БД** (`MarketplaceSaleRepository::getAccrualStamps`, :140-147) | SKIP / INSERT / CLAIM_LEGACY / INSERT с суффиксом `-acc{id}` |
| WB `CostCalculator/*` (12 калькуляторов) | normalizer, logger; `WbDeductionCalculator` — `SlugifyService`, `WbCostCategory` | без БД, но принимают `?MarketplaceListing` и возвращают `product => $listing->getProduct()` | `{category_code, amount, external_id, cost_date, operation_type, product}` |

Вывод: классификация, разбор строк Ozon by-day, коды категорий затрат Ozon и WB уже являются
функциями «строка источника → факт» и технически переносимы. Единственная зависимость от
сущности в нормализации — `MarketplaceListing` в калькуляторах WB, и она нужна только для поля
`product`, которое относится к mapping.

## 4. Где нормализация сплетена с mapping и записью

| Код | Что внутри одного цикла |
|---|---|
| `Wildberries/Application/Processor/WbSalesRawProcessor::processBatch` (:57-174) | фильтр строк → `MarketplaceBarcodeCatalogService::fillFromWbRows` (upsert) → поиск/создание листингов (flush) → дедуп по srid (БД) → деньги float→`number_format`→`bcdiv` → себестоимость → `new MarketplaceSale`, flush |
| `WbReturnsRawProcessor::processBatch` | то же + привязка к продаже по srid; refund `(string) float` без округления (:161) |
| `Wildberries/Application/Action/ProcessWbCostsAction` | листинги, предзагрузка категорий, калькуляторы, дедуп по существующим external id, `MarketplaceCostCategoryResolver::resolve`, persist/flush батчами |
| `OzonAccrual*RawProcessor::processBatch/process` | после чистого `extract*`: `ensureListings` (INSERT … ON CONFLICT), `getAccrualStamps`, `claimLegacyRecord` (UPDATE raw_data), себестоимость, привязка возврата, persist/flush |
| `Ozon/Application/Action/ProcessOzonRealizationAction::process` (:136-267) | нормализация, поиск листинга по SKU, сборка `MarketplaceOzonRealization`, flush каждые 250 — один цикл |

## 5. Зависимости financial mapping (остаются у владельца данных)

| Сервис | Зависимость | Побочный эффект во время обработки |
|---|---|---|
| `Application/Service/MarketplaceCostCategoryResolver` | `MarketplaceCostCategoryRepository`, EntityManager | **создаёт** категорию затрат «на лету» (:89-102) и **восстанавливает** мягко удалённую (:64-66, :85-87) |
| `Application/Service/MarketplaceCostPriceResolver` | `Marketplace/Inventory` → `InventoryCostPriceResolver`, таблица `marketplace_inventory_cost_prices` | себестоимость по листингу и дате; для возврата — из продажи, затем `orderDt`, затем дата возврата, затем `0.00` |
| `Ozon/Application/Service/OzonListingEnsureService` | `OzonListingUpsertQuery`, EntityManager | **создаёт** `MarketplaceListing` (`INSERT … ON CONFLICT DO NOTHING`), может обновить имя/артикул |
| `Wildberries/Application/Service/WbListingResolverService` | репозитории листингов и баркодов, `WbBarcodeUpsertQuery` | **создаёт** листинги и баркоды |
| `Application/Service/MarketplaceBarcodeCatalogService` | `MarketplaceBarcodeCatalogRepository` | upsert каталога баркодов |
| `OzonAccrualRecordKey` + `MarketplaceSaleRepository::getAccrualStamps/claimLegacyRecord` | `marketplace_sales.raw_data` | решение об идемпотентности и UPDATE легаси-строк |

**Cost → ОПиУ при обработке не происходит.** Обработка привязывает только `MarketplaceCostCategory`;
категория ОПиУ берётся при закрытии месяца: `Infrastructure/Query/UnprocessedCostsQuery.php`
(join `marketplace_cost_pl_mappings`, `include_in_pl`) → `Application/Source/CostsDataSource.php:56` →
`CloseMonthStageAction.php:193-228` → `PLEntryDTO(plCategoryId)`. Продажи/возвраты — через
`UnprocessedSalesQuery`. Это уже готовая граница «нормализация/учёт ↔ ОПиУ».

## 6. Корректировки и закрытые периоды

| Механизм | Правило | Где |
|---|---|---|
| `ByDayRowReplacement::prepare*` (Ozon by-day) | advisory lock месяца → `Company::financeLockBefore` (день ≤ блокировки — не менять вообще) → этап не закрыт — заменять → закрыт окончательно — нет → прошлый предварительный — да, связи сохраняются → текущий предварительный — отвязать строки от предварительных документов ОПиУ | `Application/Service/ByDayRowReplacement.php:76-199`; вызов внутри транзакции `ProcessMarketplaceRawDocumentAction.php:206-255`, затраты — `OzonAccrualCostsRawProcessor.php:134` |
| `deleteByRawDocument` | удаляет только `document IS NULL` — строки, привязанные к документу ОПиУ, не трогаются | `MarketplaceSaleRepository.php:236`, `MarketplaceReturnRepository.php:180`, `MarketplaceCostRepository.php:91`; затраты не-by-day — SQL `document_id IS NULL` (`ProcessMarketplaceRawDocumentAction.php:138-145`) |
| WB forceRefresh | `WbGeneratedRowsSafeReplaceService` удаляет открытые строки документа, связанные сохраняет (warning со счётчиками); проверки `financeLockBefore` и состояния месяца нет; плюс `deleteOpenByExternalIds` по srid | `Wildberries/Application/FinancialReport/WbGeneratedRowsSafeReplaceService.php:24-44`, `ProcessMarketplaceRawDocumentAction.php:437-480` |
| Ozon realization reprocess | удаляет **все** строки документа, включая привязанные, пересоздаёт и восстанавливает `pl_document_id` по SKU; проверки блокировки нет | `ProcessOzonRealizationAction.php:314-380` |
| Легаси Ozon v3 | `financeLockBefore` → `DomainException` | `Ozon/Application/Processor/OzonSalesRawProcessor.php:318-321`, `OzonCostsRawProcessor.php:298-301` |
| ADR-007 (`fincore_periods`, OPEN/SOFT_CLOSED/CLOSED) | в коде Marketplace не используется | `docs/financial-core/adr/007-period-states.md` |

Все эти правила требуют состояния Symfony (компания, закрытие месяца, документы ОПиУ, advisory
lock) и транзакции вместе с записью строк. Наблюдение для будущих этапов (не меняется в M1):
политика закрытых периодов различается по путям — by-day полная, WB forceRefresh и realization
без `financeLockBefore`.

## 7. Деньги и ключи

- **Деньги в нормализации — смесь float и строк.** float: `WbSalesReportRowNormalizer.php:263, :111, :204-208`,
  `OzonAccrualByDayRowClassifier.php:74`, `OzonAccrualSalesRawProcessor.php:236, :262-263`,
  `OzonAccrualCostsRawProcessor.php:290, :295, :363, :376`, `WbDeductionCalculator.php:41`,
  `ProcessOzonRealizationAction.php:178, :199, :252`. bcmath: `OzonAccrualSalesRawProcessor.php:294, :333`,
  `OzonAccrualReturnsRawProcessor.php:280, :314`, `OzonAccrualRecordKey.php:82`, `WbSalesRawProcessor.php:158`,
  `MarketplaceCostPriceResolver.php:58`. Строка на границе сущности — `number_format(…, 2)` или
  `(string) abs(float)`. Перенос нормализации обязан воспроизвести **ровно эти** округления, иначе
  паритет сумм не сойдётся; контракт handoff ([05](05-target-architecture.md)) требует точные строки.
- **Ключи идемпотентности:** WB продажи/возвраты — `srid`; WB затраты — `wb:{rrdId}:{categoryCode}`
  (`CostCalculator/WbCostExternalIdBuilder.php`); Ozon by-day — `ozon-accrual-{ref}-product-{i}` /
  `-return-product-{i}`, суффикс `-acc{accrual_id}`, маркер `_accrual_id` в `raw_data`; Ozon затраты —
  `ozon-accrual-{accrual_id}-…-type-{type_id}`. Индексы: `uniq_marketplace_srid` (без company_id —
  отдельная задача R-12b), `uniq_marketplace_*_company_marketplace_external*`, `uniq_company_marketplace_code`
  (категории), `uniq_company_marketplace_sku_size` (листинги). У `MarketplaceOzonRealization` бизнес-ключа нет —
  идемпотентность через delete-and-recreate по документу.

## 8. Что технически можно вынести в Go/Python

| Операция | Вынос | Условие |
|---|---|---|
| Fetch Ozon/WB (HTTP, пагинация, rate limit, 429, курсор) | **да** | версионированный envelope и durable handoff ([05](05-target-architecture.md)); общий seller-bucket WB |
| Source parsing (JSON → строки, валидация формы, монотонность `rrdId`) | **да** | — |
| Хранение raw-батчей (S3) | **да, как новый путь** | сейчас raw Marketplace в PostgreSQL; S3-путь — по контракту handoff, текущее хранение не меняется до cutover |
| Классификация строк (`StagingRecordType`) | **да** | паритет по `RowClassifierRegistry` на архивном raw |
| Нормализация строки Ozon by-day (`extract*`), коды категорий Ozon (`OzonAccrualServiceCategoryResolver` + каталог `OzonCostCategory`) | **да** | каталог кодов — версионированный артефакт, общий с Symfony; неизвестные коды — карантин, не skip |
| WB калькуляторы затрат (сумма, код категории, external id) | **да, без поля `product`** | `product` и листинг — mapping, остаётся в Symfony |
| Денежные поля факта | **да, строками** | точное воспроизведение округлений §7 и тесты паритета до копейки |
| Решение по `OzonAccrualRecordKey` | **нет** (только вычисление ключа) | нужны штампы и UPDATE строк Symfony |

## 9. Что обязательно остаётся в Symfony

- Создание и восстановление `MarketplaceCostCategory`, листингов, баркодов — справочники компании.
- Себестоимость (`marketplace_inventory_cost_prices`) и привязка возврата к продаже.
- Идемпотентная вставка: дедуп по существующим ключам, `claimLegacyRecord`, решения SKIP/INSERT.
- Правила замены и закрытых периодов: `ByDayRowReplacement`, `deleteByRawDocument … document IS NULL`,
  `financeLockBefore`, предварительное/окончательное закрытие, advisory lock.
- Запись `marketplace_sales/returns/costs/ozon_realizations` в одной транзакции с заменой.
- Cost → ОПиУ (`marketplace_cost_pl_mappings`), `CloseMonthStageAction`, `FinanceFacade`, Financial Core.

## 10. Рекомендация по границе

1. **Граница — «нормализованный факт источника».** Слева (кандидат во внешний сервис): fetch, parse,
   классификация, извлечение фактов с кодом категории, суммой строкой, датой, количеством и стабильным
   ключом источника. Справа (Symfony): всё, что читает или создаёт сущности компании, решает об
   идемпотентности по состоянию БД и знает о периодах.
2. **Выносить по отдельности, по данным M1.** Если bottleneck — `api_fetch`/`source_parse` или запись/
   гидрация большого JSON (`storage_*/postgres`), выносится fetch+parse с батчами вне PostgreSQL
   (ближайший шаг [06](06-migration-roadmap.md) M4/M6). Если — `processor_total` при малой доле
   `financial_mapping`/`financial_posting`, кандидат — нормализация. Если доминируют `financial_mapping`
   или `financial_posting`, вынос нормализации не поможет: это работа Symfony (предзагрузка
   себестоимости и категорий, батчи) — M2.
3. **Подготовка в Symfony без смены поведения (будущие Stage, не M1):** выделить в WB процессорах чистую
   функцию «строка → факт» по образцу Ozon `extract*`, убрать `product` из выхода калькуляторов, свести
   деньги нормализации к строкам с явным округлением. Это создаёт контракт факта, который потом можно
   реализовать на Go/Python и проверять на паритет на том же raw.
4. **Неизменяемые входы для паритета:** каталог `OzonCostCategory`, `WbCostCategory`, правила округления,
   форматы ключей §7 — версионировать как часть контракта; расхождение кода категории или копейки —
   отказ cutover.
