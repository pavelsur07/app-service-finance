<?php

declare(strict_types=1);

namespace App\Company\Repository;

use Doctrine\DBAL\Connection;

final readonly class ProjectDirectionCopyMappingRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, string> */
    public function findBetweenCompanies(string $sourceCompanyId, string $companyId): array
    {
        /** @var array<string, string> $mappings */
        $mappings = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT mapping.source_project_id, mapping.target_project_id
                  FROM project_direction_copy_mappings mapping
                  JOIN project_directions source ON source.id = mapping.source_project_id
                 WHERE source.company_id = :sourceCompanyId AND mapping.target_company_id = :companyId
                SQL,
            ['sourceCompanyId' => $sourceCompanyId, 'companyId' => $companyId],
        );

        return $mappings;
    }

    /** @param array<string, string> $mappings */
    public function insertBetweenCompanies(string $sourceCompanyId, string $companyId, array $mappings): void
    {
        $inserted = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_direction_copy_mappings (source_project_id, target_company_id, target_project_id)
                SELECT source.id, :companyId, CAST(mapping.value AS UUID)
                  FROM jsonb_each_text(CAST(:mappings AS jsonb)) mapping
                  JOIN project_directions source ON source.id = CAST(mapping.key AS UUID)
                 WHERE source.company_id = :sourceCompanyId
                SQL,
            ['sourceCompanyId' => $sourceCompanyId, 'companyId' => $companyId, 'mappings' => json_encode($mappings, \JSON_THROW_ON_ERROR)],
        );
        if ((int) $inserted !== \count($mappings)) {
            throw new \LogicException('Не удалось записать соответствия для всех проектов источника.');
        }
    }
}
