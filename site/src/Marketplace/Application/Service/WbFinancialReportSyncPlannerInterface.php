<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Enum\FinancialReportSyncMode;
use DateTimeImmutable;

interface WbFinancialReportSyncPlannerInterface
{
    public function planDaily(?string $companyId = null, ?string $connectionId = null, bool $force = false): int;

    public function planRefresh14Days(?string $companyId = null, ?string $connectionId = null, int $maxDays = 1): int;

    public function planRefreshRecentDays(?string $companyId = null, ?string $connectionId = null, int $daysBack = 2, int $maxDays = 1): int;

    public function planDueRetry(?string $companyId = null, ?string $connectionId = null, int $maxDays = 1, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int;

    /**
     * @deprecated Use planRangeLimited() from console commands. planRange() schedules full explicit range without dispatch limit.
     */
    public function planRange(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        FinancialReportSyncMode $mode,
        ?string $companyId = null,
        ?string $connectionId = null,
        bool $forceRefresh = false,
    ): int;

    public function planRangeLimited(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        FinancialReportSyncMode $mode,
        int $maxDays,
        ?string $companyId = null,
        ?string $connectionId = null,
        bool $forceRefresh = false,
    ): WbFinancialReportSyncPlanResult;

    public function planMissing(?string $companyId = null, ?string $connectionId = null, int $maxDays = 14, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int;

    public function planInitial(?string $companyId = null, ?string $connectionId = null, ?DateTimeImmutable $startFrom = null, int $maxDays = 1): int;
}
