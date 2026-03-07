<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
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

        $salesData = array_filter($rawData, static function (array $item): bool {
            return ($item['doc_type_name'] ?? '') === 'Продажа'
                && (float) ($item['retail_amount'] ?? 0) > 0;
        });

        if (empty($salesData)) {
            $this->logger->info('[WB] No sales to process');
            return 0;
        }

        $this->logger->info('[WB] Starting sales processing', [
            'total_filtered' => count($salesData),
        ]);

        $allSrids = array_column($salesData, 'srid');
        $existingSridsMap = $this->saleRepository->getExistingExternalIds($companyId, $allSrids);

        $allNmIds = array_values(array_unique(array_column($salesData, 'nm_id')));
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            MarketplaceType::WILDBERRIES,
            $allNmIds,
        );

        $newListingsCreated = 0;
        foreach ($salesData as $item) {
            $nmId = (string) ($item['nm_id'] ?? '');
            $tsName = $item['ts_name'] ?? null;
            $size = (trim((string) $tsName) !== '') ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;

            if (!isset($listingsCache[$cacheKey])) {
                $listing = $this->listingResolver->resolve($company, $nmId, $tsName, [
                    'sa_name'      => $item['sa_name'] ?? '',
                    'brand_name'   => $item['brand_name'] ?? '',
                    'subject_name' => $item['subject_name'] ?? '',
                    'retail_price' => $item['retail_price'] ?? 0,
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
                $externalOrderId = (string) $item['srid'];
                if (isset($existingSridsMap[$externalOrderId])) {
                    continue;
                }

                $nmId = (string) ($item['nm_id'] ?? '');
                $tsName = $item['ts_name'] ?? null;
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
                $sale->setSaleDate(new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']));
                $sale->setQuantity(abs((int) $item['quantity']));
                $sale->setPricePerUnit((string) $item['retail_price']);
                $sale->setTotalRevenue((string) abs((float) $item['retail_amount']));
                $sale->setRawDocumentId($rawDocId);

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
                    'srid'  => $item['srid'] ?? 'unknown',
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
