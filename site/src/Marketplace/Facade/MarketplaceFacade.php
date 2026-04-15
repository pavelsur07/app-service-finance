<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\DTO\ActiveListingDTO;
use App\Marketplace\DTO\AdvertisingCostDTO;
use App\Marketplace\DTO\CostData;
use App\Marketplace\DTO\OrderDTO;
use App\Marketplace\DTO\ReturnData;
use App\Marketplace\DTO\SaleData;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Infrastructure\Query\CostCategoriesQuery;
use App\Marketplace\Infrastructure\Query\ListingCostAggregateQuery;
use App\Marketplace\Infrastructure\Query\ListingMetaQuery;
use App\Marketplace\Infrastructure\Query\ListingReturnAggregateQuery;
use App\Marketplace\Infrastructure\Query\ListingSalesAggregateQuery;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
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
        private CostCategoriesQuery $costCategoriesQuery,
        private ListingSalesAggregateQuery $salesAggregateQuery,
        private ListingReturnAggregateQuery $returnAggregateQuery,
        private ListingCostAggregateQuery $costAggregateQuery,
        private ListingMetaQuery $listingMetaQuery,
        private MarketplaceCredentialsQuery $credentialsQuery,
    ) {}

    /**
     * Получить учётные данные подключения к API маркетплейса.
     *
     * Используется кросс-модульно (например, из MarketplaceAds для получения
     * credentials к Performance API).
     *
     * @return array{api_key: string, client_id: ?string}|null
     */
    public function getConnectionCredentials(
        string $companyId,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType,
    ): ?array {
        return $this->credentialsQuery->getCredentials($companyId, $marketplace, $connectionType);
    }

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
                    c.operation_type,
                    cat.id AS category_id, cat.code AS category_code, l.marketplace_sku
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

        // operationType — source of truth для классификации charge vs storno.
        // После Phase 2B колонка гарантированно NOT NULL для всех строк.
        return array_map(static fn (array $row): CostData => new CostData(
            marketplace: MarketplaceType::from($row['marketplace']),
            categoryCode: $row['category_code'],
            amount: $row['amount'],
            costDate: new \DateTimeImmutable($row['cost_date']),
            categoryId: $row['category_id'],
            marketplaceSku: $row['marketplace_sku'] ?? null,
            description: $row['description'],
            externalId: $row['external_id'],
            operationType: $row['operation_type'],
        ), $rows);
    }

    /**
     * @return ActiveListingDTO[]
     */
    public function getActiveListings(string $companyId, ?string $marketplace): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('l.id', 'l.marketplace', 'l.marketplace_sku AS marketplace_sku', 'l.name')
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
            name: $row['name'],
        ), $rows);
    }

    public function findListingById(string $companyId, string $listingId): ?ActiveListingDTO
    {
        $row = $this->connection->fetchAssociative(
            'SELECT l.id, l.marketplace, l.marketplace_sku AS marketplace_sku, l.name
             FROM marketplace_listings l
             WHERE l.id = :id AND l.company_id = :companyId',
            ['id' => $listingId, 'companyId' => $companyId],
        );

        if ($row === false) {
            return null;
        }

        return new ActiveListingDTO(
            id: $row['id'],
            marketplace: $row['marketplace'],
            marketplaceSku: $row['marketplace_sku'],
            name: $row['name'],
        );
    }

    public function getCostPriceForListing(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): ?string {
        $result = $this->costPriceResolver->resolve($companyId, $listingId, $date);

        return bccomp($result, '0.00', 2) === 0 ? null : $result;
    }

    /**
     * @return array<array{id: string, code: string, name: string}>
     */
    public function getCostCategoriesForCompany(
        string $companyId,
        string $marketplace,
    ): array {
        return $this->costCategoriesQuery->fetchForCompanyAndMarketplace($companyId, $marketplace);
    }

    /**
     * Находит все листинги (включая неактивные) по marketplace SKU (родительский артикул / nm_id в WB).
     * Без фильтра is_active — нужен при обработке исторических отчётов, когда листинг мог быть
     * деактивирован после даты отчёта.
     *
     * @return list<array{id: string, parentSku: string}>
     */
    public function findListingsByMarketplaceSku(
        string $companyId,
        string $marketplace,
        string $marketplaceSku,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id, l.marketplace_sku AS parent_sku
             FROM marketplace_listings l
             WHERE l.company_id = :companyId
               AND l.marketplace = :marketplace
               AND l.marketplace_sku = :marketplaceSku',
            [
                'companyId'     => $companyId,
                'marketplace'   => $marketplace,
                'marketplaceSku' => $marketplaceSku,
            ],
        );

        return array_map(static fn(array $row) => [
            'id'        => $row['id'],
            'parentSku' => $row['parent_sku'],
        ], $rows);
    }

    /**
     * Bulk-вариант {@see self::findListingsByMarketplaceSku()}: один запрос на набор SKU.
     * Результат сгруппирован по parentSku; SKU без листингов в ключах отсутствуют
     * (caller должен обработать их отдельно).
     *
     * @param  string[] $marketplaceSkus
     * @return array<string, list<array{id: string, parentSku: string}>> parentSku => listings
     */
    public function findListingsByMarketplaceSkus(
        string $companyId,
        string $marketplace,
        array $marketplaceSkus,
    ): array {
        if ($marketplaceSkus === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id, l.marketplace_sku AS parent_sku
             FROM marketplace_listings l
             WHERE l.company_id = :companyId
               AND l.marketplace = :marketplace
               AND l.marketplace_sku IN (:marketplaceSkus)',
            [
                'companyId'       => $companyId,
                'marketplace'     => $marketplace,
                'marketplaceSkus' => array_values(array_unique($marketplaceSkus)),
            ],
            ['marketplaceSkus' => Connection::PARAM_STR_ARRAY],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['parent_sku']][] = [
                'id'        => $row['id'],
                'parentSku' => $row['parent_sku'],
            ];
        }

        return $result;
    }

    /**
     * Bulk-запрос продаж для набора листингов за одну дату.
     * Листинги без продаж в результате отсутствуют (caller должен подставить 0 самостоятельно).
     *
     * @param  string[]           $listingIds
     * @return array<string, int> listingId => суммарное количество продаж
     */
    public function getSalesQuantitiesForListings(
        string $companyId,
        array $listingIds,
        \DateTimeImmutable $date,
    ): array {
        if ($listingIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.listing_id, SUM(s.quantity) AS total_quantity
             FROM marketplace_sales s
             WHERE s.company_id = :companyId
               AND s.listing_id IN (:listingIds)
               AND s.sale_date = :date
             GROUP BY s.listing_id',
            [
                'companyId'  => $companyId,
                'listingIds' => $listingIds,
                'date'       => $date->format('Y-m-d'),
            ],
            ['listingIds' => Connection::PARAM_STR_ARRAY],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['listing_id']] = (int) $row['total_quantity'];
        }

        return $result;
    }

    /**
     * @return array<string, \App\Marketplace\DTO\ListingSalesAggregateDTO> keyed by listingId
     */
    public function getSalesAggregatesByListing(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->salesAggregateQuery->executeByPeriod($companyId, $marketplace, $from, $to);
    }

    /**
     * @return array<string, \App\Marketplace\DTO\ListingReturnAggregateDTO> keyed by listingId
     */
    public function getReturnAggregatesByListing(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->returnAggregateQuery->executeByPeriod($companyId, $marketplace, $from, $to);
    }

    /**
     * @return array<string, list<\App\Marketplace\DTO\ListingCostCategoryAggregateDTO>> keyed by listingId
     */
    public function getCostAggregatesByListing(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->costAggregateQuery->executeByPeriod($companyId, $marketplace, $from, $to);
    }

    /**
     * @param  list<string> $listingIds
     * @return array<string, \App\Marketplace\DTO\ListingMetaDTO> keyed by id
     */
    public function getListingsMetaByIds(
        string $companyId,
        array $listingIds,
    ): array {
        return $this->listingMetaQuery->findByIds($companyId, $listingIds);
    }
}
