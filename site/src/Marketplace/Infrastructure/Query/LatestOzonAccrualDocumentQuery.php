<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Последний загруженный день начислений Ozon по каждой компании.
 *
 * Читает только документы формата by-day: у них собственный `document_type`,
 * поэтому снятый формат v3 в выборку не попадает и «свежесть» не подделывается
 * историческими `sales_report`.
 *
 * Документы в статусе `failed` не считаются загруженными: загрузчик их
 * игнорирует (`findActiveExactDayDocuments`) и заводит день заново, значит и
 * гейт обязан видеть такой день как отсутствующий — иначе проверка утверждала
 * бы шире, чем то, что чинит починка.
 */
final class LatestOzonAccrualDocumentQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $companyIds
     *
     * @return array<string, string> companyId => последний period_from (Y-m-d)
     */
    public function findLatestByCompanyIds(array $companyIds, string $documentType): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT company_id, MAX(period_from) AS last_day
             FROM marketplace_raw_documents
             WHERE company_id IN (:companyIds)
               AND marketplace = :marketplace
               AND document_type = :documentType
               AND (processing_status IS NULL OR processing_status <> :failed)
             GROUP BY company_id',
            [
                'companyIds' => $companyIds,
                'marketplace' => MarketplaceType::OZON->value,
                'documentType' => $documentType,
                'failed' => PipelineStatus::FAILED->value,
            ],
            [
                'companyIds' => ArrayParameterType::STRING,
            ],
        );

        $latest = [];
        foreach ($rows as $row) {
            $companyId = (string) $row['company_id'];
            $lastDay = $row['last_day'] ?? null;

            if ('' !== $companyId && null !== $lastDay) {
                $latest[$companyId] = (new \DateTimeImmutable((string) $lastDay))->format('Y-m-d');
            }
        }

        return $latest;
    }
}
