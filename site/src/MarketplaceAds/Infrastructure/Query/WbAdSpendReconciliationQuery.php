<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Application\DTO\WbAdSpendReconciliation;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class WbAdSpendReconciliationQuery
{
    private const CURRENCY = 'RUB';

    public function __construct(private Connection $connection)
    {
    }

    public function get(string $companyId, string $rawDocumentId): WbAdSpendReconciliation
    {
        Assert::uuid($companyId);
        Assert::uuid($rawDocumentId);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH documents AS (
                    SELECT id, parent_sku, total_cost
                    FROM marketplace_ad_documents
                    WHERE company_id = :companyId
                      AND ad_raw_document_id = :rawDocumentId
                ),
                line_totals AS (
                    SELECT lines.ad_document_id, SUM(lines.cost) AS total
                    FROM marketplace_ad_document_lines lines
                    JOIN documents ON documents.id = lines.ad_document_id
                    GROUP BY lines.ad_document_id
                )
                SELECT
                    COALESCE(SUM(documents.total_cost), 0) AS document_total,
                    COALESCE(SUM(COALESCE(line_totals.total, 0)), 0) AS line_total,
                    COALESCE(SUM(documents.total_cost) FILTER (
                        WHERE line_totals.ad_document_id IS NULL
                    ), 0) AS without_line_total,
                    -- Deliberately do not filter by missing lines here: an
                    -- __unallocated__ document with a line must break reconciliation.
                    COALESCE(SUM(documents.total_cost) FILTER (
                        WHERE documents.parent_sku = :unallocatedSku
                    ), 0) AS unallocated_total,
                    COALESCE(SUM(documents.total_cost) FILTER (
                        WHERE line_totals.ad_document_id IS NULL
                          AND documents.parent_sku <> :unallocatedSku
                    ), 0) AS unmapped_total,
                    COUNT(*) FILTER (
                        WHERE line_totals.ad_document_id IS NULL
                          AND documents.parent_sku <> :unallocatedSku
                    ) AS unmapped_count
                FROM documents
                LEFT JOIN line_totals ON line_totals.ad_document_id = documents.id
                SQL,
            [
                'companyId' => $companyId,
                'rawDocumentId' => $rawDocumentId,
                'unallocatedSku' => AdRawEntry::UNALLOCATED_PARENT_SKU,
            ],
        );

        if (false === $row) {
            throw new \RuntimeException('WB advertising reconciliation query returned no result.');
        }

        return new WbAdSpendReconciliation(
            documentTotal: $this->money($row['document_total'] ?? null),
            lineTotal: $this->money($row['line_total'] ?? null),
            withoutLineTotal: $this->money($row['without_line_total'] ?? null),
            unallocatedTotal: $this->money($row['unallocated_total'] ?? null),
            unmappedTotal: $this->money($row['unmapped_total'] ?? null),
            unmappedCount: (int) ($row['unmapped_count'] ?? 0),
        );
    }

    private function money(mixed $value): Money
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException('WB advertising reconciliation returned an invalid money value.');
        }

        return Money::fromString((string) $value, self::CURRENCY);
    }
}
