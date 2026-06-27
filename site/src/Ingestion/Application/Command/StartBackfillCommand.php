<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use App\Ingestion\Enum\IngestSource;
use Webmozart\Assert\Assert;

final readonly class StartBackfillCommand
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public IngestSource $source,
        public string $resourceType,
        public string $shopRef,
        public \DateTimeImmutable $windowFrom,
        public \DateTimeImmutable $windowTo,
        public int $initialDelaySeconds = 0,
        public int $chunkDelayStepSeconds = 0,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::notEmpty($this->resourceType);
        Assert::lessThanEq($this->windowFrom, $this->windowTo);
        Assert::range($this->initialDelaySeconds, 0, 86400);
        Assert::range($this->chunkDelayStepSeconds, 0, 86400);
    }
}
