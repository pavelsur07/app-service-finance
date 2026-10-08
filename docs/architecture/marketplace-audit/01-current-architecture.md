# Marketplace Stage 0: фактическая архитектура

Срез: `master` @ `e0dda166`, 2026-10-08. Это аудит исходников и конфигурации, без запуска production-команд. Слова «сейчас» относятся к этому срезу. Документы [02](02-queues-and-workers.md), [03](03-performance-bottlenecks.md) и [04](04-data-and-financial-flows.md) раскрывают очереди, нагрузку и деньги.

## Стек и границы

Основное приложение — PHP 8.4 / Symfony 7.4 / Doctrine ORM 3 / PostgreSQL 15 / Redis 7. Messenger использует Redis transport, а не RabbitMQ: `site/composer.json:7,16,39,51`, `docker-compose.prod.yml:16-20,374-415`, `site/config/packages/messenger.yaml`. Есть Redis, PHP-FPM, CLI, отдельные Messenger workers и cron (`docker-compose.prod.yml:172-353,423-500`). Размеры и фактическое число рабочих процессов этим аудитом не измерялись.

Физического отдельного сервиса Ozon или WB нет. Внутри `App\Marketplace` провайдеры уже разнесены в `Ozon/` и `Wildberries/`, но общий слой владеет Entity, Repository, Message, Facade, обработкой raw и закрытием месяца (`ARCHITECTURE.md:358-377`; `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php:41-55`). Параллельно существует `App\Ingestion` с собственными raw/canonical таблицами; его нормализация **не пишет ОПиУ** (`docs/ingestion/INGESTION_ARCHITECTURE.md:5-32`). `MarketplaceAds` и `MarketplaceAnalytics` — отдельные потребители/потоки; их нельзя считать частью только seller finance (`site/src/MarketplaceAds/Facade/MarketplaceAdsFacade.php`, `site/src/MarketplaceAnalytics/Facade/MarketplaceAnalyticsFacade.php`).

## Карта компонентов

Переносимость означает технически возможную границу будущего сервиса, а не решение о переносе сейчас. «Да, fetch» требует совместимого контракта, shadow-проверки и cutover из [06](06-migration-roadmap.md).

| Класс / файл | Назначение и зависимости | Go | Риск переноса |
|---|---|---|---|
| `OzonAccrualByDayClient`, `site/src/Marketplace/Ozon/Infrastructure/Api/OzonAccrualByDayClient.php:47-159` | HTTP и пагинация `/v1/finance/accrual/by-day`; credentials из Marketplace | Да, fetch | rate limit, ревизии дней, полнота страниц |
| `OzonRealizationFetcher`, `site/src/Marketplace/Ozon/Infrastructure/Api/OzonRealizationFetcher.php:35-87` | Получение месячной «Реализации» | Да, fetch | пустой/ещё не готовый отчёт не равен нулевому отчёту |
| `SyncOzonAccrualByDayHandler`, `site/src/Marketplace/Ozon/MessageHandler/SyncOzonAccrualByDayHandler.php:74-226` | Выбор соединения, fetch, запись `MarketplaceRawDocument`, постановка `ProcessDayReportMessage` | Разделить | статус/запись/dispatch должны иметь восстановимый handoff |
| `OzonAccrualSyncPlanner`, `site/src/Marketplace/Ozon/Application/Service/OzonAccrualSyncPlanner.php` | Дневное окно и план сообщений | Да, с сохранением политики | окно с `EARLIEST_SAFE_DAY` нельзя расширить и удвоить legacy-v3 |
| `OzonAccrual*RawProcessor`, `site/src/Marketplace/Ozon/Application/Processor/` | sale/return/cost извлечение в общие `Marketplace*` Entity | Только source parser | суммы, ключи, исправления, связи с закрытым периодом — ответственность Symfony |
| `RunOzonReconciliationAction`, `site/src/Marketplace/Ozon/Application/Action/RunOzonReconciliationAction.php` | Сравнение realization/raw/ledger | Нет | проверяет именно финансовый результат в БД Symfony |
| `WbFinanceSalesReportClient`, `site/src/Marketplace/Wildberries/Infrastructure/Api/WbFinanceSalesReportClient.php:39-181` | HTTP, `rrd_id`, rate limit/cooldown | Да, fetch | курсор, 429, общая квота seller bucket |
| `SyncWbFinancialReportDayHandler`, `site/src/Marketplace/Wildberries/MessageHandler/SyncWbFinancialReportDayHandler.php:62-318` | Статус дня, загрузка страниц, raw, следующий `ProcessDayReportMessage` | Разделить | продолжения сообщений и повтор страницы требуют стабильной идентичности |
| `WbSalesReportRowNormalizer`, `site/src/Marketplace/Wildberries/Infrastructure/Normalizer/WbSalesReportRowNormalizer.php` | Разбор полей WB | Да как versioned parser | меняет смысл сумм/возвратов, нужна сверка с Symfony |
| `WbCostsRawProcessor`, `site/src/Marketplace/Wildberries/Application/Processor/WbCostsRawProcessor.php` и `CostCalculator/` | Категории комиссий/логистики, запись затрат | Нет для posting; parser возможен | финансовые знаки, маппинги компаний, replay |
| `WbRawFinancialReportBuilder`, `site/src/Marketplace/Wildberries/Application/FinancialReport/WbRawFinancialReportBuilder.php:161-422` | Агрегированное представление финансового отчёта | Не на первом этапе | повторная реализация правил даст расхождения UI/учёта |
| `ProcessMarketplaceRawDocumentAction`, `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php:59-250` | Общий routing classifier/processor; sales/returns/costs, частичная замена | Нет как целое | совмещает формат источника, финансовые строки и транзакционную замену |
| `MarketplaceRawDocument`, `site/src/Marketplace/Entity/MarketplaceRawDocument.php` и `MarketplaceRawDocumentRepository` | Legacy raw JSON, статус/метаданные | До cutover Symfony | существующие документы и replay; удаление разрушит совместимость |
| `MarketplaceSale`, `MarketplaceReturn`, `MarketplaceCost`, `site/src/Marketplace/Entity/` | Финансовые строки для закрытия месяца | Нет | единственный legacy-владелец учётных строк, company scope и уникальность |
| `MarketplaceFacade`, `site/src/Marketplace/Facade/MarketplaceFacade.php:35-681` | Контракты подключения, listing, sales/returns/costs другим модулям | Нет | широкий публичный межмодульный контракт |
| `CloseMonthStageAction`, `site/src/Marketplace/Application/CloseMonthStageAction.php:44-106` | Preflight, PL Document, привязка строк, целостность | Нет | одна финансовая транзакция и блокировка по периоду |
| `FinanceFacade`, `site/src/Finance/Facade/FinanceFacade.php` | Создание и изменение Finance P&L | Нет | граница финансового владельца |
| `Ingestion` connectors/mappers, `site/src/Ingestion/Application/Source/{Ozon,Wildberries}/` | Новый raw → каноническая `FinancialTransaction` и order pipeline | Fetch/parser частично | нельзя создать второй финансовый producer или обойти существующий roadmap |
| `RawStorageFacade`, `site/src/Ingestion/Facade/RawStorageFacade.php` | Единая запись/чтение Ingestion raw | Возможно за контрактом | legacy raw хранится отдельно; миграция ссылок и replay |

