<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceBarcodeCatalogService;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Service\CostCalculator\CostCalculatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class ProcessWbCostsAction
{
    /** @var iterable<CostCalculatorInterface> */
    private iterable $costCalculators;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingExternalIdsQuery,
        private readonly WbListingResolverService $listingResolver,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly MarketplaceBarcodeCatalogService $barcodeCatalog,
        private readonly MarketplaceListingBarcodeRepository $barcodeRepository,
        private readonly LoggerInterface $logger,
        iterable $costCalculators,
    ) {
        $this->costCalculators = $costCalculators;
    }

    public function __invoke(string $companyId, string $rawDocId): int
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }
        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            throw new \RuntimeException('Raw document not found: ' . $rawDocId);
        }
        $rawData = $rawDoc->getRawData();
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
        $synced = 0;
        $unprocessedTypes = [];
        $batchSize = 100;

        // --- ФАЗА 1: ПРЕДЗАГРУЗКА ---

        // 1. Фильтруем: убираем только возвраты (их обрабатывает processReturnsFromRaw).
        //    ВАЖНО: 'Продажа' НЕ исключаем — калькуляторы комиссии, эквайринга,
        //    лояльности и др. работают именно по записям с doc_type_name = 'Продажа'.
        $costsData = array_filter($rawData, function ($item) {
            $docType = $item['doc_type_name'] ?? '';

            return $docType !== 'Возврат';
        });

        if (empty($costsData)) {
            $this->logger->info('No costs to process');

            return 0;
        }

        $this->logger->info('Starting bulk costs processing', [
            'total_filtered' => count($costsData),
            'batch_size' => $batchSize,
        ]);

        // 2. Массово загружаем listings (ПО ТОЙ ЖЕ ЛОГИКЕ ЧТО И ПРОДАЖИ!)
        $allNmIdsMap = [];
        foreach ($costsData as $item) {
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            if ($nmId === '' || $nmId === '0') {
                continue;
            }
            $allNmIdsMap[$nmId] = true;
        }
        $allNmIds = array_keys($allNmIdsMap);

        // Собираем все barcodes для barcode→size lookup
        $allBarcodes = [];
        foreach ($costsData as $item) {
            $barcode = trim((string) ($item['barcode'] ?? ''));
            if ($barcode !== '') {
                $allBarcodes[$barcode] = true;
            }
        }

        $barcodeSizeMap = $this->barcodeCatalog->findSizesByBarcodes(
            $companyId,
            MarketplaceType::WILDBERRIES,
            array_keys($allBarcodes),
        );

        // Предзагрузка barcode→listing для items с пустым nm_id
        $barcodeListingMap = [];
        if (!empty($allBarcodes)) {
            $barcodeEntities = $this->barcodeRepository->findByBarcodesIndexed(
                $companyId,
                array_keys($allBarcodes),
                MarketplaceType::WILDBERRIES,
            );
            foreach ($barcodeEntities as $bc => $barcodeEntity) {
                $barcodeListingMap[$bc] = $barcodeEntity->getListing();
            }
        }

        $listingsCache = [];
        if (!empty($allNmIds)) {
            // КЛЮЧЕВОЕ ИЗМЕНЕНИЕ: используем ту же логику индексации что и для продаж/возвратов
            $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
                $company,
                MarketplaceType::WILDBERRIES,
                $allNmIds,
            );

            $this->logger->info('Loaded listings for costs', [
                'count' => count($listingsCache),
            ]);
        }

        $newListingsCreated = 0;
        foreach ($costsData as $item) {
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            if ($nmId === '' || $nmId === '0') {
                continue;
            }

            $tsName = $item['ts_name'] ?? null;
            $barcode = trim((string) ($item['barcode'] ?? ''));

            if (trim((string) $tsName) === '' && $barcode !== '' && isset($barcodeSizeMap[$barcode])) {
                $tsName = $barcodeSizeMap[$barcode];
            }

            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;

            if (isset($listingsCache[$cacheKey])) {
                continue;
            }

            $listing = $this->listingResolver->resolve($company, $nmId, $tsName, [
                'sa_name'      => (string) ($item['sa_name'] ?? ''),
                'brand_name'   => (string) ($item['brand_name'] ?? ''),
                'subject_name' => (string) ($item['subject_name'] ?? ''),
                'retail_price' => (string) ($item['retail_price'] ?? '0'),
            ], $barcode);

            $listingsCache[$cacheKey] = $listing;
            $newListingsCreated++;
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->listingResolver->flushBarcodes();
            $this->logger->info('Created missing listings for costs in bulk', [
                'new_listings' => $newListingsCreated,
            ]);
        }

        // 3. Прогреваем категории компании
        $this->categoryResolver->preload($company, MarketplaceType::WILDBERRIES);

        // --- ФАЗА 2: ОБРАБОТКА ---
        $counter = 0;
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
            &$lastFlushedCounter,
            &$listingsCache,
            &$company,
            $companyId,
            $rawDocId,
            $batchSize,
        ): void {
            if (empty($pendingIds)) {
                return;
            }

            $dbExistingMap = $this->costExistingExternalIdsQuery->execute($companyId, $pendingIds);

            $knownExternalIdsMap = $knownExternalIdsMap + $dbExistingMap;

            foreach ($pending as $pendingItem) {
                $externalId = $pendingItem['external_id'];
                if (isset($knownExternalIdsMap[$externalId])) {
                    continue;
                }

                $costData = $pendingItem['costData'];
                $listing = $pendingItem['listing']; // ← Теперь это MarketplaceListing (или null)

                $categoryCode = $costData['category_code'];
                $categoryName = $costData['category_name']
                    ?? $costData['description']
                    ?? $categoryCode;
                $category = $this->categoryResolver->resolve(
                    $company,
                    MarketplaceType::WILDBERRIES,
                    $categoryCode,
                    $categoryName,
                );

                $cost = new MarketplaceCost(
                    Uuid::uuid4()->toString(),
                    $company,
                    MarketplaceType::WILDBERRIES,
                    $category,
                );

                $cost->setExternalId($externalId);
                $cost->setCostDate($costData['cost_date']);
                $cost->setAmount($costData['amount']);
                $cost->setDescription($costData['description']);
                $cost->setRawDocumentId($rawDocId);

                // ПРИВЯЗКА К LISTING (если есть)
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

                $company = $this->em->find(Company::class, $companyId);
                $this->categoryResolver->resetCache();

                foreach ($listingsCache as $k => $cachedListing) {
                    $listingsCache[$k] = $this->em->getReference(
                        MarketplaceListing::class,
                        $cachedListing->getId(),
                    );
                }

                gc_collect_cycles();

                $this->logger->info('Costs batch processed', [
                    'processed' => $counter,
                    'synced' => $synced,
                    'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
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

                    // Получаем listing из кэша по nm_id + size (с barcode fallback)
                    $nmId = trim((string) ($item['nm_id'] ?? ''));
                    $barcode = trim((string) ($item['barcode'] ?? ''));
                    if ($nmId === '' || $nmId === '0') {
                        // nm_id пустой — ищем листинг по barcode из предзагруженного кэша
                        $listing = $barcode !== '' && isset($barcodeListingMap[$barcode])
                            ? $barcodeListingMap[$barcode]
                            : null;
                    } else {
                        $tsName = $item['ts_name'] ?? null;

                        if (trim((string) $tsName) === '' && $barcode !== '' && isset($barcodeSizeMap[$barcode])) {
                            $tsName = $barcodeSizeMap[$barcode];
                        }

                        $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
                        $cacheKey = $nmId . '_' . $size;
                        $listing = $listingsCache[$cacheKey] ?? null;
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
                            'listing' => $listing, // ← Передаем найденный listing (или null)
                        ];
                        $pendingIds[] = $externalId;

                        if (count($pendingIds) >= $dedupBatchSize) {
                            $processPendingBatch();
                        }
                    }
                }

                if (!$processed && isset($item['supplier_oper_name'])) {
                    $operName = (string) $item['supplier_oper_name'];
                    if (!isset($unprocessedTypes[$operName])) {
                        $unprocessedTypes[$operName] = 0;
                    }
                    $unprocessedTypes[$operName]++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to process cost item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (!empty($pendingIds)) {
            $processPendingBatch();
        }

        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        // Сохраняем статистику
        $unprocessedCount = array_sum($unprocessedTypes);
        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);

        if ($rawDoc) {
            $rawDoc->setUnprocessedCostsCount($unprocessedCount);
            $rawDoc->setUnprocessedCostTypes($unprocessedTypes ?: null);
            $this->em->flush();
        }

        $this->logger->info('Costs processing completed', [
            'total_synced' => $synced,
            'total_records' => count($rawData),
            'unprocessed_count' => $unprocessedCount,
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        ]);

        return $synced;
    }
}
