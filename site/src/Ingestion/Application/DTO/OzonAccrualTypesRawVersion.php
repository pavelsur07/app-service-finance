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
        /** Когда Ozon отдал это содержимое последний раз (`last_seen_at`). */
        public \DateTimeImmutable $seenAt,
    ) {
    }
}
