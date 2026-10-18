<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Последняя загруженная версия справочника Ozon `accrual/types` у компании.
 */
final readonly class OzonAccrualTypesRawVersion
{
    public function __construct(
        public string $companyId,
        public string $rawRecordId,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }
}
