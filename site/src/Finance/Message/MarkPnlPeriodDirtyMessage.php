<?php

declare(strict_types=1);

namespace App\Finance\Message;

use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Ingestion\Message\CompanyAwareMessage;
use Webmozart\Assert\Assert;

final readonly class MarkPnlPeriodDirtyMessage implements CompanyAwareMessage
{
    public function __construct(
        public string $companyId,
        public int $year,
        public int $month,
        public string $shopRef,
        public string $reasonValue,
    ) {
        Assert::uuid($this->companyId);
        Assert::range($this->year, 2020, 2100);
        Assert::range($this->month, 1, 12);
        PLDirtyPeriodReason::from($this->reasonValue);
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}
