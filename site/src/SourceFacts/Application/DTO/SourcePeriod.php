<?php

declare(strict_types=1);

namespace App\SourceFacts\Application\DTO;

/**
 * Период источника (для by-day — день кабинета) с текущей ревизией.
 */
final readonly class SourcePeriod
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $periodKey,
        public int $revision,
    ) {
    }
}