## Сценарии и связи

- Ozon seller: `SyncConnectionAction` и cron через `OzonAccrualSyncPlanner` создают дневные сообщения; обработчик fetch сохраняет raw и запускает дневную обработку (`site/src/Marketplace/Application/SyncConnectionAction.php:43-90`; `site/src/Marketplace/Ozon/MessageHandler/SyncOzonAccrualByDayHandler.php:98-226`). Отдельный месячный путь realization poll/process и сверка (`ARCHITECTURE.md:438-455`). Старый v3 API удалён, но обработчики сохранённых v3 raw документов ещё нужны (`ARCHITECTURE.md:393-398`).
- WB seller: `WbFinancialReportSyncPlanner` планирует дни, `SyncWbFinancialReportDayHandler` сохраняет постраничный raw, затем дневные sales/returns/costs (`site/src/Marketplace/Wildberries/Application/FinancialReport/WbFinancialReportSyncPlanner.php`; `site/src/Marketplace/Wildberries/MessageHandler/SyncWbFinancialReportDayHandler.php:90-318`). WB orders отдельно идут в Ingestion (`site/src/Ingestion/Application/Source/Wildberries/WbOrdersConnector.php`).
- Каталог товаров и barcode — отдельный поток через `OzonProductCatalogClient`, `WbProductCardsClient`, `Refresh*ListingCatalogAction`; он влияет на атрибуцию listing, но не должен сам posting-овать деньги (`site/src/Marketplace/Ozon/Infrastructure/Api/OzonProductCatalogClient.php:41-124`; `site/src/Marketplace/Wildberries/Infrastructure/Api/WbProductCardsClient.php:29-77`).
- Закрытие месяца читает `SalesReturnsDataSource`, `CostsDataSource`, `RealizationDataSource`, создаёт PL Document через `FinanceFacade` (`site/src/Marketplace/Application/CloseMonthStageAction.php:47-55`; `site/src/Marketplace/Application/Source/`).
- Ads и Analytics используют собственные raw/расчётные сущности и очереди; при планировании ёмкости их нужно учитывать, но смена seller fetch не означает их миграцию (`site/src/MarketplaceAds/Application/ProcessAdRawDocumentAction.php:51-244`; `site/src/MarketplaceAnalytics/MessageHandler/RecalcSnapshotsMessageHandler.php`).

## Где смешаны ответственности

`ProcessMarketplaceRawDocumentAction` одновременно распознаёт формат Ozon/WB, удаляет/заменяет строки и выбирает финансовые processors (`site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php:86-145,151-250`). `SyncWbFinancialReportDayHandler` одновременно работает с API, курсором, JSON raw, status и dispatch (`site/src/Marketplace/Wildberries/MessageHandler/SyncWbFinancialReportDayHandler.php:90-318`). `CloseMonthStageAction` — правильная граница Symfony Financial Core; вынос её в Go нарушил бы локальную транзакцию с Finance (`site/src/Marketplace/Application/CloseMonthStageAction.php:68-104,106-400`).
