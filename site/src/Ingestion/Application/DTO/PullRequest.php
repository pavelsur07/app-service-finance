<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class PullRequest
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $shopRef,
        public string $resourceType,
        public ?string $cursorValue,
        public ?\DateTimeImmutable $windowFrom,
        public ?\DateTimeImmutable $windowTo,
        public string $syncJobId,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::notEmpty($this->resourceType);
        Assert::uuid($this->syncJobId);
    }
}
