<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final class MarketplaceRawDocumentMarketplaceQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getMarketplaceValue(string $companyId, string $rawDocId): ?string
    {
        $marketplace = $this->connection->fetchOne(
            'SELECT marketplace FROM marketplace_raw_documents WHERE company_id = :companyId AND id = :id LIMIT 1',
            [
                'companyId' => $companyId,
                'id' => $rawDocId,
            ],
        );

        return $marketplace === false ? null : (string) $marketplace;
    }
}
