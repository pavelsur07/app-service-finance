<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Факт ОПиУ из регистра `pl_daily_totals` по статьям и месяцам года одним GROUP BY — для
 * план–факт бюджета. Знак — значение отчёта (доход − расход). Проекты — точные id: `fetch` (одна
 * пара) сравнивает факт проекта с его собственным планом; `fetchSlice` получает уже развёрнутое
 * вызывающим поддерево (как в отчёте ОПиУ).
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
        return $this->fetchSlice(
            $companyId,
            $year,
            null === $projectDirectionId ? null : [$projectDirectionId],
            null === $responsibilityCenterId ? null : [$responsibilityCenterId],
            $includeUnassignedCenter,
        );
    }

    /**
     * Срез по спискам: проекты — точные id (поддерево разворачивает вызывающий), ЦФО — список,
     * `includeUnassignedCenter` — плюс строки без ЦФО. null — без фильтра по измерению, пустой
     * список — ничего.
     *
     * @param list<string>|null $projectDirectionIds
     * @param list<string>|null $responsibilityCenterIds
     *
     * @return array<string, array<string, string>> `Y-m` → plCategoryId → сумма; пустые месяцы не возвращаются
     */
    public function fetchSlice(string $companyId, int $year, ?array $projectDirectionIds, ?array $responsibilityCenterIds, bool $includeUnassignedCenter = false): array
    {
        if ([] === $projectDirectionIds || ([] === $responsibilityCenterIds && !$includeUnassignedCenter)) {
            return [];
        }
        $params = [
            'company' => $companyId,
            'from' => sprintf('%04d-01-01', $year),
            'to' => sprintf('%04d-12-31', $year),
        ];
        $types = [];
        $projectFilter = '';
        if (null !== $projectDirectionIds) {
            $projectFilter = 'AND dt.project_direction_id IN (:projects)';
            $params['projects'] = array_values(array_unique($projectDirectionIds));
            $types['projects'] = ArrayParameterType::STRING;
        }
        $centerFilter = '';
        if (null !== $responsibilityCenterIds) {
            $conditions = [];
            if ([] !== $responsibilityCenterIds) {
                $conditions[] = 'dt.responsibility_center_id IN (:centers)';
                $params['centers'] = array_values(array_unique($responsibilityCenterIds));
                $types['centers'] = ArrayParameterType::STRING;
            }
            if ($includeUnassignedCenter) {
                $conditions[] = 'dt.responsibility_center_id IS NULL';
            }
            $centerFilter = 'AND ('.implode(' OR ', $conditions).')';
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
            SQL, $params, $types);

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['month']][(string) $row['category_id']] = (string) $row['amount'];
        }

        return $result;
    }

    /**
     * Есть ли за год ненулевой факт хотя бы в одной ячейке «проект × ЦФО × статья × месяц».
     * Сумма по всей компании не годится: встречные суммы разных пар гасят друг друга.
     */
    public function hasAmounts(string $companyId, int $year): bool
    {
        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1
              FROM pl_daily_totals dt
             WHERE dt.company_id = :company
               AND dt.date BETWEEN :from AND :to
               AND dt.pl_category_id IS NOT NULL
             GROUP BY dt.project_direction_id, dt.responsibility_center_id, dt.pl_category_id, DATE_TRUNC('month', dt.date)
            HAVING SUM(dt.amount_income) - SUM(dt.amount_expense) <> 0
             LIMIT 1
            SQL, ['company' => $companyId, 'from' => sprintf('%04d-01-01', $year), 'to' => sprintf('%04d-12-31', $year)]);
    }
}
