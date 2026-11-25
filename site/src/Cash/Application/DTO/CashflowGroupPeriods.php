<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Application\Report\CashflowGroupCalendar;

final readonly class CashflowGroupPeriods
{
    /** @param list<CashflowGroupPeriodColumn> $columns */
    public function __construct(
        public CashflowGroupCalendar $calendar,
        public array $columns,
        public string $opening,
        public string $closing,
        public int $crossPairCount,
        public string $crossPairAmount,
    ) {
    }
}
