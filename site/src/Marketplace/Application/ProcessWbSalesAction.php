<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class ProcessWbSalesAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly WbListingResolverService $listingResolver,
        private readonly WbSalesReportRowNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {
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
        $synced = 0;
        $batchSize = 250;

        $salesData = array_filter($rawData, function (array $item): bool {
            return $this->normalizer->isSale($item)
                && $this->normalizer->quantity($item) > 0
                && $this->normalizer->retailPriceWithDisc($item) > 0;
        });

        if (empty($salesData)) {
            $this->logger->info('[WB] No sales to process');
            return 0;
        }

        $this->logger->info('[WB] Starting sales processing', [
            'total_filtered' => count($salesData),
        ]);

        $allSrids = array_values(array_filter(array_map(fn (array $item): ?string => $this->normalizer->srid($item), $salesData)));
        $existingSridsMap = $this->saleRepository->getExistingExternalIds($companyId, $allSrids);

        $allNmIds = array_values(array_unique(array_map(
            fn (array $item): string => $this->normalizer->nmId($item),
            $salesData,
        )));
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            MarketplaceType::WILDBERRIES,
            $allNmIds,
        );

        $newListingsCreated = 0;
        foreach ($salesData as $item) {
            $nmId = $this->normalizer->nmId($item);
            $tsName = $this->normalizer->techSize($item);
            $size = (trim((string) $tsName) !== '') ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;

            if (!isset($listingsCache[$cacheKey])) {
                $listing = $this->listingResolver->resolve($company, $nmId, $tsName, [
                    'sa_name'      => $this->normalizer->vendorCode($item),
                    'brand_name'   => $this->normalizer->brandName($item),
                    'subject_name' => $this->normalizer->subjectName($item),
                    'retail_price' => (string) $this->normalizer->retailPrice($item),
                ]);
                $listingsCache[$cacheKey] = $listing;
                $newListingsCreated++;
            }
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('[WB] Created missing listings', ['count' => $newListingsCreated]);
        }

        $counter = 0;
        foreach ($salesData as $item) {
            try {
                $externalOrderId = (string) ($this->normalizer->srid($item) ?? '');
                if (isset($existingSridsMap[$externalOrderId])) {
                    continue;
                }

                $nmId = $this->normalizer->nmId($item);
                $tsName = $this->normalizer->techSize($item);
                $size = (trim((string) $tsName) !== '') ? trim((string) $tsName) : 'UNKNOWN';
                $cacheKey = $nmId . '_' . $size;
                $listing = $listingsCache[$cacheKey] ?? null;

                if ($listing === null) {
                    $this->logger->error('[WB] Listing missing from cache', ['nm_id' => $nmId]);
                    continue;
                }

                $sale = new MarketplaceSale(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    MarketplaceType::WILDBERRIES,
                );

                $sale->setExternalOrderId($externalOrderId);
                $sale->setSaleDate($this->normalizer->operationDate($item));
                $quantity = abs($this->normalizer->quantity($item));
                $retailPriceWithDisc = $this->normalizer->retailPriceWithDisc($item);
                $pricePerUnit = $quantity > 0 ? $retailPriceWithDisc / $quantity : 0.0;

                $sale->setQuantity($quantity);
                $sale->setPricePerUnit((string) $pricePerUnit);
                $sale->setTotalRevenue((string) $retailPriceWithDisc);
                $sale->setRawDocumentId($rawDocId);
                $sale->setRawData($item);

                $this->em->persist($sale);
                $existingSridsMap[$externalOrderId] = true;
                $synced++;
                $counter++;

                if ($counter % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();
                    $company = $this->em->find(Company::class, $companyId);
                    foreach ($listingsCache as $k => $cached) {
                        $listingsCache[$k] = $this->em->getReference(
                            MarketplaceListing::class,
                            $cached->getId(),
                        );
                    }
                    gc_collect_cycles();
                    $this->logger->info('[WB] Sales batch', [
                        'processed' => $counter,
                        'memory'    => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('[WB] Failed to process sale', [
                    'srid'  => $this->normalizer->srid($item) ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('[WB] Sales processing completed', ['total_synced' => $synced]);
        return $synced;
    }
}
