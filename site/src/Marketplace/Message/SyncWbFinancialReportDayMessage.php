<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

final readonly class SyncWbFinancialReportDayMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
        public string $businessDate,
        public string $mode,
        public bool $forceRefresh,
    ) {}
}
