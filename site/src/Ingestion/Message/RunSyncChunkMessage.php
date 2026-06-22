<?php

declare(strict_types=1);

namespace App\Ingestion\Message;

use Webmozart\Assert\Assert;

final readonly class RunSyncChunkMessage implements CompanyAwareMessage
{
    public function __construct(
        public string $companyId,
        public string $jobId,
        public ?string $cursorValue = null,
    ) {
        Assert::uuid($this->companyId);
        Assert::uuid($this->jobId);
        if (null !== $this->cursorValue) {
            Assert::notEmpty($this->cursorValue);
        }
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}
