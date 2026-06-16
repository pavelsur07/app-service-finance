<?php

declare(strict_types=1);

namespace App\Ingestion\DTO;

use App\Ingestion\Enum\IngestSource;
use Webmozart\Assert\Assert;

final readonly class RawBatch
{
    /**
     * @param iterable<array<string, mixed>> $rows
     */
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $shopRef,
        public IngestSource $source,
        public string $resourceType,
        public string $externalId,
        public string $syncJobId,
        public \DateTimeImmutable $fetchedAt,
        public iterable $rows,
    ) {
        Assert::uuid($this->companyId);
        Assert::notEmpty($this->connectionRef);
        Assert::notEmpty($this->shopRef);
        Assert::notEmpty($this->resourceType);
        Assert::notEmpty($this->externalId);
        Assert::notEmpty($this->syncJobId);
    }
}
