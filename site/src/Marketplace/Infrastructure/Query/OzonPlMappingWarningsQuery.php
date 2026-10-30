<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/** Legacy Ozon начисления не являются ценой покупателя с СПП. Ничего не исправляет молча. */
final readonly class OzonPlMappingWarningsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function fetch(string $companyId): array
    {
        return array_map(static fn (array $row): string => sprintf(
            'Ozon: источник %s направлен в статью «%s» с СПП, но содержит начисления без СПП. Отключите это правило и используйте %s из отчёта «Реализация». Совместное включение складывает оба источника и искажает отчёт.',
            $row['amount_source'], $row['name'], $row['realization_source'],
        ), $this->fetchConflicts($companyId));
    }

    /** @return list<array<string, mixed>> */
    public function fetchConflicts(string $companyId): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT m.id, m.amount_source, m.pl_category_id, c.name,
                   realization.id AS realization_id, realization.is_negative AS realization_negative,
                   CASE m.amount_source WHEN 'sale_revenue' THEN 'sale_realization' ELSE 'return_realization' END AS realization_source
            FROM marketplace_sale_mappings m
            JOIN pl_categories c ON c.id = m.pl_category_id AND c.company_id = m.company_id
            LEFT JOIN marketplace_sale_mappings realization
              ON realization.company_id = m.company_id
             AND realization.marketplace = m.marketplace
             AND realization.is_active = true
             AND realization.pl_category_id = m.pl_category_id
             AND realization.amount_source = CASE m.amount_source WHEN 'sale_revenue' THEN 'sale_realization' ELSE 'return_realization' END
            WHERE m.company_id = :companyId
              AND m.marketplace = 'ozon'
              AND m.is_active = true
              AND m.amount_source IN ('sale_revenue', 'return_refund')
              AND (
                  c.code = CASE m.amount_source WHEN 'sale_revenue' THEN 'REV_SPP_SALES' ELSE 'REV_SPP_RETURNS' END
                  OR realization.id IS NOT NULL
              )
            ORDER BY m.amount_source, m.id
            SQL,
            ['companyId' => $companyId],
        );
    }
}
