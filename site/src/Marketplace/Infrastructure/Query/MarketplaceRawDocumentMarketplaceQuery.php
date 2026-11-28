<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final class MarketplaceRawDocumentMarketplaceQuery implements MarketplaceRawDocumentMarketplaceQueryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findDocumentSource(string $companyId, string $rawDocId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT marketplace, api_endpoint FROM marketplace_raw_documents WHERE company_id = :companyId AND id = :id LIMIT 1',
            [
                'companyId' => $companyId,
                'id' => $rawDocId,
            ],
        );

        return false === $row ? null : [
            'marketplace' => (string) $row['marketplace'],
            'api_endpoint' => (string) $row['api_endpoint'],
        ];
    }
}
