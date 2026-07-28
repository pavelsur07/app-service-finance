<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Read-only source for the WB loaded-data report.
 *
 * The status row points to the complete raw document for a business day.
 * Staging documents are deliberately excluded, so partial API pages cannot
 * leak into report totals.
 */
final readonly class WbRawFinancialReportQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function findByCompanyAndPeriod(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): iterable {
        $result = $this->connection->createQueryBuilder()
            ->select(
                's.business_date',
                's.status',
                's.records_count',
                's.raw_document_id',
                's.last_error_message',
                's.updated_at',
                'd.id AS joined_raw_document_id',
                'd.raw_data',
                'd.synced_at',
            )
            ->from('marketplace_financial_report_sync_statuses', 's')
            ->leftJoin(
                's',
                'marketplace_raw_documents',
                'd',
                <<<'SQL'
                    d.id = s.raw_document_id
                    AND d.company_id = s.company_id
                    AND d.marketplace = :marketplace
                    AND d.document_type = :reportType
                    SQL,
            )
            ->where('s.company_id = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.report_type = :reportType')
            ->andWhere('s.business_date BETWEEN :dateFrom AND :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', 'wildberries')
            ->setParameter('reportType', 'sales_report')
            ->setParameter('dateFrom', $dateFrom->format('Y-m-d'))
            ->setParameter('dateTo', $dateTo->format('Y-m-d'))
            ->orderBy('s.business_date', 'ASC')
            ->executeQuery();

        try {
            yield from $result->iterateAssociative();
        } finally {
            $result->free();
        }
    }
}
