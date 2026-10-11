<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Решения компании по своим категориям затрат маркетплейсов (неудалённые категории,
 * все маркетплейсы) — образцы для DefaultCostMappingSiblingResolver.
 *
 * Статья возвращается только для включённого правила со статьёй LEAF_INPUT своей же
 * компании. Правило, исключённое из ОПиУ или указывающее на не-LEAF статью, — тоже
 * решение компании, но без статьи-образца (null). Пустое включённое правило — не
 * решение и не возвращается.
 */
final readonly class CompanyCostPlTargetsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, array<string, string|null>> маркетплейс => код категории затрат => id статьи ОПиУ либо null */
    public function fetch(string $companyId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT mcc.marketplace,
                   mcc.code,
                   CASE WHEN m.include_in_pl = true AND pl.type = 'LEAF_INPUT' THEN pl.id END AS pl_category_id
            FROM marketplace_cost_pl_mappings m
            INNER JOIN marketplace_cost_categories mcc
                ON mcc.id = m.cost_category_id
               AND mcc.company_id = m.company_id
            LEFT JOIN pl_categories pl
                ON pl.id = m.pl_category_id
               AND pl.company_id = m.company_id
            WHERE m.company_id = :companyId
              AND mcc.deleted_at IS NULL
              AND (m.include_in_pl = false OR m.pl_category_id IS NOT NULL)
            SQL,
            ['companyId' => $companyId],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['marketplace']][(string) $row['code']] = null === $row['pl_category_id'] ? null : (string) $row['pl_category_id'];
        }

        return $result;
    }
}
