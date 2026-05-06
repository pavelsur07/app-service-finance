<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class OzonRawDuplicatesCleanupPlan
{
    /** @param list<OzonRawDuplicatesCleanupDayPlan> $affectedDays */
    public function __construct(
        public string $companyId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $affectedDays,
    ) {
    }

    public function totalStaleSalesRows(): int
    {
        return array_sum(array_map(static fn (OzonRawDuplicatesCleanupDayPlan $dayPlan): int => $dayPlan->staleSalesRowsCount, $this->affectedDays));
    }

    public function totalStaleReturnsRows(): int
    {
        return array_sum(array_map(static fn (OzonRawDuplicatesCleanupDayPlan $dayPlan): int => $dayPlan->staleReturnsRowsCount, $this->affectedDays));
    }

    public function totalStaleCostsRows(): int
    {
        return array_sum(array_map(static fn (OzonRawDuplicatesCleanupDayPlan $dayPlan): int => $dayPlan->staleCostsRowsCount, $this->affectedDays));
    }
}
