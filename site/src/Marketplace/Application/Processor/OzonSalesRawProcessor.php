<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessOzonSalesAction;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceSaleExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private readonly ProcessOzonSalesAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceSaleExistingExternalIdsQuery $existingIdsQuery,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::SALE
                && $marketplace === MarketplaceType::OZON;
        }

        return $type === MarketplaceType::OZON->value && $kind === 'sales';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return ($this->action)($companyId, $rawDocId);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        if (empty($rawRows)) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $salesData = array_filter($rawRows, static function (array $op): bool {
            return ($op['type'] ?? '') === 'orders'
                && (float) ($op['accruals_for_sale'] ?? 0) > 0;
        });

        if (empty($salesData)) {
            return;
        }

        // Предзагрузка листингов по sku
        $allSkus = [];
        foreach ($salesData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            array_keys($allSkus),
        );

        $newListings = 0;
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
                $newListings++;
            }
        }

        if ($newListings > 0) {
            $this->em->flush();
        }

        // Дедупликация
        $allOperationIds = array_values(array_filter(array_map(
            static fn (array $op): string => (string) ($op['operation_id'] ?? ''),
            $salesData,
        )));
        $existingMap = array_fill_keys(
            $this->existingIdsQuery->findExisting($companyId, MarketplaceType::OZON, $allOperationIds),
            true,
        );

        foreach ($salesData as $op) {
            $operationId = (string) ($op['operation_id'] ?? '');
            if ($operationId === '' || isset($existingMap[$operationId])) {
                continue;
            }

            $firstItem = ($op['items'] ?? [])[0] ?? null;
            $sku = $firstItem ? (string) ($firstItem['sku'] ?? '') : '';
            $listing = $listingsCache[$sku] ?? null;

            if (!$listing) {
                $this->logger->warning('[Ozon] processBatch sales: listing not found', ['sku' => $sku]);
                continue;
            }

            $accrual = (float) ($op['accruals_for_sale'] ?? 0);

            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::OZON,
            );

            $sale->setExternalOrderId($operationId);
            $sale->setSaleDate(new \DateTimeImmutable($op['operation_date']));
            $sale->setQuantity(1);
            $sale->setPricePerUnit((string) $accrual);
            $sale->setTotalRevenue((string) abs($accrual));
            $sale->setRawData($op);

            $this->em->persist($sale);
            $existingMap[$operationId] = true;
        }

        $this->em->flush();
    }
}
