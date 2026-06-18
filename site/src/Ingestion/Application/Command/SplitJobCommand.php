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
    ) {
        Assert::uuid($this->parentJobId);
        Assert::uuid($this->companyId);
        Assert::range($this->chunkSizeInDays, 1, 90);
    }
}
