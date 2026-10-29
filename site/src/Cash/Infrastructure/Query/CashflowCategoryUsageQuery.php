<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\DTO\CashflowCategoryUsage;
use Doctrine\DBAL\Connection;

/**
 * Ссылки на статьи ДДС компании, которые не дают её удалить: операции (сама операция или строка
 * её разбивки, удалённые — отдельно), платежи календаря, правила автокатегоризации.
 */
final readonly class CashflowCategoryUsageQuery
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array<string, CashflowCategoryUsage> только используемые статьи, ключ — ID статьи
     */
    public function __invoke(string $companyId, ?string $categoryId = null): array
    {
        $rows = $this->db->fetchAllAssociative(
            'WITH tx AS (
                 SELECT t.cashflow_category_id AS category_id, t.id, t.deleted_at
                 FROM cash_transaction t
                 WHERE t.company_id = :company AND t.cashflow_category_id IS NOT NULL
                 UNION
                 SELECT s.cashflow_category_id, t.id, t.deleted_at
                 FROM cash_transaction_split s JOIN cash_transaction t ON t.id = s.cash_transaction_id
                 WHERE s.company_id = :company
             ), used AS (
                 SELECT category_id, COUNT(*) FILTER (WHERE deleted_at IS NULL) AS transactions,
                        COUNT(*) FILTER (WHERE deleted_at IS NOT NULL) AS deleted_transactions,
                        0 AS payment_plans, 0 AS auto_rules
                 FROM tx GROUP BY category_id
                 UNION ALL
                 SELECT cashflow_category_id, 0, 0, COUNT(*), 0
                 FROM payment_plan WHERE company_id = :company GROUP BY cashflow_category_id
                 UNION ALL
                 SELECT cashflow_category_id, 0, 0, 0, COUNT(*)
                 FROM cash_transaction_auto_rule
                 WHERE company_id = :company AND cashflow_category_id IS NOT NULL
                 GROUP BY cashflow_category_id
             )
             SELECT category_id::text AS category_id, SUM(transactions) AS transactions,
                    SUM(deleted_transactions) AS deleted_transactions,
                    SUM(payment_plans) AS payment_plans, SUM(auto_rules) AS auto_rules
             FROM used
             WHERE CAST(:category AS uuid) IS NULL OR category_id = CAST(:category AS uuid)
             GROUP BY category_id',
            ['company' => $companyId, 'category' => $categoryId],
        );

        $usage = [];
        foreach ($rows as $row) {
            $usage[(string) $row['category_id']] = new CashflowCategoryUsage(
                (int) $row['transactions'],
                (int) $row['deleted_transactions'],
                (int) $row['payment_plans'],
                (int) $row['auto_rules'],
            );
        }

        return $usage;
    }
}
