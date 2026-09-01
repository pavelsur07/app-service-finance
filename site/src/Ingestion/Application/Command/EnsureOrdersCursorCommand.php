<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class EnsureOrdersCursorCommand
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $resourceType,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::notEmpty($this->resourceType);
    }
}
