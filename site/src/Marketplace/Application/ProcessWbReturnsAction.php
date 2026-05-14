<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class ProcessWbReturnsAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceReturnRepository $returnRepository,
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
        $companyId = (string) $company->getId();
        $rawDocId = (string) $rawDoc->getId();
        $synced = 0;
        $batchSize = 250;

        $returnsData = array_filter($rawData, function (array $item): bool {
            return $this->normalizer->isReturn($item)
                && $this->normalizer->quantity($item) > 0
                && $this->normalizer->retailPriceWithDisc($item) > 0;
        });

        if (empty($returnsData)) {
            $this->logger->info('[WB] No returns to process');

            return 0;
        }

        $this->logger->info('[WB] Starting returns processing', [
            'total_filtered' => count($returnsData),
        ]);

        $allSrids = array_column($returnsData, 'srid');
        $existingSridsMap = $this->returnRepository->getExistingExternalIds($companyId, $allSrids);

        $allNmIds = array_values(array_unique(array_map(
            fn (array $item): string => $this->normalizer->nmId($item),
            $returnsData,
        )));
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            MarketplaceType::WILDBERRIES,
            $allNmIds,
        );

        $newListingsCreated = 0;
        foreach ($returnsData as $item) {
            $nmId = $this->normalizer->nmId($item);
            $tsName = $this->normalizer->techSize($item);
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
            ]);

            $listingsCache[$cacheKey] = $listing;
            $newListingsCreated++;
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('[WB] Created missing listings for returns', ['count' => $newListingsCreated]);
        }

        $counter = 0;

        foreach ($returnsData as $item) {
            try {
                $externalReturnId = (string) $item['srid'];

                if (isset($existingSridsMap[$externalReturnId])) {
                    continue;
                }

                $nmId = $this->normalizer->nmId($item);
                $tsName = $this->normalizer->techSize($item);
                $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
                $cacheKey = $nmId . '_' . $size;

                $listing = $listingsCache[$cacheKey] ?? null;

                if (!$listing) {
                    $this->logger->error('[WB] Listing missing from cache (logic error)', [
                        'nm_id' => $nmId,
                        'ts_name' => $size,
                    ]);
                    continue;
                }

                $return = new MarketplaceReturn(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    MarketplaceType::WILDBERRIES,
                );

                $return->setExternalReturnId($externalReturnId);
                $return->setReturnDate($this->normalizer->reportDate($item));
                $return->setQuantity(abs($this->normalizer->quantity($item)));
                $return->setRefundAmount((string) $this->normalizer->retailPriceWithDisc($item));
                $return->setReturnReason($this->normalizer->sellerOperName($item));
                $return->setRawDocumentId($rawDocId);
                $return->setRawData($item);

                $this->em->persist($return);
                $existingSridsMap[$externalReturnId] = true;

                $synced++;
                $counter++;

                if ($counter % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();

                    $company = $this->em->find(Company::class, $companyId);

                    foreach ($listingsCache as $k => $cachedListing) {
                        $listingsCache[$k] = $this->em->getReference(
                            MarketplaceListing::class,
                            $cachedListing->getId(),
                        );
                    }

                    gc_collect_cycles();

                    $this->logger->info('[WB] Returns batch', [
                        'processed' => $counter,
                        'synced' => $synced,
                        'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('[WB] Failed to process return item', [
                    'srid' => $item['srid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if ($counter % $batchSize !== 0) {
            $this->em->flush();
            $this->em->clear();
        }

        $this->logger->info('[WB] Returns processing completed', ['total_synced' => $synced]);

        return $synced;
    }
}
