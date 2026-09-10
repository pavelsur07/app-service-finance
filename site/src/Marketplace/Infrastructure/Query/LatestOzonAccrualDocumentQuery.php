<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Компании, у которых за конкретный день есть обработанный документ начислений.
 *
 * Спрашивается ровно запрошенный день, а не «последний загруженный»: максимум по
 * периоду принял бы документ за сегодня как доказательство того, что вчерашний
 * день на месте, и гейт зазеленел бы поверх дыры.
 *
 * Засчитывается только `completed`. Документ в `pending`/`running` означает, что
 * сырьё скачано, а продаж, возвратов и затрат из него ещё не появилось; `failed`
 * загрузчик и вовсе игнорирует и заводит день заново. Считать такие дни
 * загруженными значит утверждать наличие финансовых строк, которых нет.
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
     * @return list<string> companyId тех, у кого день закрыт обработанным документом
     */
    public function findCompaniesWithProcessedDay(array $companyIds, string $documentType, string $day): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT company_id
             FROM marketplace_raw_documents
             WHERE company_id IN (:companyIds)
               AND marketplace = :marketplace
               AND document_type = :documentType
               AND period_from = :day
               AND period_to = :day
               AND processing_status = :completed',
            [
                'companyIds' => $companyIds,
                'marketplace' => MarketplaceType::OZON->value,
                'documentType' => $documentType,
                'day' => $day,
                'completed' => PipelineStatus::COMPLETED->value,
            ],
            [
                'companyIds' => ArrayParameterType::STRING,
            ],
        );

        $found = [];
        foreach ($rows as $row) {
            $companyId = (string) $row['company_id'];
            if ('' !== $companyId) {
                $found[] = $companyId;
            }
        }

        return $found;
    }
}
