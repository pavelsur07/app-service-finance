<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use App\Finance\Application\DTO\PLCategoryUsage;
use Doctrine\DBAL\Connection;

/**
 * Ссылки на статьи ОПиУ компании, которые не дают их удалить (FK без ON DELETE или RESTRICT):
 * вложенные статьи, операции документов (удалённых документов — отдельно), статьи ДДС, кредиты
 * (проценты и комиссия), правила разнесения продаж маркетплейсов.
 */
final readonly class PLCategoryUsageQuery
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array<string, PLCategoryUsage> только используемые статьи, ключ — ID статьи
     */
    public function __invoke(string $companyId, ?string $categoryId = null): array
    {
        $rows = $this->db->fetchAllAssociative(
            'WITH used AS (
                 SELECT parent_id AS category_id, COUNT(*) AS children, 0 AS doc_ops, 0 AS deleted_doc_ops,
                        0 AS cashflow, 0 AS loans, 0 AS sale_mappings
                 FROM pl_categories
                 WHERE company_id = :company AND parent_id IS NOT NULL
                 GROUP BY parent_id
                 UNION ALL
                 SELECT o.category_id, 0, COUNT(*) FILTER (WHERE d.deleted_at IS NULL),
                        COUNT(*) FILTER (WHERE d.deleted_at IS NOT NULL), 0, 0, 0
                 FROM document_operations o JOIN documents d ON d.id = o.document_id
                 WHERE d.company_id = :company AND o.category_id IS NOT NULL
                 GROUP BY o.category_id
                 UNION ALL
                 SELECT pl_category_id, 0, 0, 0, COUNT(*), 0, 0
                 FROM cashflow_categories
                 WHERE company_id = :company AND pl_category_id IS NOT NULL
                 GROUP BY pl_category_id
                 UNION ALL
                 SELECT l.category_id, 0, 0, 0, 0, COUNT(*), 0
                 FROM (
                     SELECT pl_category_interest_id AS category_id FROM finance_loan
                     WHERE company_id = :company AND pl_category_interest_id IS NOT NULL
                     UNION ALL
                     SELECT pl_category_fee_id FROM finance_loan
                     WHERE company_id = :company AND pl_category_fee_id IS NOT NULL
                 ) l
                 GROUP BY l.category_id
                 UNION ALL
                 SELECT pl_category_id, 0, 0, 0, 0, 0, COUNT(*)
                 FROM marketplace_sale_mappings
                 WHERE company_id = :company AND pl_category_id IS NOT NULL
                 GROUP BY pl_category_id
             )
             SELECT category_id::text AS category_id, SUM(children) AS children, SUM(doc_ops) AS doc_ops,
                    SUM(deleted_doc_ops) AS deleted_doc_ops, SUM(cashflow) AS cashflow, SUM(loans) AS loans,
                    SUM(sale_mappings) AS sale_mappings
             FROM used
             WHERE CAST(:category AS uuid) IS NULL OR category_id = CAST(:category AS uuid)
             GROUP BY category_id',
            ['company' => $companyId, 'category' => $categoryId],
        );

        $usage = [];
        foreach ($rows as $row) {
            $usage[(string) $row['category_id']] = new PLCategoryUsage(
                (int) $row['children'],
                (int) $row['doc_ops'],
                (int) $row['deleted_doc_ops'],
                (int) $row['cashflow'],
                (int) $row['loans'],
                (int) $row['sale_mappings'],
            );
        }

        return $usage;
    }
}
