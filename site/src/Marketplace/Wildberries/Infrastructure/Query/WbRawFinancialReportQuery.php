<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Infrastructure\Query;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\PipelineStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read-only source for the WB loaded-data report.
 *
 * The status row points to the complete raw document for a business day.
 * Staging documents are deliberately excluded, so partial API pages cannot
 * leak into report totals.
 *
 * A failed or ongoing reload keeps the status linked to the previously
 * loaded document. Older statuses lost that link (before the fix in
 * markLoading), so a status without a link falls back to the day's active
 * document — only when it is fully processed (`completed`). An `empty`
 * status is WB's explicit answer for the day and gets no fallback.
 *
 * Outside the current attempt's own states (`raw_loaded`, `processing`,
 * `success`) the document is flagged as earlier data, and a document that
 * is being rewritten right now (`pending`, `loading`, `running`) is flagged
 * unsettled so its partial rows stay out of the totals.
 */
final readonly class WbRawFinancialReportQuery
{
    private const EARLIER_DATA = <<<'SQL'
        d.id IS NOT NULL AND (
            s.raw_document_id IS NULL
            OR (s.status NOT IN (:attemptStatuses) AND d.processing_status = :completedStatus)
        )
        SQL;

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
                'CASE WHEN '.self::EARLIER_DATA.' THEN d.records_count ELSE s.records_count END AS records_count',
                'COALESCE(s.raw_document_id, d.id) AS raw_document_id',
                '('.self::EARLIER_DATA.') AS fallback_raw_document',
                '(s.status NOT IN (:attemptStatuses) AND d.processing_status IN (:rewritingStatuses)) AS unsettled_raw_document',
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
                    d.id = COALESCE(s.raw_document_id, (
                        SELECT f.id
                        FROM marketplace_raw_documents f
                        WHERE f.company_id = s.company_id
                          AND f.marketplace = :marketplace
                          AND f.document_type = :reportType
                          AND f.period_from = s.business_date
                          AND f.period_to = s.business_date
                          AND f.processing_status = :completedStatus
                          AND s.status <> :emptyStatus
                    ))
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
            ->setParameter('completedStatus', PipelineStatus::COMPLETED->value)
            ->setParameter('emptyStatus', FinancialReportSyncStatus::EMPTY->value)
            ->setParameter('attemptStatuses', [
                FinancialReportSyncStatus::RAW_LOADED->value,
                FinancialReportSyncStatus::PROCESSING->value,
                FinancialReportSyncStatus::SUCCESS->value,
            ], ArrayParameterType::STRING)
            ->setParameter('rewritingStatuses', [
                PipelineStatus::PENDING->value,
                PipelineStatus::LOADING->value,
                PipelineStatus::RUNNING->value,
            ], ArrayParameterType::STRING)
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
