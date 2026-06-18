<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class MarkJobCompletedCommand
{
    public function __construct(
        public string $jobId,
        public string $companyId,
    ) {
        Assert::uuid($this->jobId);
        Assert::uuid($this->companyId);
    }
}
