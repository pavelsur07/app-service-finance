<?php

declare(strict_types=1);

namespace App\Budget\Infrastructure\Query;

use App\Budget\Application\DTO\BudgetRuleRow;
use Doctrine\DBAL\Connection;

/**
 * Правила «X % от строки-базы» варианта: одной пары или всех пар (без пары).
 */
final readonly class BudgetRulesQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<BudgetRuleRow>
     */
    public function fetch(string $companyId, string $versionId, ?string $projectDirectionId = null, ?string $responsibilityCenterId = null): array
    {
        $sql = 'SELECT pl_category_id, project_direction_id, responsibility_center_id, base_category_id, percent::text AS percent
                  FROM budget_rules
                 WHERE company_id = :companyId AND version_id = :versionId';
        $params = ['companyId' => $companyId, 'versionId' => $versionId];
        if (null !== $projectDirectionId && null !== $responsibilityCenterId) {
            $sql .= ' AND project_direction_id = :projectId AND responsibility_center_id = :centerId';
            $params['projectId'] = $projectDirectionId;
            $params['centerId'] = $responsibilityCenterId;
        }

        return array_map(
            static fn (array $row): BudgetRuleRow => new BudgetRuleRow(
                (string) $row['pl_category_id'],
                (string) $row['project_direction_id'],
                (string) $row['responsibility_center_id'],
                (string) $row['base_category_id'],
                (string) $row['percent'],
            ),
            $this->connection->fetchAllAssociative($sql.' ORDER BY pl_category_id', $params),
        );
    }

    /**
     * Статьи с правилом в паре: plCategoryId → правило.
     *
     * @return array<string, BudgetRuleRow>
     */
    public function targetsOfPair(string $companyId, string $versionId, string $projectDirectionId, string $responsibilityCenterId): array
    {
        $result = [];
        foreach ($this->fetch($companyId, $versionId, $projectDirectionId, $responsibilityCenterId) as $rule) {
            $result[$rule->plCategoryId] = $rule;
        }

        return $result;
    }
}
