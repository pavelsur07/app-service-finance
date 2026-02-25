<?php

namespace App\Marketplace\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Infrastructure\ProductRepository;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Marketplace\Service\CostCalculator\CostCalculatorInterface;
use App\Marketplace\Service\CostCalculator\WbAcquiringCalculator;
use App\Marketplace\Service\CostCalculator\WbCommissionCalculator;
use App\Marketplace\Service\CostCalculator\WbDeductionCalculator;
use App\Marketplace\Service\CostCalculator\WbLogisticsDeliveryCalculator;
use App\Marketplace\Service\CostCalculator\WbLogisticsReturnCalculator;
use App\Marketplace\Service\CostCalculator\WbLoyaltyDiscountCalculator;
use App\Marketplace\Service\CostCalculator\WbPenaltyCalculator;
use App\Marketplace\Service\CostCalculator\WbProductProcessingCalculator;
use App\Marketplace\Service\CostCalculator\WbPvzProcessingCalculator;
use App\Marketplace\Service\CostCalculator\WbStorageCalculator;
use App\Marketplace\Service\CostCalculator\WbWarehouseLogisticsCalculator;
use App\Marketplace\Service\Integration\MarketplaceAdapterInterface;
use App\Shared\Service\SlugifyService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MarketplaceSyncService
{
    /** @var CostCalculatorInterface[] */
    private array $costCalculators;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $productRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceCostCategoryRepository $costCategoryRepository,
        private readonly MarketplaceCostRepository $costRepository,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly LoggerInterface $logger,
        private readonly SlugifyService $slugify
    ) {
        // Регистрируем калькуляторы затрат
        $this->costCalculators = [
            new WbCommissionCalculator(),
            new WbAcquiringCalculator(),
            new WbLogisticsDeliveryCalculator(),
            new WbLogisticsReturnCalculator(),
            new WbStorageCalculator(),
            new WbPvzProcessingCalculator(),
            new WbWarehouseLogisticsCalculator(),
            new WbPenaltyCalculator(),
            new WbProductProcessingCalculator(),
            new WbDeductionCalculator($this->slugify),
            new WbLoyaltyDiscountCalculator(),
        ];
    }

    public function processSalesFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $companyId = $company->getId();
        $synced = 0;
        $batchSize = 250; // Уменьшено для 512MB лимита

        // --- ФАЗА 1: ПОДГОТОВКА И ПРЕДЗАГРУЗКА ---

        // 1. Фильтруем только продажи с положительной суммой
        $salesData = array_filter($rawData, function($item) {
            return ($item['doc_type_name'] ?? '') === 'Продажа'
                && (float)($item['retail_amount'] ?? 0) > 0;
        });

        if (empty($salesData)) {
            $this->logger->info('No sales to process');
            return 0;
        }

        $this->logger->info('Starting bulk sales processing', [
            'total_filtered' => count($salesData),
            'batch_size' => $batchSize
        ]);

        // 2. Собираем все SRID для массовой проверки
        $allSrids = array_column($salesData, 'srid');

        // 3. Массово получаем существующие продажи (ОДИН запрос вместо тысяч!)
        $existingSridsMap = $this->saleRepository->getExistingExternalIds($companyId, $allSrids);

        $this->logger->info('Loaded existing sales', [
            'existing_count' => count($existingSridsMap)
        ]);

        // 4. Собираем все уникальные nm_id
        $allNmIds = array_values(array_unique(array_column($salesData, 'nm_id')));

        // 5. Массово получаем листинги (ОДИН запрос вместо тысяч!)
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
            $allNmIds
        );

        $this->logger->info('Loaded existing listings', [
            'listings_count' => count($listingsCache),
            'unique_nm_ids' => count($allNmIds)
        ]);

        // --- ФАЗА 2: СОЗДАНИЕ ОТСУТСТВУЮЩИХ ЛИСТИНГОВ ---
        $newListingsCreated = 0;

        foreach ($salesData as $item) {
            $nmId = (string)($item['nm_id'] ?? '');
            $tsName = trim($item['ts_name'] ?? '');
            $tsName = ($tsName === '') ? 'UNKNOWN' : $tsName;
            $cacheKey = $nmId . '_' . $tsName;

            if (!isset($listingsCache[$cacheKey])) {
                // Создаем в памяти, но пока НЕ делаем flush
                $listing = $this->createListingFromWbData($company, [
                    'nm_id' => $nmId,
                    'ts_name' => $tsName,
                    'sa_name' => $item['sa_name'],
                    'brand_name' => $item['brand_name'] ?? '',
                    'subject_name' => $item['subject_name'] ?? '',
                    'retail_price' => $item['retail_price'] ?? 0,
                ]);

                $listingsCache[$cacheKey] = $listing;
                $newListingsCreated++;
            }
        }

        // Сохраняем все новые листинги ОДНИМ flush до обработки продаж
        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('Created missing listings in bulk', [
                'new_listings' => $newListingsCreated
            ]);
        }

        // --- ФАЗА 3: ОБРАБОТКА ПРОДАЖ (ОСНОВНОЙ ЦИКЛ БЕЗ БД ЗАПРОСОВ) ---
        $counter = 0;

        foreach ($salesData as $item) {
            try {
                $externalOrderId = (string)$item['srid'];

                // Мгновенная проверка в памяти (НЕ БД!)
                if (isset($existingSridsMap[$externalOrderId])) {
                    continue;
                }

                $nmId = (string)($item['nm_id'] ?? '');
                $tsName = trim($item['ts_name'] ?? '');
                $tsName = ($tsName === '') ? 'UNKNOWN' : $tsName;
                $cacheKey = $nmId . '_' . $tsName;

                // Берем листинг из кэша (100% гарантия что есть - создали в Фазе 2)
                $listing = $listingsCache[$cacheKey] ?? null;

                if (!$listing) {
                    $this->logger->error('Listing missing from cache (logic error)', [
                        'nm_id' => $nmId,
                        'ts_name' => $tsName
                    ]);
                    continue;
                }

                // Создать Sale
                $sale = new MarketplaceSale(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    null,
                    \App\Marketplace\Enum\MarketplaceType::WILDBERRIES
                );

                $sale->setExternalOrderId($externalOrderId);
                $sale->setSaleDate(new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']));
                $sale->setQuantity(abs((int)$item['quantity']));
                $sale->setPricePerUnit((string)$item['retail_price']);
                $sale->setTotalRevenue((string)abs((float)$item['retail_amount']));
                $sale->setRawDocumentId($rawDoc->getId());

                $this->em->persist($sale);
                $existingSridsMap[$externalOrderId] = true; // Защита от дублей внутри файла

                $synced++;
                $counter++;

                // Batch flush каждые 500 записей
                if ($counter % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();

                    // Восстанавливаем Company
                    $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);

                    // Восстанавливаем Listings через getReference (оптимизация памяти)
                    foreach ($listingsCache as $k => $cachedListing) {
                        $listingsCache[$k] = $this->em->getReference(
                            \App\Marketplace\Entity\MarketplaceListing::class,
                            $cachedListing->getId()
                        );
                    }

                    gc_collect_cycles();

                    $this->logger->info('Sales batch processed', [
                        'processed' => $counter,
                        'synced' => $synced,
                        'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                    ]);
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to process sale item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Финальный flush для остатка
        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('Sales processing completed', [
            'total_synced' => $synced,
            'total_records' => count($rawData),
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
        ]);

        return $synced;
    }

    public function processReturnsFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $companyId = $company->getId();
        $synced = 0;
        $batchSize = 250; // Уменьшено для 512MB лимита

        // --- ФАЗА 1: ПОДГОТОВКА И ПРЕДЗАГРУЗКА ---

        // 1. Фильтруем только возвраты с положительной ценой
        $returnsData = array_filter($rawData, function($item) {
            $docType = $item['doc_type_name'] ?? '';
            return in_array($docType, ['Возврат', 'возврат', 'Return'])
                && (float)($item['retail_price'] ?? 0) > 0;
        });

        if (empty($returnsData)) {
            $this->logger->info('No returns to process');
            return 0;
        }

        $this->logger->info('Starting bulk returns processing', [
            'total_filtered' => count($returnsData),
            'batch_size' => $batchSize
        ]);

        // 2. Собираем все SRID для массовой проверки
        $allSrids = array_column($returnsData, 'srid');

        // 3. Массово получаем существующие возвраты (ОДИН запрос!)
        $existingSridsMap = $this->returnRepository->getExistingExternalIds($companyId, $allSrids);

        $this->logger->info('Loaded existing returns', [
            'existing_count' => count($existingSridsMap)
        ]);

        // 4. Собираем все уникальные nm_id
        $allNmIds = array_values(array_unique(array_column($returnsData, 'nm_id')));

        // 5. Массово получаем листинги (ОДИН запрос!)
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
            $allNmIds
        );

        $this->logger->info('Loaded existing listings', [
            'listings_count' => count($listingsCache),
            'unique_nm_ids' => count($allNmIds)
        ]);

        // --- ФАЗА 2: СОЗДАНИЕ ОТСУТСТВУЮЩИХ ЛИСТИНГОВ ---
        $newListingsCreated = 0;

        foreach ($returnsData as $item) {
            $nmId = (string)($item['nm_id'] ?? '');
            $tsName = trim($item['ts_name'] ?? '');
            $tsName = ($tsName === '') ? 'UNKNOWN' : $tsName;
            $cacheKey = $nmId . '_' . $tsName;

            if (!isset($listingsCache[$cacheKey])) {
                // Создаем в памяти, НЕ делаем flush
                $listing = $this->createListingFromWbData($company, [
                    'nm_id' => $nmId,
                    'ts_name' => $tsName,
                    'sa_name' => $item['sa_name'],
                    'brand_name' => $item['brand_name'] ?? '',
                    'subject_name' => $item['subject_name'] ?? '',
                    'retail_price' => $item['retail_price'] ?? 0,
                ]);

                $listingsCache[$cacheKey] = $listing;
                $newListingsCreated++;
            }
        }

        // Сохраняем все новые листинги ОДНИМ flush
        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('Created missing listings in bulk', [
                'new_listings' => $newListingsCreated
            ]);
        }

        // --- ФАЗА 3: ОБРАБОТКА ВОЗВРАТОВ (БЕЗ БД ЗАПРОСОВ) ---
        $counter = 0;

        foreach ($returnsData as $item) {
            try {
                $externalReturnId = (string)$item['srid'];

                // Мгновенная проверка в памяти
                if (isset($existingSridsMap[$externalReturnId])) {
                    continue;
                }

                $nmId = (string)($item['nm_id'] ?? '');
                $tsName = trim($item['ts_name'] ?? '');
                $tsName = ($tsName === '') ? 'UNKNOWN' : $tsName;
                $cacheKey = $nmId . '_' . $tsName;

                // Берем листинг из кэша (100% гарантия - создали в Фазе 2)
                $listing = $listingsCache[$cacheKey] ?? null;

                if (!$listing) {
                    $this->logger->error('Listing missing from cache (logic error)', [
                        'nm_id' => $nmId,
                        'ts_name' => $tsName
                    ]);
                    continue;
                }

                // Создать Return
                $return = new MarketplaceReturn(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    \App\Marketplace\Enum\MarketplaceType::WILDBERRIES
                );

                $return->setExternalReturnId($externalReturnId);
                $return->setReturnDate(new \DateTimeImmutable($item['rr_dt']));
                $return->setQuantity(abs((int)$item['quantity']));
                $return->setRefundAmount((string)$item['retail_price']);
                $return->setReturnReason($item['supplier_oper_name'] ?? '');

                $this->em->persist($return);
                $existingSridsMap[$externalReturnId] = true; // Защита от дублей внутри файла

                $synced++;
                $counter++;

                // Batch flush каждые 500 записей
                if ($counter % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();

                    // Восстанавливаем Company
                    $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);

                    // Восстанавливаем Listings через getReference
                    foreach ($listingsCache as $k => $cachedListing) {
                        $listingsCache[$k] = $this->em->getReference(
                            \App\Marketplace\Entity\MarketplaceListing::class,
                            $cachedListing->getId()
                        );
                    }

                    gc_collect_cycles();

                    $this->logger->info('Returns batch processed', [
                        'processed' => $counter,
                        'synced' => $synced,
                        'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                    ]);
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to process return item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Финальный flush для остатка
        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('Returns processing completed', [
            'total_synced' => $synced,
            'total_records' => count($rawData),
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
        ]);

        return $synced;
    }

    public function processCostsFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $companyId = $company->getId();
        $synced = 0;
        $unprocessedTypes = [];
        $batchSize = 100; // Маленький для 512MB лимита

        // DBAL для быстрых проверок
        $conn = $this->em->getConnection();

        // --- ФАЗА 1: ПРЕДЗАГРУЗКА (только легкие данные) ---

        // 1. Фильтруем только затраты
        $costsData = array_filter($rawData, function($item) {
            $docType = $item['doc_type_name'] ?? '';
            return !in_array($docType, ['Возврат', 'Продажа']);
        });

        if (empty($costsData)) {
            $this->logger->info('No costs to process');
            return 0;
        }

        $this->logger->info('Starting bulk costs processing', [
            'total_filtered' => count($costsData),
            'batch_size' => $batchSize
        ]);

        // 2. Массово загружаем listings (их немного)
        $allNmIds = array_values(array_unique(
            array_filter(array_column($costsData, 'nm_id'))
        ));

        $listingsCache = [];
        if (!empty($allNmIds)) {
            $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
                $company,
                \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                $allNmIds
            );

            $this->logger->info('Loaded listings', [
                'count' => count($listingsCache)
            ]);
        }

        // 3. Загружаем ВСЕ категории компании (их 10-30 штук)
        $categoriesCache = [];
        $allCategories = $this->costCategoryRepository->findBy([
            'company' => $company,
            'marketplace' => \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
            'deletedAt' => null
        ]);

        foreach ($allCategories as $cat) {
            $categoriesCache[$cat->getCode()] = $cat;
        }

        $this->logger->info('Loaded categories', [
            'count' => count($categoriesCache)
        ]);

        // --- ФАЗА 2: ОБРАБОТКА (ОДИН ПРОХОД) ---
        $counter = 0;
        $newCategoriesCreated = 0;

        foreach ($costsData as $item) {
            try {
                $processed = false;

                foreach ($this->costCalculators as $calculator) {
                    if (!$calculator->supports($item)) {
                        continue;
                    }

                    $processed = true;

                    // Получаем listing из кэша
                    $listing = null;

                    $nmId = (string)($item['nm_id'] ?? '');
                    $tsName = trim($item['ts_name'] ?? '');
                    $tsName = ($tsName === '') ? 'UNKNOWN' : $tsName;

                    if ($nmId !== '') {
                        $cacheKey = $nmId . '_' . $tsName;
                        $listing = $listingsCache[$cacheKey] ?? null;
                    }

                    if ($calculator->requiresListing() && !$listing) {
                        continue;
                    }

                    $calculatedCosts = $calculator->calculate($item, $listing);

                    foreach ($calculatedCosts as $costData) {
                        $externalId = $costData['external_id'];

                        // DBAL проверка дубликата (быстро, БЕЗ загрузки объекта)
                        $exists = $conn->fetchOne(
                            "SELECT 1 FROM marketplace_costs WHERE company_id = ? AND external_id = ? LIMIT 1",
                            [$companyId, $externalId]
                        );

                        if ($exists) {
                            continue;
                        }

                        $categoryCode = $costData['category_code'];
                        $category = $categoriesCache[$categoryCode] ?? null;

                        // Создаём категорию если её нет
                        if (!$category) {
                            $category = new \App\Marketplace\Entity\MarketplaceCostCategory(
                                Uuid::uuid4()->toString(),
                                $company,
                                \App\Marketplace\Enum\MarketplaceType::WILDBERRIES
                            );
                            $category->setCode($categoryCode);

                            $categoryName = $costData['category_name']
                                ?? $costData['description']
                                ?? $categoryCode;
                            $category->setName($categoryName);

                            $this->em->persist($category);
                            $categoriesCache[$categoryCode] = $category;
                            $newCategoriesCreated++;
                        }

                        // Создать Cost
                        $cost = new \App\Marketplace\Entity\MarketplaceCost(
                            Uuid::uuid4()->toString(),
                            $company,
                            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
                            $category
                        );

                        $cost->setExternalId($externalId);
                        $cost->setCostDate($costData['cost_date']);
                        $cost->setAmount($costData['amount']);
                        $cost->setDescription($costData['description']);

                        if ($listing) {
                            $cost->setListing($listing);
                        }

                        $this->em->persist($cost);
                        $synced++;
                        $counter++;

                        // Batch flush каждые 100 записей
                        if ($counter % $batchSize === 0) {
                            $this->em->flush();
                            $this->em->clear();

                            // Восстанавливаем Company
                            $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);

                            // Восстанавливаем Categories через getReference
                            foreach ($categoriesCache as $code => $cat) {
                                $categoriesCache[$code] = $this->em->getReference(
                                    \App\Marketplace\Entity\MarketplaceCostCategory::class,
                                    $cat->getId()
                                );
                            }

                            // Восстанавливаем Listings через getReference (если были)
                            foreach ($listingsCache as $k => $cachedListing) {
                                $listingsCache[$k] = $this->em->getReference(
                                    \App\Marketplace\Entity\MarketplaceListing::class,
                                    $cachedListing->getId()
                                );
                            }

                            gc_collect_cycles();

                            $this->logger->info('Costs batch processed', [
                                'processed' => $counter,
                                'synced' => $synced,
                                'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                            ]);
                        }
                    }
                }

                if (!$processed && isset($item['supplier_oper_name'])) {
                    $operName = (string)$item['supplier_oper_name'];
                    if (!isset($unprocessedTypes[$operName])) {
                        $unprocessedTypes[$operName] = 0;
                    }
                    $unprocessedTypes[$operName]++;
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to process cost item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Финальный flush для остатка
        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        // Сохраняем статистику
        $unprocessedCount = array_sum($unprocessedTypes);
        $rawDoc = $this->em->find(\App\Marketplace\Entity\MarketplaceRawDocument::class, $rawDoc->getId());

        if ($rawDoc) {
            $rawDoc->setUnprocessedCostsCount($unprocessedCount);
            $rawDoc->setUnprocessedCostTypes($unprocessedTypes ?: null);
            $this->em->flush();
        }

        $this->logger->info('Costs processing completed', [
            'total_synced' => $synced,
            'total_records' => count($rawData),
            'unprocessed_count' => $unprocessedCount,
            'new_categories_created' => $newCategoriesCreated,
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
        ]);

        return $synced;
    }

    public function syncSales(
        Company $company,
        MarketplaceAdapterInterface $adapter,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): int {
        // Получаем данные от API
        $salesData = $adapter->fetchSales($company, $fromDate, $toDate);

        // Создаём RawDocument
        $rawDoc = new \App\Marketplace\Entity\MarketplaceRawDocument(
            Uuid::uuid4()->toString(),
            $company,
            \App\Marketplace\Enum\MarketplaceType::from($adapter->getMarketplaceType()),
            'sales_report'
        );
        $rawDoc->setPeriodFrom(\DateTimeImmutable::createFromInterface($fromDate));
        $rawDoc->setPeriodTo(\DateTimeImmutable::createFromInterface($toDate));
        $rawDoc->setApiEndpoint($adapter->getMarketplaceType() . '::fetchSales');
        $rawDoc->setRawData(array_map(function($s) {
            return [
                'order_id' => $s->externalOrderId,
                'date' => $s->saleDate->format('Y-m-d'),
                'sku' => $s->marketplaceSku,
                'qty' => $s->quantity,
                'price' => $s->pricePerUnit,
                'revenue' => $s->totalRevenue,
            ];
        }, $salesData));
        $rawDoc->setRecordsCount(count($salesData));

        $this->em->persist($rawDoc);
        $this->em->flush();

        $rawDocId = $rawDoc->getId();
        $synced = 0;

        // Обрабатываем по одной
        foreach ($salesData as $saleData) {
            // Проверка дубликата
            $existing = $this->saleRepository->findByMarketplaceOrder(
                $company,
                $saleData->marketplace,
                $saleData->externalOrderId
            );

            if ($existing) {
                continue;
            }

            // Найти или создать Product и Listing
            $listing = $this->findOrCreateListing($company, $saleData);

            // Создать Sale
            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                null,
                $saleData->marketplace
            );

            $sale->setExternalOrderId($saleData->externalOrderId);
            $sale->setSaleDate($saleData->saleDate);
            $sale->setQuantity($saleData->quantity);
            $sale->setPricePerUnit($saleData->pricePerUnit);
            $sale->setTotalRevenue($saleData->totalRevenue);
            $sale->setRawDocumentId($rawDocId);

            $this->em->persist($sale);
            $synced++;
        }

        // Один flush в конце
        $this->em->flush();

        return $synced;
    }

    public function syncCosts(
        Company $company,
        MarketplaceAdapterInterface $adapter,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): int {
        $costsData = $adapter->fetchCosts($company, $fromDate, $toDate);
        $synced = 0;

        foreach ($costsData as $costData) {
            $category = $this->costCategoryRepository->findByCode(
                $company,
                $costData->marketplace,
                $costData->categoryCode
            );

            if (!$category) {
                continue;
            }

            $listing = null;
            if ($costData->marketplaceSku) {
                $listing = $this->listingRepository->findByMarketplaceSku(
                    $company,
                    $costData->marketplace,
                    $costData->marketplaceSku
                );
            }

            if ($costData->externalId) {
                $existing = $this->em->getRepository(MarketplaceCost::class)
                    ->findOneBy(['company' => $company, 'externalId' => $costData->externalId]);

                if ($existing) {
                    continue;
                }
            }

            $cost = new MarketplaceCost(
                Uuid::uuid4()->toString(),
                $company,
                $costData->marketplace,
                $category
            );

            $cost->setListing($listing);
            $cost->setAmount($costData->amount);
            $cost->setCostDate($costData->costDate);
            $cost->setDescription($costData->description);
            $cost->setExternalId($costData->externalId);

            $this->em->persist($cost);
            $synced++;
        }

        $this->em->flush();
        return $synced;
    }

    public function syncReturns(
        Company $company,
        MarketplaceAdapterInterface $adapter,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): int {
        $returnsData = $adapter->fetchReturns($company, $fromDate, $toDate);
        $synced = 0;

        foreach ($returnsData as $returnData) {
            $listing = $this->findOrCreateListing($company, $returnData);

            if ($returnData->externalReturnId) {
                $existing = $this->returnRepository->findOneBy([
                    'company' => $company,
                    'externalReturnId' => $returnData->externalReturnId
                ]);

                if ($existing) {
                    continue;
                }
            }

            $return = new MarketplaceReturn(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                $returnData->marketplace
            );

            $return->setExternalReturnId($returnData->externalReturnId);
            $return->setReturnDate($returnData->returnDate);
            $return->setQuantity($returnData->quantity);
            $return->setRefundAmount($returnData->refundAmount);
            $return->setReturnReason($returnData->returnReason);

            $this->em->persist($return);
            $synced++;
        }

        $this->em->flush();
        return $synced;
    }

    private function createListingFromWbData(Company $company, array $wbData): MarketplaceListing
    {
        $nmId = $wbData['nm_id'];
        $tsName = $wbData['ts_name'];
        $saName = $wbData['sa_name'];
        $brandName = $wbData['brand_name'];
        $subjectName = $wbData['subject_name'];
        $price = $wbData['retail_price'];

        // Формируем название: {brand} {subject} {sa_name} {ts_name если есть}
        // Не включаем 'UNKNOWN' в название
        $nameParts = array_filter([
            $brandName,
            $subjectName,
            $saName,
            ($tsName !== 'UNKNOWN') ? $tsName : null
        ]);
        $productName = implode(' ', $nameParts);


        // Создаём Listing
        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $company,
            null,
            MarketplaceType::WILDBERRIES
        );
        $listing->setMarketplaceSku($nmId);           // nm_id
        $listing->setSupplierSku($saName);            // sa_name
        $listing->setSize($tsName);                   // ts_name (может быть null)
        $listing->setPrice((string)$price);
        $listing->setName($productName);

        $this->em->persist($listing);

        return $listing;
    }

    private function findOrCreateListing(Company $company, $saleData): MarketplaceListing
    {
        $listing = $this->listingRepository->findByMarketplaceSku(
            $company,
            $saleData->marketplace,
            $saleData->marketplaceSku
        );

        if ($listing) {
            return $listing;
        }

        // Создаём Listing без Product — продукт привязывается вручную
        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $company,
            null,
            $saleData->marketplace
        );
        $listing->setMarketplaceSku($saleData->marketplaceSku);
        $listing->setPrice($saleData->pricePerUnit);

        $this->em->persist($listing);

        return $listing;
    }
}
