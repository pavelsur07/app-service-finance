<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\PipelineStatus;
use Doctrine\DBAL\Connection;

final class MarketplaceRawDocumentDuplicateAuditQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function findDuplicateGroups(): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                rd.company_id,
                rd.marketplace,
                rd.document_type,
                rd.api_endpoint,
                rd.period_from,
                rd.period_to,
                COUNT(*) AS duplicate_count,
                ARRAY_AGG(rd.id ORDER BY rd.id) AS raw_document_ids
            FROM marketplace_raw_documents rd
            WHERE rd.processing_status IS NULL
               OR rd.processing_status <> :failedStatus
            GROUP BY
                rd.company_id,
                rd.marketplace,
                rd.document_type,
                rd.api_endpoint,
                rd.period_from,
                rd.period_to
            HAVING COUNT(*) > 1
            ORDER BY duplicate_count DESC, rd.company_id, rd.marketplace, rd.document_type, rd.api_endpoint, rd.period_from, rd.period_to
            SQL,
            ['failedStatus' => PipelineStatus::FAILED->value],
        );
    }
}
