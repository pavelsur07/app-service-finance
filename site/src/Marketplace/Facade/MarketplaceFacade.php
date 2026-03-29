<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\DTO\ActiveListingDTO;
use App\Marketplace\DTO\AdvertisingCostDTO;
use App\Marketplace\DTO\CostData;
use App\Marketplace\DTO\OrderDTO;
use App\Marketplace\DTO\ReturnData;
use App\Marketplace\DTO\SaleData;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Repository\MarketplaceAdvertisingCostRepositoryInterface;
use App\Marketplace\Repository\MarketplaceOrderRepositoryInterface;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceFacade
{
    public function __construct(
        private MarketplaceAdvertisingCostRepositoryInterface $advertisingCostRepository,
        private MarketplaceOrderRepositoryInterface $orderRepository,
        private Connection $connection,
        private CostPriceResolverInterface $costPriceResolver,
    ) {}

    /**
     * @return AdvertisingCostDTO[]
     */
    public function getAdvertisingCostsForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $results = $this->advertisingCostRepository->findByListingAndDate(
            $companyId,
            $listingId,
            $date,
        );

        return array_map(AdvertisingCostDTO::fromEntity(...), $results);
    }

    /**
     * @return OrderDTO[]
     */
    public function getOrdersForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $results = $this->orderRepository->findByListingAndDate(
            $companyId,
            $listingId,
            $date,
        );

        return array_map(OrderDTO::fromEntity(...), $results);
    }

    /**
     * @return SaleData[]
     */
    public function getSalesForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.marketplace, s.external_order_id, s.sale_date, s.quantity,
                    s.price_per_unit, s.total_revenue, s.cost_price, s.raw_data,
                    l.marketplace_sku
             FROM marketplace_sales s
             JOIN marketplace_listings l ON s.listing_id = l.id
             WHERE s.company_id = :companyId
               AND s.listing_id = :listingId
               AND s.sale_date = :date',
            [
                'companyId' => $companyId,
                'listingId' => $listingId,
                'date' => $date->format('Y-m-d'),
            ],
        );

        return array_map(static fn(array $row) => new SaleData(
            marketplace: MarketplaceType::from($row['marketplace']),
            externalOrderId: $row['external_order_id'],
            saleDate: new \DateTimeImmutable($row['sale_date']),
            marketplaceSku: $row['marketplace_sku'],
            quantity: (int) $row['quantity'],
            pricePerUnit: $row['price_per_unit'],
            totalRevenue: $row['total_revenue'],
            rawData: $row['raw_data'] !== null ? json_decode($row['raw_data'], true) : null,
        ), $rows);
    }

    /**
     * @return ReturnData[]
     */
    public function getReturnsForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.marketplace, r.return_date, r.quantity, r.refund_amount,
                    r.return_reason, l.marketplace_sku
             FROM marketplace_returns r
             JOIN marketplace_listings l ON r.listing_id = l.id
             WHERE r.company_id = :companyId
               AND r.listing_id = :listingId
               AND r.return_date = :date',
            [
                'companyId' => $companyId,
                'listingId' => $listingId,
                'date' => $date->format('Y-m-d'),
            ],
        );

        return array_map(static fn(array $row) => new ReturnData(
            marketplace: MarketplaceType::from($row['marketplace']),
            marketplaceSku: $row['marketplace_sku'],
            returnDate: new \DateTimeImmutable($row['return_date']),
            quantity: (int) $row['quantity'],
            refundAmount: $row['refund_amount'],
            returnReason: $row['return_reason'],
        ), $rows);
    }

    /**
     * @return CostData[]
     */
    public function getCostsForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.marketplace, c.amount, c.cost_date, c.description, c.external_id,
                    cat.code AS category_code, l.marketplace_sku
             FROM marketplace_costs c
             JOIN marketplace_cost_categories cat ON c.category_id = cat.id
             LEFT JOIN marketplace_listings l ON c.listing_id = l.id
             WHERE c.company_id = :companyId
               AND c.listing_id = :listingId
               AND c.cost_date = :date',
            [
                'companyId' => $companyId,
                'listingId' => $listingId,
                'date' => $date->format('Y-m-d'),
            ],
        );

        return array_map(static fn(array $row) => new CostData(
            marketplace: MarketplaceType::from($row['marketplace']),
            categoryCode: $row['category_code'],
            amount: $row['amount'],
            costDate: new \DateTimeImmutable($row['cost_date']),
            marketplaceSku: $row['marketplace_sku'] ?? null,
            description: $row['description'],
            externalId: $row['external_id'],
        ), $rows);
    }

    /**
     * @return ActiveListingDTO[]
     */
    public function getActiveListings(string $companyId, ?string $marketplace): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('l.id', 'l.marketplace', 'l.marketplace_sku AS marketplace_sku')
            ->from('marketplace_listings', 'l')
            ->where('l.company_id = :companyId')
            ->andWhere('l.is_active = :active')
            ->setParameter('companyId', $companyId)
            ->setParameter('active', true);

        if ($marketplace !== null) {
            $qb->andWhere('l.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static fn(array $row) => new ActiveListingDTO(
            id: $row['id'],
            marketplace: $row['marketplace'],
            marketplaceSku: $row['marketplace_sku'],
        ), $rows);
    }

    public function getCostPriceForListing(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): ?string {
        $result = $this->costPriceResolver->resolve($companyId, $listingId, $date);

        return bccomp($result, '0.00', 2) === 0 ? null : $result;
    }
}
