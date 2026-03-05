<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class MarketplaceListingPreloadQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $skus
     *
     * @return array<int, array{id:string, marketplace_sku:string, size:?string}>
     */
    public function fetchBySkus(string $companyId, string $marketplaceValue, array $skus): array
    {
        $normalizedSkus = [];

        foreach ($skus as $sku) {
            $value = trim((string) $sku);
            if ('' === $value) {
                continue;
            }

            $normalizedSkus[$value] = true;
        }

        $normalizedSkus = array_keys($normalizedSkus);
        if ([] === $normalizedSkus) {
            return [];
        }

        $rows = [];

        foreach (array_chunk($normalizedSkus, 1000) as $skuChunk) {
            $chunkRows = $this->connection->fetchAllAssociative(
                'SELECT id, marketplace_sku, size
                 FROM marketplace_listings
                 WHERE company_id = :companyId
                   AND marketplace = :marketplace
                   AND marketplace_sku IN (:skus)',
                [
                    'companyId' => $companyId,
                    'marketplace' => $marketplaceValue,
                    'skus' => $skuChunk,
                ],
                [
                    'skus' => ArrayParameterType::STRING,
                ]
            );

            foreach ($chunkRows as $row) {
                $rows[] = [
                    'id' => (string) $row['id'],
                    'marketplace_sku' => (string) $row['marketplace_sku'],
                    'size' => isset($row['size']) ? (string) $row['size'] : null,
                ];
            }
        }

        return $rows;
    }
}
