<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Спорная группа: отчёт кабинета подтвердил другую группу, чем уже действующая.
 */
final readonly class OzonAccrualTypeDisputeView
{
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public string $currentGroupLabel,
        public string $disputedGroupLabel,
        public int $currentVotes,
        public int $disputedVotes,
        public ?string $disputedCompanyId,
        public ?string $disputedAt,
    ) {
    }
}
