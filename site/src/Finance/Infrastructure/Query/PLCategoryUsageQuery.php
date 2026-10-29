<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use App\Finance\Application\DTO\PLCategoryUsage;
use Doctrine\DBAL\Connection;

/**
 * Ссылки на статьи ОПиУ компании, которые не дают их удалить (FK без ON DELETE или RESTRICT):
 * вложенные статьи, операции документов (удалённых документов — отдельно), статьи ДДС, кредиты
 * (проценты или комиссия; кредит считается один раз), правила разнесения продаж маркетплейсов.
 * Статьи ДДС и кредиты — ещё и поимённо: диалог удаления ведёт к каждой.
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
                 SELECT l.category_id, 0, 0, 0, 0, COUNT(DISTINCT l.loan_id), 0
                 FROM (
                     SELECT pl_category_interest_id AS category_id, id AS loan_id FROM finance_loan
                     WHERE company_id = :company AND pl_category_interest_id IS NOT NULL
                     UNION ALL
                     SELECT pl_category_fee_id, id FROM finance_loan
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
                    SUM(sale_mappings) AS sale_mappings,
                    (SELECT json_agg(json_build_object(\'id\', c.id, \'name\', c.name) ORDER BY c.name, c.id)
                     FROM cashflow_categories c
                     WHERE c.company_id = :company AND c.pl_category_id = used.category_id) AS cashflow_refs,
                    (SELECT json_agg(json_build_object(\'id\', l.id, \'name\', l.name) ORDER BY l.name, l.id)
                     FROM finance_loan l
                     WHERE l.company_id = :company
                       AND (l.pl_category_interest_id = used.category_id OR l.pl_category_fee_id = used.category_id)) AS loan_refs
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
                self::refs($row['cashflow_refs']),
                self::refs($row['loan_refs']),
            );
        }

        return $usage;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private static function refs(mixed $json): array
    {
        if (!\is_string($json) || '' === $json) {
            return [];
        }

        $refs = [];
        foreach ((array) json_decode($json, true, 4, \JSON_THROW_ON_ERROR) as $ref) {
            if (\is_array($ref) && isset($ref['id'], $ref['name'])) {
                $refs[] = ['id' => (string) $ref['id'], 'name' => (string) $ref['name']];
            }
        }

        return $refs;
    }
}
