<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class CostCategoriesQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<array{id: string, code: string, name: string}>
     */
    public function fetchForCompanyAndMarketplace(
        string $companyId,
        string $marketplace,
    ): array {
        return $this->connection->fetchAllAssociative(
            'SELECT cc.id, cc.code, cc.name
             FROM marketplace_cost_categories cc
             WHERE cc.company_id = :companyId
               AND cc.marketplace = :marketplace
             ORDER BY cc.name ASC',
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
            ],
        );
    }
}
