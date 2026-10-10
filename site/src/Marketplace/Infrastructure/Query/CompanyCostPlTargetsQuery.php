<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Куда компания относит свои категории затрат маркетплейса: только включённые
 * правила неудалённых категорий со статьёй LEAF_INPUT своей же компании. Образцы для
 * DefaultCostMappingSiblingResolver.
 */
final readonly class CompanyCostPlTargetsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, string> код категории затрат => id статьи ОПиУ */
    public function fetch(string $companyId, string $marketplace): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
            SELECT mcc.code, m.pl_category_id
            FROM marketplace_cost_pl_mappings m
            INNER JOIN marketplace_cost_categories mcc
                ON mcc.id = m.cost_category_id
               AND mcc.company_id = m.company_id
            INNER JOIN pl_categories pl
                ON pl.id = m.pl_category_id
               AND pl.company_id = m.company_id
            WHERE m.company_id = :companyId
              AND mcc.marketplace = :marketplace
              AND mcc.deleted_at IS NULL
              AND m.include_in_pl = true
              AND pl.type = 'LEAF_INPUT'
            SQL,
            ['companyId' => $companyId, 'marketplace' => $marketplace],
        );

        return array_map('strval', $rows);
    }
}
