<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\DTO\OzonAccrualTypesRawVersion;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

/**
 * Последние разобранные версии справочника `accrual/types` по компаниям.
 *
 * Выборка идёт по всем компаниям сознательно: справочник Ozon общий, и для
 * первичного заполнения таблицы нужна последняя версия, у кого бы она ни
 * лежала. Наружу отдаются только идентификаторы записей, не данные компаний.
 */
final readonly class OzonAccrualTypesRawVersionQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<OzonAccrualTypesRawVersion> от старой версии к новой
     */
    public function latestPerCompany(?string $companyId = null): array
    {
        if (null !== $companyId) {
            Assert::uuid($companyId);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (r.company_id) r.company_id, r.id, r.fetched_at
             FROM ingest_raw_records r
             WHERE r.source = :source
               AND r.resource_type = :resourceType
               AND r.normalization_status = :doneStatus
               AND r.payload_deleted_at IS NULL
               AND (CAST(:companyId AS UUID) IS NULL OR r.company_id = CAST(:companyId AS UUID))
             ORDER BY r.company_id, r.fetched_at DESC, r.created_at DESC',
            [
                'source' => IngestSource::OZON->value,
                'resourceType' => OzonResourceType::ACCRUAL_TYPES,
                'doneStatus' => RawNormalizationStatus::DONE->value,
                'companyId' => $companyId,
            ],
        );

        $versions = array_map(
            static fn (array $row): OzonAccrualTypesRawVersion => new OzonAccrualTypesRawVersion(
                companyId: (string) $row['company_id'],
                rawRecordId: (string) $row['id'],
                fetchedAt: new \DateTimeImmutable((string) $row['fetched_at']),
            ),
            $rows,
        );

        usort($versions, static fn (OzonAccrualTypesRawVersion $a, OzonAccrualTypesRawVersion $b): int => $a->fetchedAt <=> $b->fetchedAt);

        return $versions;
    }
}
