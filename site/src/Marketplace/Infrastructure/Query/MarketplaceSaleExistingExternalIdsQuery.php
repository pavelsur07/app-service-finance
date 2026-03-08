<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceSaleExistingExternalIdsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<int, string> $externalIds
     *
     * @return array<int, string>
     */
    public function findExisting(string $companyId, MarketplaceType $marketplace, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $externalIds = array_values(array_unique(array_filter(
            $externalIds,
            static fn (string $externalId): bool => $externalId !== '',
        )));

        if ($externalIds === []) {
            return [];
        }

        return array_map(
            static fn (mixed $externalId): string => (string) $externalId,
            $this->connection->executeQuery(
                'SELECT external_order_id FROM marketplace_sales WHERE company_id = :companyId AND marketplace = :marketplace AND external_order_id IN (:ids)',
                [
                    'companyId' => $companyId,
                    'marketplace' => $marketplace->value,
                    'ids' => $externalIds,
                ],
                [
                    'ids' => Connection::PARAM_STR_ARRAY,
                ],
            )->fetchFirstColumn(),
        );
    }
}
