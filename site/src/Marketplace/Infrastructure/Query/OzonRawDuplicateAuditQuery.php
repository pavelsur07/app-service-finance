<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final class OzonRawDuplicateAuditQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function findExactRawDocumentDuplicates(?string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = $this->buildBaseParams($companyId, $from, $to);
        $companyFilter = $companyId !== null ? ' AND rd.company_id = :companyId' : '';

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                rd.company_id,
                rd.marketplace,
                rd.document_type,
                rd.period_from,
                rd.period_to,
                COUNT(*) AS docs_count,
                ARRAY_AGG(rd.id ORDER BY rd.synced_at DESC, rd.id DESC) AS doc_ids,
                ARRAY_AGG(COALESCE(rd.processing_status, 'null') ORDER BY rd.synced_at DESC, rd.id DESC) AS statuses,
                ARRAY_AGG(rd.records_count ORDER BY rd.synced_at DESC, rd.id DESC) AS records_counts,
                ARRAY_AGG(rd.synced_at ORDER BY rd.synced_at DESC, rd.id DESC) AS synced_ats
            FROM marketplace_raw_documents rd
            WHERE rd.marketplace = 'ozon'
              AND rd.document_type = 'sales_report'
              AND rd.period_from BETWEEN :fromDate AND :toDate
              AND rd.period_to BETWEEN :fromDate AND :toDate
              {$companyFilter}
            GROUP BY rd.company_id, rd.marketplace, rd.document_type, rd.period_from, rd.period_to
            HAVING COUNT(*) > 1
            ORDER BY rd.company_id, rd.period_from, rd.period_to
            SQL,
            $params,
        );
    }

    public function findOverlappingRawDocuments(?string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = $this->buildBaseParams($companyId, $from, $to);
        $companyFilter = $companyId !== null ? ' AND daily.company_id = :companyId' : '';

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                daily.company_id,
                daily.period_from AS day,
                daily.id AS daily_doc_id,
                daily.records_count AS daily_records_count,
                range_doc.id AS range_doc_id,
                range_doc.period_from AS range_from,
                range_doc.period_to AS range_to,
                range_doc.records_count AS range_records_count,
                daily.processing_status AS daily_status,
                range_doc.processing_status AS range_status
            FROM marketplace_raw_documents daily
            INNER JOIN marketplace_raw_documents range_doc
                ON range_doc.company_id = daily.company_id
               AND range_doc.marketplace = daily.marketplace
               AND range_doc.document_type = daily.document_type
               AND range_doc.id <> daily.id
               AND range_doc.period_from <= daily.period_from
               AND range_doc.period_to >= daily.period_to
               AND range_doc.period_from <> range_doc.period_to
            WHERE daily.marketplace = 'ozon'
              AND daily.document_type = 'sales_report'
              AND daily.period_from = daily.period_to
              AND daily.period_from BETWEEN :fromDate AND :toDate
              {$companyFilter}
            ORDER BY daily.company_id, daily.period_from, daily.id, range_doc.id
            SQL,
            $params,
        );
    }

    public function findProcessedSalesWithMultipleRawDocuments(?string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->findProcessedRowsWithMultipleRawDocuments(
            table: 'marketplace_sales',
            dateField: 'sale_date',
            amountField: 'total_revenue',
            alias: 's',
            companyId: $companyId,
            from: $from,
            to: $to,
        );
    }

    public function findProcessedReturnsWithMultipleRawDocuments(?string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->findProcessedRowsWithMultipleRawDocuments(
            table: 'marketplace_returns',
            dateField: 'return_date',
            amountField: 'refund_amount',
            alias: 'r',
            companyId: $companyId,
            from: $from,
            to: $to,
        );
    }

    public function findProcessedCostsWithMultipleRawDocuments(?string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->findProcessedRowsWithMultipleRawDocuments(
            table: 'marketplace_costs',
            dateField: 'cost_date',
            amountField: 'amount',
            alias: 'c',
            companyId: $companyId,
            from: $from,
            to: $to,
        );
    }

    private function findProcessedRowsWithMultipleRawDocuments(
        string $table,
        string $dateField,
        string $amountField,
        string $alias,
        ?string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = $this->buildBaseParams($companyId, $from, $to);
        $companyFilter = $companyId !== null ? sprintf(' AND %s.company_id = :companyId', $alias) : '';

        return $this->connection->fetchAllAssociative(
            sprintf(
                <<<SQL
                SELECT
                    %1$s.company_id,
                    %1$s.%2$s,
                    COUNT(DISTINCT %1$s.raw_document_id) AS raw_docs_count,
                    COUNT(*) AS row_count,
                    COALESCE(SUM(%1$s.%3$s), 0) AS amount_sum,
                    ARRAY_AGG(DISTINCT %1$s.raw_document_id) AS raw_document_ids,
                    BOOL_OR(%1$s.document_id IS NOT NULL) AS has_closed_rows
                FROM %4$s %1$s
                WHERE %1$s.marketplace = 'ozon'
                  AND %1$s.%2$s BETWEEN :fromDate AND :toDate
                  AND %1$s.raw_document_id IS NOT NULL
                  %5$s
                GROUP BY %1$s.company_id, %1$s.%2$s
                HAVING COUNT(DISTINCT %1$s.raw_document_id) > 1
                ORDER BY %1$s.company_id, %1$s.%2$s
                SQL,
                $alias,
                $dateField,
                $amountField,
                $table,
                $companyFilter,
            ),
            $params,
        );
    }

    private function buildBaseParams(?string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($companyId !== null) {
            Assert::uuid($companyId);
        }

        $params = [
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
        ];

        if ($companyId !== null) {
            $params['companyId'] = $companyId;
        }

        return $params;
    }
}
