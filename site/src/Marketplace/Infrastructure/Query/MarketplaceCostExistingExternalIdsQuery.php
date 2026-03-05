<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class MarketplaceCostExistingExternalIdsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<int, string> $externalIds
     *
     * @return array<string, true>
     */
    public function execute(string $companyId, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $externalIds = array_values(array_unique(array_filter($externalIds, static fn (string $externalId): bool => $externalId !== '')));
        if ($externalIds === []) {
            return [];
        }

        $existingExternalIdsMap = [];

        foreach (array_chunk($externalIds, 1000) as $externalIdsChunk) {
            $rows = $this->connection->executeQuery(
                'SELECT external_id FROM marketplace_costs WHERE company_id = :companyId AND external_id IN (:externalIds)',
                [
                    'companyId' => $companyId,
                    'externalIds' => $externalIdsChunk,
                ],
                [
                    'externalIds' => ArrayParameterType::STRING,
                ]
            )->fetchFirstColumn();

            foreach ($rows as $externalId) {
                $existingExternalIdsMap[(string) $externalId] = true;
            }
        }

        return $existingExternalIdsMap;
    }
}
