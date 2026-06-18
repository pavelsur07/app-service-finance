<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

use Webmozart\Assert\Assert;

final readonly class RebuildPnlPeriodCommand
{
    public function __construct(
        public string $companyId,
        public int $year,
        public int $month,
        public string $shopRef = '',
    ) {
        Assert::uuid($this->companyId);
        Assert::range($this->year, 2020, 2100);
        Assert::range($this->month, 1, 12);
    }
}
