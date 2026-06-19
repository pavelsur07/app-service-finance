<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

use App\Ingestion\Enum\PLDirtyPeriodReason;
use Webmozart\Assert\Assert;

final readonly class MarkPnlPeriodDirtyCommand
{
    public function __construct(
        public string $companyId,
        public int $year,
        public int $month,
        public string $shopRef,
        public PLDirtyPeriodReason $reason,
    ) {
        Assert::uuid($this->companyId);
        Assert::range($this->year, 2020, 2100);
        Assert::range($this->month, 1, 12);
    }
}
