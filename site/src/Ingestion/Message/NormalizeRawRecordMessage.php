<?php

declare(strict_types=1);

namespace App\Ingestion\Message;

use Webmozart\Assert\Assert;

final readonly class NormalizeRawRecordMessage implements CompanyAwareMessage
{
    public function __construct(
        public string $rawRecordId,
        public string $companyId,
    ) {
        Assert::uuid($this->rawRecordId);
        Assert::uuid($this->companyId);
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}
