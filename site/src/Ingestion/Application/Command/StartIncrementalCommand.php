<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use App\Ingestion\Enum\IngestSource;
use Webmozart\Assert\Assert;

final readonly class StartIncrementalCommand
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public IngestSource $source,
        public string $resourceType,
        public string $shopRef,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::notEmpty($this->resourceType);
    }
}
