<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Tenant-scoped catalog and historical cost context for the WB raw report.
 *
 * The query is intentionally driven by identifiers found in the selected raw
 * rows. It never reads normalized sales, returns, or financial transactions.
 */
final readonly class WbRawFinancialReportProductQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $nmIds
     * @param list<string> $barcodes
     *
     * @return iterable<array<string, mixed>>
     */
    public function findByCompanyAndIdentifiers(string $companyId, array $nmIds, array $barcodes): iterable
    {
        $nmIds = array_values(array_unique(array_filter(
            array_map('trim', $nmIds),
            static fn (string $value): bool => '' !== $value && '0' !== $value,
        )));
        $barcodes = array_values(array_unique(array_filter(
            array_map('trim', $barcodes),
            static fn (string $value): bool => '' !== $value,
        )));

        if ([] === $nmIds && [] === $barcodes) {
            return;
        }

        $identityQueries = [];
        $query = $this->connection->createQueryBuilder()
            ->select(
                'l.id AS listing_id',
                'l.marketplace_sku AS nm_id',
                'l.supplier_sku',
                'l.size',
                'l.name',
                'b.barcode',
                'c.id AS cost_id',
                'c.effective_from',
                'c.effective_to',
                'c.price_amount',
                'c.price_currency',
            )
            ->from('marketplace_listings', 'l')
            ->leftJoin(
                'l',
                'marketplace_listing_barcodes',
                'b',
                'b.listing_id = l.id AND b.company_id = l.company_id AND b.marketplace = :marketplace',
            )
            ->leftJoin(
                'l',
                'marketplace_inventory_cost_prices',
                'c',
                'c.listing_id = l.id AND c.company_id = l.company_id',
            )
            ->where('l.company_id = :companyId')
            ->andWhere('l.marketplace = :marketplace')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', 'wildberries');

        if ([] !== $nmIds) {
            $identityQueries[] = <<<'SQL'
                SELECT candidate_listing.id
                FROM marketplace_listings candidate_listing
                WHERE candidate_listing.company_id = :companyId
                  AND candidate_listing.marketplace = :marketplace
                  AND candidate_listing.marketplace_sku IN (:nmIds)
                SQL;
            $query->setParameter('nmIds', $nmIds, ArrayParameterType::STRING);
        }
        if ([] !== $barcodes) {
            $identityQueries[] = <<<'SQL'
                SELECT candidate_barcode.listing_id
                FROM marketplace_listing_barcodes candidate_barcode
                WHERE candidate_barcode.company_id = :companyId
                  AND candidate_barcode.marketplace = :marketplace
                  AND candidate_barcode.barcode IN (:barcodes)
                SQL;
            $query->setParameter('barcodes', $barcodes, ArrayParameterType::STRING);
        }
        $query->andWhere('l.id IN ('.implode(' UNION ', $identityQueries).')');

        $result = $query->executeQuery();
        try {
            yield from $result->iterateAssociative();
        } finally {
            $result->free();
        }
    }
}
