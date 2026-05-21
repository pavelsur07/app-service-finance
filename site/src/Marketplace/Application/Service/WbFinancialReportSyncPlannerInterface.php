<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Enum\FinancialReportSyncMode;
use DateTimeImmutable;

interface WbFinancialReportSyncPlannerInterface
{
    public function planDaily(?string $companyId = null, ?string $connectionId = null, bool $force = false): int;

    public function planRefresh14Days(?string $companyId = null, ?string $connectionId = null): int;

    public function planRange(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        FinancialReportSyncMode $mode,
        ?string $companyId = null,
        ?string $connectionId = null,
        bool $forceRefresh = false,
    ): int;

    public function planMissing(?string $companyId = null, ?string $connectionId = null, int $maxDays = 14): int;

    public function planInitial(?string $companyId = null, ?string $connectionId = null, ?DateTimeImmutable $startFrom = null): int;
}
