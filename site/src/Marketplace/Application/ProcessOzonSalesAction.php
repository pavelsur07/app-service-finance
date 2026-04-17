<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceSaleExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * @deprecated Use OzonSalesRawProcessor::processBatch() via ProcessMarketplaceRawDocumentAction.
 */
final class ProcessOzonSalesAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceSaleExistingExternalIdsQuery $existingIdsQuery,
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

        $salesData = array_filter($rawData, function ($op) {
            return ($op['type'] ?? '') === 'orders'
                && (float) ($op['accruals_for_sale'] ?? 0) > 0;
        });

        if (empty($salesData)) {
            $this->logger->info('[Ozon] No sales to process');
            return 0;
        }

        $this->logger->info('[Ozon] Starting sales processing', [
            'total_filtered' => count($salesData),
        ]);

        $allExternalIds = array_map(fn ($op) => (string) $op['operation_id'], $salesData);
        $existingIdsMap = array_fill_keys(
            $this->existingIdsQuery->findExisting($companyId, MarketplaceType::OZON, $allExternalIds),
            true,
        );

        $allSkus = [];
        foreach ($salesData as $op) {
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

        foreach ($salesData as $op) {
            $price = (float) ($op['accruals_for_sale'] ?? 0);

            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $listing = new MarketplaceListing(
                    Uuid::uuid4()->toString(),
                    $company,
                    null,
                    MarketplaceType::OZON,
                );
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
            $this->logger->info('[Ozon] Created missing listings', ['count' => $newListingsCreated]);
        }

        $counter = 0;

        foreach ($salesData as $op) {
            try {
                $externalOrderId = (string) $op['operation_id'];

                if (isset($existingIdsMap[$externalOrderId])) {
                    continue;
                }

                $items = $op['items'] ?? [];
                $firstItem = $items[0] ?? null;
                $sku = $firstItem ? (string) ($firstItem['sku'] ?? '') : '';

                $listing = $listingsCache[$sku] ?? null;
                if (!$listing) {
                    $this->logger->warning('[Ozon] No listing for sale', ['sku' => $sku]);
                    continue;
                }

                $accrual = (float) ($op['accruals_for_sale'] ?? 0);
                $quantity = count($items) > 0 ? 1 : 0;

                $sale = new MarketplaceSale(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    MarketplaceType::OZON,
                );

                $sale->setExternalOrderId($externalOrderId);
                $sale->setSaleDate(new \DateTimeImmutable($op['operation_date']));
                $sale->setQuantity($quantity);
                $sale->setPricePerUnit((string) $accrual);
                $sale->setTotalRevenue((string) abs($accrual));
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
                            $cachedListing->getId(),
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
}
