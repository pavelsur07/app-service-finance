<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Application\FinancialReport;

final readonly class WbFinancialReportSyncPlanResult
{
    public function __construct(
        public int $candidatesCount,
        public int $dispatchLimit,
        public int $attemptedCount,
        public int $dispatchedCount,
        public int $skippedByLimitCount,
    ) {
    }
}
