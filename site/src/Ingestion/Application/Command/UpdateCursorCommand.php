<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class UpdateCursorCommand
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $resourceType,
        public string $shopRef,
        public string $newCursorValue,
        public string $syncJobId,
        public ?\DateTimeImmutable $fetchedAt = null,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::notEmpty($this->resourceType);
        Assert::notEmpty($this->newCursorValue);
        Assert::uuid($this->syncJobId);
    }
}
