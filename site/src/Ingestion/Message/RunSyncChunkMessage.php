<?php

declare(strict_types=1);

namespace App\Ingestion\Message;

use Webmozart\Assert\Assert;

final readonly class RunSyncChunkMessage implements CompanyAwareMessage
{
    public function __construct(
        public string $companyId,
        public string $jobId,
    ) {
        Assert::uuid($this->companyId);
        Assert::uuid($this->jobId);
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}
