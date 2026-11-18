<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\Domain\PlPreview\AdStatisticsCostCategories;
use Doctrine\DBAL\Connection;

final readonly class OzonOrderAdAccrualQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{campaignId: string|null, date: string, amount: float}> */
    public function byCampaignAndDay(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            WITH costs AS MATERIALIZED (
                SELECT c.raw_document_id, c.external_id, c.cost_date,
                    CASE WHEN c.operation_type = 'storno' THEN -ABS(c.amount) ELSE ABS(c.amount) END AS net
                FROM marketplace_costs c
                JOIN marketplace_cost_categories category ON category.id = c.category_id AND category.company_id = c.company_id
                WHERE c.company_id = :company AND c.marketplace = 'ozon'
                    AND c.cost_date BETWEEN :dateFrom AND :dateTo AND category.code = :category
            ), campaigns AS (
                SELECT d.id, 'ozon-accrual-' || (a.entry->>'accrual_id') || '-non-item-type-54' AS external_id,
                    MIN(NULLIF(a.entry->>'unit_number', '')) AS campaign_id
                FROM (SELECT DISTINCT raw_document_id FROM costs WHERE raw_document_id IS NOT NULL) ids
                JOIN marketplace_raw_documents d ON d.id = ids.raw_document_id AND d.company_id = :company
                    AND d.marketplace = 'ozon' AND d.document_type = 'accrual_by_day'
                CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN jsonb_typeof(d.raw_data::jsonb->'accruals') = 'array'
                        THEN d.raw_data::jsonb->'accruals' ELSE '[]'::jsonb END
                ) a(entry)
                WHERE a.entry->'non_item_fee'->>'type_id' = '54'
                GROUP BY d.id, 2
            )
            SELECT campaigns.campaign_id, costs.cost_date, SUM(costs.net) AS amount
            FROM costs
            LEFT JOIN campaigns ON campaigns.id = costs.raw_document_id AND campaigns.external_id = costs.external_id
            GROUP BY 1, 2
            ORDER BY 2 DESC, 1
            SQL, [
            'company' => $companyId,
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
            'category' => AdStatisticsCostCategories::payPerOrderCode(),
        ]);

        return array_map(static fn (array $row): array => [
            'campaignId' => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
            'date' => (string) $row['cost_date'],
            'amount' => (float) $row['amount'],
        ], $rows);
    }
}
