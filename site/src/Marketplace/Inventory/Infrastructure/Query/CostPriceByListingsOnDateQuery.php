<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Себестоимость набора листингов на дату одним запросом — те же правила, что у
 * InventoryCostPriceResolver: запись, действующая на дату, а если её нет — самая
 * ранняя запись листинга. Листинги без записей в результат не попадают.
 */
final readonly class CostPriceByListingsOnDateQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $listingIds
     *
     * @return array<string, string> listingId => decimal-string
     */
    public function execute(string $companyId, array $listingIds, \DateTimeImmutable $date): array
    {
        if ([] === $listingIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (p.listing_id) p.listing_id, p.price_amount
             FROM marketplace_inventory_cost_prices p
             WHERE p.company_id = :companyId
               AND p.listing_id IN (:listingIds)
             ORDER BY p.listing_id,
                      (p.effective_from <= :date AND (p.effective_to IS NULL OR p.effective_to >= :date)) DESC,
                      CASE WHEN p.effective_from <= :date AND (p.effective_to IS NULL OR p.effective_to >= :date)
                           THEN p.effective_from END DESC NULLS LAST,
                      p.effective_from ASC',
            [
                'companyId' => $companyId,
                'listingIds' => $listingIds,
                'date' => $date->format('Y-m-d'),
            ],
            ['listingIds' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['listing_id']] = (string) $row['price_amount'];
        }

        return $result;
    }
}
