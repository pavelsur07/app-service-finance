<?php
declare(strict_types=1);

namespace App\Finance\Report;

final class PlReportResult
{
    /** @param PlComputedRow[] $rows */
    public function __construct(
        public readonly PlReportPeriod $period,
        public readonly array $rows,
        /** @var string[] $warnings */
        public readonly array $warnings = []
    ) {}
}
