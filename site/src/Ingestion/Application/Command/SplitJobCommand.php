<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class SplitJobCommand
{
    public function __construct(
        public string $parentJobId,
        public string $companyId,
        public int $chunkSizeInDays = 7,
        public int $initialDelaySeconds = 0,
        public int $chunkDelayStepSeconds = 0,
    ) {
        Assert::uuid($this->parentJobId);
        Assert::uuid($this->companyId);
        Assert::range($this->chunkSizeInDays, 1, 90);
        Assert::range($this->initialDelaySeconds, 0, 86400);
        Assert::range($this->chunkDelayStepSeconds, 0, 86400);
    }
}
