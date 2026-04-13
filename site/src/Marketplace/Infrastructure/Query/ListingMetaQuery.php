<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\DTO\ListingMetaDTO;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class ListingMetaQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $listingIds
     * @return array<string, ListingMetaDTO> keyed by id
     */
    public function findByIds(string $companyId, array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                l.id,
                l.name            AS listing_title,
                l.marketplace_sku AS listing_sku,
                l.marketplace     AS listing_marketplace
            FROM marketplace_listings l
            WHERE l.id IN (:ids)
              AND l.company_id = :companyId
            SQL,
            [
                'ids'       => array_values($listingIds),
                'companyId' => $companyId,
            ],
            ['ids' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = new ListingMetaDTO(
                id:          $row['id'],
                title:       $row['listing_title'],
                sku:         $row['listing_sku'],
                marketplace: $row['listing_marketplace'],
            );
        }

        return $result;
    }
}
