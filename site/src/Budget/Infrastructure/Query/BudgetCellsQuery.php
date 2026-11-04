<?php

declare(strict_types=1);

namespace App\Budget\Infrastructure\Query;

use App\Budget\Application\DTO\BudgetCellRow;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Плановые суммы варианта по статье × месяцу: одной пары «проект × ЦФО» (с комментариями) или
 * сумма по всем парам (комментариев нет — их несколько).
 */
final readonly class BudgetCellsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<BudgetCellRow>
     */
    public function fetch(string $companyId, string $versionId, ?string $projectDirectionId, ?string $responsibilityCenterId): array
    {
        $byPair = null !== $projectDirectionId && null !== $responsibilityCenterId;
        $rows = $byPair
            ? $this->connection->fetchAllAssociative(
                "SELECT pl_category_id, to_char(month, 'YYYY-MM') AS month, amount::text AS amount, comment, source
                   FROM budget_lines
                  WHERE company_id = :companyId AND version_id = :versionId
                    AND project_direction_id = :projectId AND responsibility_center_id = :centerId",
                ['companyId' => $companyId, 'versionId' => $versionId, 'projectId' => $projectDirectionId, 'centerId' => $responsibilityCenterId],
            )
            : $this->connection->fetchAllAssociative(
                "SELECT pl_category_id, to_char(month, 'YYYY-MM') AS month, SUM(amount)::text AS amount, NULL AS comment, 'manual' AS source
                   FROM budget_lines
                  WHERE company_id = :companyId AND version_id = :versionId
                  GROUP BY pl_category_id, month",
                ['companyId' => $companyId, 'versionId' => $versionId],
            );

        return array_map(
            static fn (array $row): BudgetCellRow => new BudgetCellRow(
                (string) $row['pl_category_id'],
                (string) $row['month'],
                (string) $row['amount'],
                null === $row['comment'] ? null : (string) $row['comment'],
                (string) $row['source'],
            ),
            $rows,
        );
    }

    /**
     * Годовые суммы вариантов по статьям — по всем парам и месяцам.
     *
     * @param list<string> $versionIds
     *
     * @return array<string, array<string, string>> versionId → plCategoryId → сумма
     */
    public function yearTotalsByVersion(string $companyId, array $versionIds): array
    {
        if ([] === $versionIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT version_id, pl_category_id, SUM(amount)::text AS amount
               FROM budget_lines
              WHERE company_id = :companyId AND version_id IN (:versionIds)
              GROUP BY version_id, pl_category_id',
            ['companyId' => $companyId, 'versionIds' => $versionIds],
            ['versionIds' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['version_id']][(string) $row['pl_category_id']] = (string) $row['amount'];
        }

        return $result;
    }
}
