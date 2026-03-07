<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceSaleExistingExternalIdsQuery;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class WbSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private MarketplaceSaleExistingExternalIdsQuery $existingIdsQuery,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::SALE
            && $marketplace === MarketplaceType::WILDBERRIES;
        }

        return $type === MarketplaceType::WILDBERRIES->value && $kind === StagingRecordType::SALE->value;
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        $externalIds = array_values(array_filter(
            array_map(
                static fn (array $row): string => (string) ($row['rrd_id'] ?? $row['srid'] ?? ''),
                $rawRows,
            ),
            static fn (string $id): bool => $id !== '',
        ));

        if ($externalIds === []) {
            return;
        }

        $existingIds = $this->existingIdsQuery->findExisting($companyId, $marketplace, $externalIds);
        $company = $this->entityManager->getReference(Company::class, $companyId);

        foreach ($rawRows as $row) {
            $transactionId = (string) ($row['rrd_id'] ?? $row['srid'] ?? '');
            if ($transactionId === '' || in_array($transactionId, $existingIds, true)) {
                continue;
            }

            $existingIds[] = $transactionId;

            $listingId = (string) ($row['listing_id'] ?? '');
            $listing = $listingId !== ''
                ? $this->entityManager->getReference(MarketplaceListing::class, $listingId)
                : null;

            $pricePerUnit = (string) ((float) ($row['retail_price'] ?? $row['price'] ?? 0));
            $quantity = (int) ($row['quantity'] ?? 1);
            $totalRevenue = (string) ((float) ($row['retail_amount'] ?? $row['total_price'] ?? ((float) $pricePerUnit * $quantity)));

            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                null,
                $marketplace,
            );

            $sale->setExternalId($transactionId);
            $sale->setExternalOrderId((string) ($row['srid'] ?? ''));
            $sale->setSaleDate($this->resolveSaleDate($row));
            $sale->setQuantity($quantity);
            $sale->setPricePerUnit($pricePerUnit);
            $sale->setTotalRevenue($totalRevenue);
            $sale->setRawData($row);

            $this->entityManager->persist($sale);

            $commission = (float) ($row['commission_percent'] ?? 0);
            if ($commission !== 0.0) {
                // TODO: В будущем маппить категорию через сервис/словари. Пока берем из массива, если есть.
                $categoryId = (string) ($row['commission_category_id'] ?? '');
                $category = $categoryId !== '' ? $this->entityManager->getReference(MarketplaceCostCategory::class, $categoryId) : null;

                if ($category !== null) {
                    $cost = new MarketplaceCost(
                        Uuid::uuid4()->toString(),
                        $company,
                        $marketplace,
                        $category,
                    );
                    $cost->setExternalId($transactionId . '_commission');
                    $cost->setSale($sale);
                    $cost->setListing($listing);
                    $cost->setCostDate($this->resolveSaleDate($row));
                    $cost->setAmount((string) abs($commission));
                    $cost->setDescription('WB commission');
                    $cost->setRawData($row);

                    $this->entityManager->persist($cost);
                }
            }
        }

        $this->entityManager->flush();
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveSaleDate(array $row): \DateTimeImmutable
    {
        $date = (string) ($row['rr_dt'] ?? $row['sale_dt'] ?? $row['date'] ?? 'now');

        try {
            return new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }
}
