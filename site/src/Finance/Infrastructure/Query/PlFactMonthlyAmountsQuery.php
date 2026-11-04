<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Факт ОПиУ из регистра `pl_daily_totals` по статьям и месяцам года одним GROUP BY — для
 * план–факт бюджета. Знак — значение отчёта (доход − расход). Проект — точное совпадение, без
 * дочерних (в отличие от отчёта ОПиУ): план бюджета хранится по точной паре «проект × ЦФО», и
 * факт дочернего проекта сравнивается с его собственным планом.
 */
final readonly class PlFactMonthlyAmountsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, array<string, string>> `Y-m` → plCategoryId → сумма; пустые месяцы не возвращаются
     */
    public function fetch(string $companyId, int $year, ?string $projectDirectionId, ?string $responsibilityCenterId, bool $includeUnassignedCenter = false): array
    {
        $params = [
            'company' => $companyId,
            'from' => sprintf('%04d-01-01', $year),
            'to' => sprintf('%04d-12-31', $year),
        ];
        $projectFilter = '';
        if (null !== $projectDirectionId) {
            $projectFilter = 'AND dt.project_direction_id = :project';
            $params['project'] = $projectDirectionId;
        }
        $centerFilter = '';
        if (null !== $responsibilityCenterId) {
            $centerFilter = $includeUnassignedCenter
                ? 'AND (dt.responsibility_center_id = :center OR dt.responsibility_center_id IS NULL)'
                : 'AND dt.responsibility_center_id = :center';
            $params['center'] = $responsibilityCenterId;
        }

        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT TO_CHAR(dt.date, 'YYYY-MM') AS month,
                   dt.pl_category_id AS category_id,
                   (SUM(dt.amount_income) - SUM(dt.amount_expense))::text AS amount
              FROM pl_daily_totals dt
             WHERE dt.company_id = :company
               AND dt.date BETWEEN :from AND :to
               AND dt.pl_category_id IS NOT NULL
               {$projectFilter}
               {$centerFilter}
             GROUP BY 1, 2
            HAVING SUM(dt.amount_income) - SUM(dt.amount_expense) <> 0
             ORDER BY 1, 2
            SQL, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['month']][(string) $row['category_id']] = (string) $row['amount'];
        }

        return $result;
    }
}
