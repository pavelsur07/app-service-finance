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
use Doctrine\DBAL\ArrayParameterType;
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
        // Диспатч по маркетплейсу
        if ($rawDoc->getMarketplace() === MarketplaceType::OZON) {
            return $this->processOzonSalesFromRaw($company, $rawDoc);
        }

        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
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
            $tsName = $this->normalizeWbSize($item['ts_name'] ?? null);
            $cacheKey = $this->wbListingCacheKey($nmId, $tsName);

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
                $tsName = $this->normalizeWbSize($item['ts_name'] ?? null);
                $cacheKey = $this->wbListingCacheKey($nmId, $tsName);

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
                $sale->setRawDocumentId($rawDocId);

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
        // Диспатч по маркетплейсу
        if ($rawDoc->getMarketplace() === MarketplaceType::OZON) {
            return $this->processOzonReturnsFromRaw($company, $rawDoc);
        }

        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
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
            $tsName = $this->normalizeWbSize($item['ts_name'] ?? null);
            $cacheKey = $this->wbListingCacheKey($nmId, $tsName);

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
                $tsName = $this->normalizeWbSize($item['ts_name'] ?? null);
                $cacheKey = $this->wbListingCacheKey($nmId, $tsName);

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
        // Диспатч по маркетплейсу
        if ($rawDoc->getMarketplace() === MarketplaceType::OZON) {
            return $this->processOzonCostsFromRaw($company, $rawDoc);
        }

        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
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
        $lastFlushedCounter = 0;
        $knownExternalIdsMap = []; // [externalId => true]
        $pending = []; // ['external_id' => string, 'costData' => array, 'listing' => ?MarketplaceListing]
        $pendingIds = [];
        $dedupBatchSize = $batchSize;

        $processPendingBatch = function () use (
            &$pending,
            &$pendingIds,
            &$knownExternalIdsMap,
            &$counter,
            &$synced,
            &$newCategoriesCreated,
            &$lastFlushedCounter,
            &$categoriesCache,
            &$listingsCache,
            &$company,
            $companyId,
            $conn,
            $batchSize
        ): void {
            if (empty($pendingIds)) {
                return;
            }

            $placeholders = implode(',', array_fill(0, count($pendingIds), '?'));
            $dbExistingIds = $conn->fetchFirstColumn(
                "SELECT external_id FROM marketplace_costs WHERE company_id = ? AND external_id IN ($placeholders)",
                array_merge([$companyId], $pendingIds)
            );

            $dbExistingMap = [];
            foreach ($dbExistingIds as $existingId) {
                $dbExistingMap[(string) $existingId] = true;
            }

            $knownExternalIdsMap = $knownExternalIdsMap + $dbExistingMap;

            foreach ($pending as $pendingItem) {
                $externalId = $pendingItem['external_id'];
                if (isset($knownExternalIdsMap[$externalId])) {
                    continue;
                }

                $costData = $pendingItem['costData'];
                $listing = $pendingItem['listing'];

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
                $knownExternalIdsMap[$externalId] = true;
                $synced++;
                $counter++;
            }

            if (($counter - $lastFlushedCounter) >= $batchSize) {
                $this->em->flush();
                $this->em->clear();
                $lastFlushedCounter = $counter;

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

            $pending = [];
            $pendingIds = [];
        };

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
                    $tsName = $this->normalizeWbSize($item['ts_name'] ?? null);

                    if ($nmId !== '') {
                        $cacheKey = $this->wbListingCacheKey($nmId, $tsName);
                        $listing = $listingsCache[$cacheKey] ?? null;
                    }

                    if ($calculator->requiresListing() && !$listing) {
                        continue;
                    }

                    $calculatedCosts = $calculator->calculate($item, $listing);

                    foreach ($calculatedCosts as $costData) {
                        $externalId = $costData['external_id'];

                        if (isset($knownExternalIdsMap[$externalId])) {
                            continue;
                        }

                        $pending[] = [
                            'external_id' => $externalId,
                            'costData' => $costData,
                            'listing' => $listing,
                        ];
                        $pendingIds[] = $externalId;

                        if (count($pendingIds) >= $dedupBatchSize) {
                            $processPendingBatch();
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

        if (!empty($pendingIds)) {
            $processPendingBatch();
        }

        // Финальный flush для остатка
        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        // Сохраняем статистику
        $unprocessedCount = array_sum($unprocessedTypes);
        $rawDoc = $this->em->find(\App\Marketplace\Entity\MarketplaceRawDocument::class, $rawDocId);

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

        $companyId = (string)$company->getId();
        $marketplace = MarketplaceType::from($adapter->getMarketplaceType());
        $rawDocId = $rawDoc->getId();
        $synced = 0;

        $allExternalIds = array_map(fn($s) => (string)$s->externalOrderId, $salesData);
        $existingMap = $this->saleRepository->getExistingExternalIds($companyId, $allExternalIds);

        $allSkus = [];
        foreach ($salesData as $saleData) {
            $sku = (string)$saleData->marketplaceSku;
            if ($sku !== '') {
                $allSkus[$sku] = true;
            }
        }
        $allSkus = array_keys($allSkus);

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed($company, $marketplace, $allSkus);

        $newListingsCreated = 0;
        foreach ($salesData as $saleData) {
            $sku = (string)$saleData->marketplaceSku;

            if ($sku === '' || isset($listingsCache[$sku])) {
                continue;
            }

            $listing = new MarketplaceListing(
                Uuid::uuid4()->toString(),
                $company,
                null,
                $marketplace
            );
            $listing->setMarketplaceSku($sku);
            $listing->setPrice($saleData->pricePerUnit);

            $this->em->persist($listing);
            $listingsCache[$sku] = $listing;
            $newListingsCreated++;
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
        }

        // Обрабатываем по одной
        foreach ($salesData as $saleData) {
            $externalId = (string)$saleData->externalOrderId;

            if (isset($existingMap[$externalId])) {
                continue;
            }

            $sku = (string)$saleData->marketplaceSku;
            $listing = $sku !== '' ? ($listingsCache[$sku] ?? null) : null;

            if (!$listing) {
                // Fallback для пустого SKU/непредвиденных кейсов: сохраняем текущую семантику.
                $listing = $this->findOrCreateListing($company, $saleData);
                if ($sku !== '') {
                    $listingsCache[$sku] = $listing;
                }
            }

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
            $existingMap[$externalId] = true;
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
        $companyId = (string)$company->getId();
        $marketplace = MarketplaceType::from($adapter->getMarketplaceType());
        $synced = 0;

        $categoryCodesMap = [];
        $skusMap = [];
        $externalIdsMap = [];

        foreach ($costsData as $costData) {
            $categoryCode = trim((string)$costData->categoryCode);
            if ($categoryCode !== '') {
                $categoryCodesMap[$categoryCode] = true;
            }

            $sku = trim((string)$costData->marketplaceSku);
            if ($sku !== '') {
                $skusMap[$sku] = true;
            }

            $externalId = trim((string)$costData->externalId);
            if ($externalId !== '') {
                $externalIdsMap[$externalId] = true;
            }
        }

        $categoryCodes = array_keys($categoryCodesMap);
        $skus = array_keys($skusMap);
        $externalIds = array_keys($externalIdsMap);

        $categoriesMap = $this->costCategoryRepository->findByCodesIndexed($company, $marketplace, $categoryCodes);
        $listingsMap = $this->listingRepository->findListingsBySkusIndexed($company, $marketplace, $skus);

        $existingExternalIdsMap = [];
        if ($externalIds !== []) {
            $connection = $this->em->getConnection();

            foreach (array_chunk($externalIds, 1000) as $externalIdsChunk) {
                $rows = $connection->executeQuery(
                    'SELECT external_id FROM marketplace_costs WHERE company_id = :companyId AND external_id IN (:externalIds)',
                    [
                        'companyId' => $companyId,
                        'externalIds' => $externalIdsChunk,
                    ],
                    [
                        'externalIds' => ArrayParameterType::STRING,
                    ]
                )->fetchFirstColumn();

                foreach ($rows as $existingExternalId) {
                    $existingExternalIdsMap[(string)$existingExternalId] = true;
                }
            }
        }

        foreach ($costsData as $costData) {
            $categoryCode = trim((string)$costData->categoryCode);
            $category = $categoriesMap[$categoryCode] ?? null;

            if (!$category) {
                continue;
            }

            $listing = null;
            if ($costData->marketplaceSku) {
                $sku = trim((string)$costData->marketplaceSku);
                $listing = $listingsMap[$sku] ?? null;
            }

            $externalId = trim((string)$costData->externalId);
            if ($externalId !== '' && isset($existingExternalIdsMap[$externalId])) {
                continue;
            }

            $cost = new MarketplaceCost(
                Uuid::uuid4()->toString(),
                $company,
                $marketplace,
                $category
            );

            $cost->setListing($listing);
            $cost->setAmount($costData->amount);
            $cost->setCostDate($costData->costDate);
            $cost->setDescription($costData->description);
            $cost->setExternalId($costData->externalId);

            $this->em->persist($cost);
            if ($externalId !== '') {
                $existingExternalIdsMap[$externalId] = true;
            }
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
        $companyId = (string)$company->getId();
        $marketplace = MarketplaceType::from($adapter->getMarketplaceType());
        $synced = 0;

        $allExternalReturnIds = [];
        foreach ($returnsData as $returnData) {
            $externalReturnId = trim((string)$returnData->externalReturnId);
            if ($externalReturnId !== '') {
                $allExternalReturnIds[$externalReturnId] = true;
            }
        }
        $allExternalReturnIds = array_keys($allExternalReturnIds);
        $existingMap = $this->returnRepository->getExistingExternalIds($companyId, $allExternalReturnIds);

        $allSkus = [];
        foreach ($returnsData as $returnData) {
            $sku = trim((string)$returnData->marketplaceSku);
            if ($sku !== '') {
                $allSkus[$sku] = true;
            }
        }
        $allSkus = array_keys($allSkus);

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed($company, $marketplace, $allSkus);

        $newListingsCreated = 0;
        foreach ($returnsData as $returnData) {
            $sku = trim((string)$returnData->marketplaceSku);

            if ($sku === '' || isset($listingsCache[$sku])) {
                continue;
            }

            $listing = new MarketplaceListing(
                Uuid::uuid4()->toString(),
                $company,
                null,
                $marketplace
            );
            $listing->setMarketplaceSku($sku);
            $listing->setPrice($returnData->refundAmount);

            $this->em->persist($listing);
            $listingsCache[$sku] = $listing;
            $newListingsCreated++;
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
        }

        foreach ($returnsData as $returnData) {
            $externalReturnId = trim((string)$returnData->externalReturnId);

            if ($externalReturnId !== '' && isset($existingMap[$externalReturnId])) {
                continue;
            }

            $sku = trim((string)$returnData->marketplaceSku);
            $listing = $sku !== '' ? ($listingsCache[$sku] ?? null) : null;

            if (!$listing) {
                // Fallback для пустого SKU/непредвиденных кейсов: сохраняем текущую семантику.
                $listing = $this->findOrCreateListing($company, $returnData);
                if ($sku !== '') {
                    $listingsCache[$sku] = $listing;
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

            if ($externalReturnId !== '') {
                $existingMap[$externalReturnId] = true;
            }

            $synced++;
        }

        $this->em->flush();
        return $synced;
    }

    // ========================================================================
    // OZON: Обработка сырых данных из /v3/finance/transaction/list
    // ========================================================================

    /**
     * Ozon: обработка продаж из RawDocument.
     *
     * Структура Ozon: operations[].type === "orders" && accruals_for_sale > 0
     * items[]: [{name, sku}], posting: {posting_number}
     */
    private function processOzonSalesFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
        $synced = 0;
        $batchSize = 250;

        // --- ФАЗА 1: Фильтрация продаж ---
        $salesData = array_filter($rawData, function ($op) {
            return ($op['type'] ?? '') === 'orders'
                && (float)($op['accruals_for_sale'] ?? 0) > 0;
        });

        if (empty($salesData)) {
            $this->logger->info('[Ozon] No sales to process');
            return 0;
        }

        $this->logger->info('[Ozon] Starting sales processing', [
            'total_filtered' => count($salesData),
        ]);

        // --- ФАЗА 2: Предзагрузка ---

        // Собираем externalIds (operation_id) для проверки дублей
        $allExternalIds = array_map(fn($op) => (string)$op['operation_id'], $salesData);
        $existingIdsMap = $this->saleRepository->getExistingExternalIds($companyId, $allExternalIds);

        // Собираем все SKU для листингов
        $allSkus = [];
        foreach ($salesData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string)($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }
        $allSkus = array_keys($allSkus);

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            $allSkus
        );

        // --- ФАЗА 3: Создание отсутствующих листингов ---
        $newListingsCreated = 0;

        foreach ($salesData as $op) {
            $price = (float)($op['accruals_for_sale'] ?? 0);

            foreach ($op['items'] ?? [] as $item) {
                $sku = (string)($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $listing = $this->createListingFromOzonData($company, [
                    'sku' => $sku,
                    'name' => $item['name'] ?? '',
                    'price' => $price,
                ]);

                $listingsCache[$sku] = $listing;
                $newListingsCreated++;
            }
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('[Ozon] Created missing listings', ['count' => $newListingsCreated]);
        }

        // --- ФАЗА 4: Обработка продаж ---
        $counter = 0;

        foreach ($salesData as $op) {
            try {
                $externalOrderId = (string)$op['operation_id'];

                if (isset($existingIdsMap[$externalOrderId])) {
                    continue;
                }

                $items = $op['items'] ?? [];
                $firstItem = $items[0] ?? null;
                $sku = $firstItem ? (string)($firstItem['sku'] ?? '') : '';

                $listing = $listingsCache[$sku] ?? null;
                if (!$listing) {
                    $this->logger->warning('[Ozon] No listing for sale', ['sku' => $sku]);
                    continue;
                }

                $accrual = (float)($op['accruals_for_sale'] ?? 0);
                $quantity = count($items) > 0 ? 1 : 0; // Ozon: 1 операция = 1 единица

                $sale = new MarketplaceSale(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    null,
                    MarketplaceType::OZON
                );

                $sale->setExternalOrderId($externalOrderId);
                $sale->setSaleDate(new \DateTimeImmutable($op['operation_date']));
                $sale->setQuantity($quantity);
                $sale->setPricePerUnit((string)$accrual);
                $sale->setTotalRevenue((string)abs($accrual));
                $sale->setRawDocumentId($rawDocId);

                $this->em->persist($sale);
                $existingIdsMap[$externalOrderId] = true;

                $synced++;
                $counter++;

                if ($counter % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();

                    $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);
                    foreach ($listingsCache as $k => $cachedListing) {
                        $listingsCache[$k] = $this->em->getReference(
                            MarketplaceListing::class,
                            $cachedListing->getId()
                        );
                    }

                    gc_collect_cycles();
                    $this->logger->info('[Ozon] Sales batch', ['processed' => $counter, 'synced' => $synced]);
                }

            } catch (\Exception $e) {
                $this->logger->error('[Ozon] Failed to process sale', [
                    'operation_id' => $op['operation_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('[Ozon] Sales processing completed', ['total_synced' => $synced]);
        return $synced;
    }

    /**
     * Ozon: обработка возвратов из RawDocument.
     *
     * Структура Ozon: operations[].type === "returns"
     */
    private function processOzonReturnsFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
        $synced = 0;
        $batchSize = 250;

        // --- ФАЗА 1: Фильтрация возвратов ---
        $returnsData = array_filter($rawData, function ($op) {
            return ($op['type'] ?? '') === 'returns';
        });

        if (empty($returnsData)) {
            $this->logger->info('[Ozon] No returns to process');
            return 0;
        }

        $this->logger->info('[Ozon] Starting returns processing', [
            'total_filtered' => count($returnsData),
        ]);

        // --- ФАЗА 2: Предзагрузка ---
        $allExternalIds = array_map(fn($op) => (string)$op['operation_id'], $returnsData);
        $existingIdsMap = $this->returnRepository->getExistingExternalIds($companyId, $allExternalIds);

        $allSkus = [];
        foreach ($returnsData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string)($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }
        $allSkus = array_keys($allSkus);

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            $allSkus
        );

        // --- ФАЗА 3: Создание отсутствующих листингов ---
        $newListingsCreated = 0;

        foreach ($returnsData as $op) {
            $price = abs((float)($op['amount'] ?? 0));

            foreach ($op['items'] ?? [] as $item) {
                $sku = (string)($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $listing = $this->createListingFromOzonData($company, [
                    'sku' => $sku,
                    'name' => $item['name'] ?? '',
                    'price' => $price,
                ]);

                $listingsCache[$sku] = $listing;
                $newListingsCreated++;
            }
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('[Ozon] Created missing listings for returns', ['count' => $newListingsCreated]);
        }

        // --- ФАЗА 4: Обработка возвратов ---
        $counter = 0;

        foreach ($returnsData as $op) {
            try {
                $externalReturnId = (string)$op['operation_id'];

                if (isset($existingIdsMap[$externalReturnId])) {
                    continue;
                }

                $items = $op['items'] ?? [];
                $firstItem = $items[0] ?? null;
                $sku = $firstItem ? (string)($firstItem['sku'] ?? '') : '';

                $listing = $listingsCache[$sku] ?? null;
                if (!$listing) {
                    $this->logger->warning('[Ozon] No listing for return', ['sku' => $sku]);
                    continue;
                }

                $amount = abs((float)($op['amount'] ?? 0));
                $returnDeliveryCharge = abs((float)($op['return_delivery_charge'] ?? 0));

                $return = new MarketplaceReturn(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    MarketplaceType::OZON
                );

                $return->setExternalReturnId($externalReturnId);
                $return->setReturnDate(new \DateTimeImmutable($op['operation_date']));
                $return->setQuantity(1);
                $return->setRefundAmount((string)$amount);
                $return->setReturnReason($op['operation_type_name'] ?? null);

                $this->em->persist($return);
                $existingIdsMap[$externalReturnId] = true;

                $synced++;
                $counter++;

                if ($counter % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();

                    $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);
                    foreach ($listingsCache as $k => $cachedListing) {
                        $listingsCache[$k] = $this->em->getReference(
                            MarketplaceListing::class,
                            $cachedListing->getId()
                        );
                    }

                    gc_collect_cycles();
                    $this->logger->info('[Ozon] Returns batch', ['processed' => $counter, 'synced' => $synced]);
                }

            } catch (\Exception $e) {
                $this->logger->error('[Ozon] Failed to process return', [
                    'operation_id' => $op['operation_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('[Ozon] Returns processing completed', ['total_synced' => $synced]);
        return $synced;
    }

    /**
     * Ozon: обработка затрат из RawDocument.
     *
     * Извлекает: sale_commission, delivery_charge, return_delivery_charge, services[].
     * Каждая операция может порождать несколько записей затрат.
     */
    private function processOzonCostsFromRaw(
        Company $company,
        \App\Marketplace\Entity\MarketplaceRawDocument $rawDoc
    ): int {
        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
        $conn = $this->em->getConnection();
        $synced = 0;
        $batchSize = 100;
        $unprocessedTypes = [];

        $this->logger->info('[Ozon] Starting costs processing', ['total_records' => count($rawData)]);

        // --- ФАЗА 1: Предзагрузка листингов ---
        $allSkus = [];
        foreach ($rawData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string)($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }

        $listingsCache = [];
        if (!empty($allSkus)) {
            $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
                $company,
                MarketplaceType::OZON,
                array_keys($allSkus)
            );
        }

        // --- ФАЗА 2: Предзагрузка категорий ---
        $categoriesCache = [];
        $allCategories = $this->costCategoryRepository->findBy([
            'company' => $company,
            'marketplace' => MarketplaceType::OZON,
            'deletedAt' => null,
        ]);

        foreach ($allCategories as $cat) {
            $categoriesCache[$cat->getCode()] = $cat;
        }

        // --- ФАЗА 3: Обработка ---
        $counter = 0;
        $newCategoriesCreated = 0;
        $lastFlushedCounter = 0;
        $knownExternalIdsMap = [];
        $pending = [];
        $pendingIds = [];
        $dedupBatchSize = $batchSize;

        $processPendingBatch = function () use (
            &$pending,
            &$pendingIds,
            &$knownExternalIdsMap,
            &$counter,
            &$synced,
            &$newCategoriesCreated,
            &$lastFlushedCounter,
            &$categoriesCache,
            &$listingsCache,
            &$company,
            $companyId,
            $conn,
            $batchSize
        ): void {
            if (empty($pendingIds)) {
                return;
            }

            $placeholders = implode(',', array_fill(0, count($pendingIds), '?'));
            $dbExistingIds = $conn->fetchFirstColumn(
                "SELECT external_id FROM marketplace_costs WHERE company_id = ? AND external_id IN ($placeholders)",
                array_merge([$companyId], $pendingIds)
            );

            $dbExistingMap = [];
            foreach ($dbExistingIds as $existingId) {
                $dbExistingMap[(string) $existingId] = true;
            }

            $knownExternalIdsMap = $knownExternalIdsMap + $dbExistingMap;

            foreach ($pending as $pendingItem) {
                try {
                    $entry = $pendingItem['entry'];
                    $externalId = $entry['external_id'];

                    if (isset($knownExternalIdsMap[$externalId])) {
                        continue;
                    }

                    $listing = $pendingItem['listing'];
                    $categoryCode = $entry['category_code'];
                    $category = $categoriesCache[$categoryCode] ?? null;

                    // Создаём категорию если не существует
                    if (!$category) {
                        $category = new \App\Marketplace\Entity\MarketplaceCostCategory(
                            Uuid::uuid4()->toString(),
                            $company,
                            MarketplaceType::OZON
                        );
                        $category->setCode($categoryCode);
                        $category->setName($entry['category_name']);

                        $this->em->persist($category);
                        $categoriesCache[$categoryCode] = $category;
                        $newCategoriesCreated++;
                    }

                    $cost = new MarketplaceCost(
                        Uuid::uuid4()->toString(),
                        $company,
                        MarketplaceType::OZON,
                        $category
                    );

                    $cost->setExternalId($externalId);
                    $cost->setCostDate($entry['cost_date']);
                    $cost->setAmount($entry['amount']);
                    $cost->setDescription($entry['description']);

                    if ($listing) {
                        $cost->setListing($listing);
                    }

                    $this->em->persist($cost);
                    $knownExternalIdsMap[$externalId] = true;
                    $synced++;
                    $counter++;
                } catch (\Exception $e) {
                    $this->logger->error('[Ozon] Failed to process cost', [
                        'external_id' => $pendingItem['entry']['external_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if (($counter - $lastFlushedCounter) >= $batchSize) {
                $this->em->flush();
                $this->em->clear();
                $lastFlushedCounter = $counter;

                $company = $this->em->find(\App\Company\Entity\Company::class, $companyId);
                foreach ($categoriesCache as $code => $cat) {
                    $categoriesCache[$code] = $this->em->getReference(
                        \App\Marketplace\Entity\MarketplaceCostCategory::class,
                        $cat->getId()
                    );
                }
                foreach ($listingsCache as $k => $cachedListing) {
                    $listingsCache[$k] = $this->em->getReference(
                        MarketplaceListing::class,
                        $cachedListing->getId()
                    );
                }

                gc_collect_cycles();
                $this->logger->info('[Ozon] Costs batch', ['processed' => $counter, 'synced' => $synced]);
            }

            $pending = [];
            $pendingIds = [];
        };

        foreach ($rawData as $op) {
            $operationId = (string)($op['operation_id'] ?? '');
            $operationDate = new \DateTimeImmutable($op['operation_date']);

            // Определяем listing (из первого item)
            $listing = null;
            $firstItem = ($op['items'] ?? [])[0] ?? null;
            if ($firstItem) {
                $sku = (string)($firstItem['sku'] ?? '');
                $listing = $listingsCache[$sku] ?? null;
            }

            // Собираем все затраты из одной операции
            $costEntries = $this->extractOzonCostEntries($op, $operationId, $operationDate);

            foreach ($costEntries as $entry) {
                $externalId = $entry['external_id'];

                if (isset($knownExternalIdsMap[$externalId])) {
                    continue;
                }

                $pending[] = [
                    'entry' => $entry,
                    'listing' => $listing,
                ];
                $pendingIds[] = $externalId;

                if (count($pendingIds) >= $dedupBatchSize) {
                    $processPendingBatch();
                }
            }
        }

        if (!empty($pendingIds)) {
            $processPendingBatch();
        }

        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('[Ozon] Costs processing completed', [
            'total_synced' => $synced,
            'new_categories' => $newCategoriesCreated,
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        ]);

        return $synced;
    }

    /**
     * Извлекает все записи затрат из одной Ozon-операции.
     *
     * @return array[] Массив записей [{external_id, category_code, category_name, amount, cost_date, description}]
     */
    private function extractOzonCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        // 1. Комиссия за продажу
        $commission = abs((float)($op['sale_commission'] ?? 0));
        if ($commission > 0) {
            $entries[] = [
                'external_id' => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => 'Комиссия Ozon за продажу',
                'amount' => (string)$commission,
                'cost_date' => $operationDate,
                'description' => 'Комиссия за продажу',
            ];
        }

        // 2. Доставка
        $delivery = abs((float)($op['delivery_charge'] ?? 0));
        if ($delivery > 0) {
            $entries[] = [
                'external_id' => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => 'Доставка Ozon',
                'amount' => (string)$delivery,
                'cost_date' => $operationDate,
                'description' => 'Стоимость доставки',
            ];
        }

        // 3. Обратная доставка (возврат)
        $returnDelivery = abs((float)($op['return_delivery_charge'] ?? 0));
        if ($returnDelivery > 0) {
            $entries[] = [
                'external_id' => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => 'Обратная доставка Ozon',
                'amount' => (string)$returnDelivery,
                'cost_date' => $operationDate,
                'description' => 'Обратная доставка',
            ];
        }

        // 4. Services — массив дополнительных услуг
        $services = $op['services'] ?? [];
        foreach ($services as $idx => $service) {
            $serviceAmount = abs((float)($service['price'] ?? 0));
            if ($serviceAmount <= 0) {
                continue;
            }

            $serviceName = $service['name'] ?? 'Неизвестная услуга';
            $categoryCode = $this->resolveOzonServiceCategoryCode($serviceName);

            $entries[] = [
                'external_id' => $operationId . '_svc_' . $idx,
                'category_code' => $categoryCode,
                'category_name' => $this->resolveOzonServiceCategoryName($categoryCode),
                'amount' => (string)$serviceAmount,
                'cost_date' => $operationDate,
                'description' => $serviceName,
            ];
        }

        return $entries;
    }

    /**
     * Определяет код категории затрат по названию услуги Ozon.
     */
    private function resolveOzonServiceCategoryCode(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        // Логистика
        if (str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'last mile')) {
            return 'ozon_logistics';
        }

        // Обработка
        if (str_contains($lower, 'обработк') || str_contains($lower, 'processing')
            || str_contains($lower, 'сборк')) {
            return 'ozon_processing';
        }

        // Хранение
        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }

        // Эквайринг
        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')
            || str_contains($lower, 'приём платеж')) {
            return 'ozon_acquiring';
        }

        // Продвижение / реклама
        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'трафик')) {
            return 'ozon_promotion';
        }

        // Подписка Premium
        if (str_contains($lower, 'подписк') || str_contains($lower, 'premium')
            || str_contains($lower, 'subscription')) {
            return 'ozon_subscription';
        }

        // Штрафы
        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'неустойк')) {
            return 'ozon_penalty';
        }

        // Компенсации
        if (str_contains($lower, 'компенсац') || str_contains($lower, 'compensation')
            || str_contains($lower, 'возмещени')) {
            return 'ozon_compensation';
        }

        return 'ozon_other_service';
    }

    /**
     * Человекочитаемое название категории Ozon.
     */
    private function resolveOzonServiceCategoryName(string $categoryCode): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission' => 'Комиссия Ozon за продажу',
            'ozon_delivery' => 'Доставка Ozon',
            'ozon_return_delivery' => 'Обратная доставка Ozon',
            'ozon_logistics' => 'Логистика Ozon',
            'ozon_processing' => 'Обработка отправления Ozon',
            'ozon_storage' => 'Хранение на складе Ozon',
            'ozon_acquiring' => 'Эквайринг Ozon',
            'ozon_promotion' => 'Продвижение / реклама Ozon',
            'ozon_subscription' => 'Подписка Premium Ozon',
            'ozon_penalty' => 'Штрафы Ozon',
            'ozon_compensation' => 'Компенсации Ozon',
            default => 'Прочие услуги Ozon',
        };
    }

    // ========================================================================
    // Ozon: создание листинга
    // ========================================================================

    private function createListingFromOzonData(Company $company, array $ozonData): MarketplaceListing
    {
        $sku = (string)$ozonData['sku'];
        $name = $ozonData['name'] ?? $sku;
        $price = $ozonData['price'] ?? '0';

        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $company,
            null,
            MarketplaceType::OZON
        );
        $listing->setMarketplaceSku($sku);
        $listing->setName($name ?: $sku);
        $listing->setPrice((string)$price);

        $this->em->persist($listing);

        return $listing;
    }

    // ========================================================================
    // WB: создание листинга
    // ========================================================================

    private function createListingFromWbData(Company $company, array $wbData): MarketplaceListing
    {
        $nmId = $wbData['nm_id'];
        $tsName = $this->normalizeWbSize($wbData['ts_name'] ?? null);
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

    private function normalizeWbSize(?string $tsName): string
    {
        $normalizedTsName = trim((string)$tsName);

        return ($normalizedTsName === '') ? 'UNKNOWN' : $normalizedTsName;
    }

    private function wbListingCacheKey(string $nmId, string $tsName): string
    {
        return "{$nmId}_{$tsName}";
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
