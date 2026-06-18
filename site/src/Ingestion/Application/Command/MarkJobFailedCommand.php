<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class MarkJobFailedCommand
{
    public function __construct(
        public string $jobId,
        public string $companyId,
        public string $reason,
    ) {
        Assert::uuid($this->jobId);
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->reason);
        Assert::maxLength($this->reason, 2000);
    }
}
