<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\ListingResolution;
use App\Ingestion\Enum\IngestSource;

interface ListingResolverInterface
{
    public function supports(IngestSource $source): bool;

    /**
     * @param array<string, mixed> $sourceData
     */
    public function resolve(string $companyId, array $sourceData): ?ListingResolution;
}
