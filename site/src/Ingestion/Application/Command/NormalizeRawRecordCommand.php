<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class NormalizeRawRecordCommand
{
    public function __construct(
        public string $rawRecordId,
        public string $companyId,
        public bool $forceReplay = false,
    ) {
        Assert::uuid($this->rawRecordId);
        Assert::uuid($this->companyId);
    }
}
