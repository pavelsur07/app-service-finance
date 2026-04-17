<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * @deprecated Use OzonReturnsRawProcessor::processBatch() via ProcessMarketplaceRawDocumentAction.
 */
final class ProcessOzonReturnsAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly MarketplaceListingRepository $listingRepository,
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

        $returnsData = array_filter($rawData, static function (array $op): bool {
            return ($op['type'] ?? '') === 'returns';
        });

        if (empty($returnsData)) {
            $this->logger->info('[Ozon] No returns to process');

            return 0;
        }

        $this->logger->info('[Ozon] Starting returns processing', [
            'total_filtered' => count($returnsData),
        ]);

        $allExternalIds = array_map(static fn (array $op): string => (string) $op['operation_id'], $returnsData);
        $existingIdsMap = $this->returnRepository->getExistingExternalIds($companyId, $allExternalIds);

        $allSkus = [];
        foreach ($returnsData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }
        $allSkus = array_keys($allSkus);

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            $allSkus,
        );

        $newListingsCreated = 0;

        foreach ($returnsData as $op) {
            $price = abs((float) ($op['amount'] ?? 0));

            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::OZON);
                $listing->setMarketplaceSku($sku);
                $listing->setPrice((string) $price);
                $listing->setName($item['name'] ?? null);
                $this->em->persist($listing);

                $listingsCache[$sku] = $listing;
                $newListingsCreated++;
            }
        }

        if ($newListingsCreated > 0) {
            $this->em->flush();
            $this->logger->info('[Ozon] Created missing listings for returns', ['count' => $newListingsCreated]);
        }

        $counter = 0;

        foreach ($returnsData as $op) {
            try {
                $externalReturnId = (string) $op['operation_id'];

                if (isset($existingIdsMap[$externalReturnId])) {
                    continue;
                }

                $items = $op['items'] ?? [];
                $firstItem = $items[0] ?? null;
                $sku = $firstItem ? (string) ($firstItem['sku'] ?? '') : '';

                $listing = $listingsCache[$sku] ?? null;
                if (!$listing) {
                    $this->logger->warning('[Ozon] No listing for return', ['sku' => $sku]);
                    continue;
                }

                $amount = abs((float) ($op['amount'] ?? 0));

                $return = new MarketplaceReturn(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    MarketplaceType::OZON,
                );

                $return->setExternalReturnId($externalReturnId);
                $return->setReturnDate(new \DateTimeImmutable($op['operation_date']));
                $return->setQuantity(1);
                $return->setRefundAmount((string) $amount);
                $return->setReturnReason($op['operation_type_name'] ?? null);
                $return->setRawDocumentId($rawDocId);

                $this->em->persist($return);
                $existingIdsMap[$externalReturnId] = true;

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
}
