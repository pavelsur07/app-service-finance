<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\ListingResolution;

interface BulkListingResolverInterface extends ListingResolverInterface
{
    /**
     * @param array<int|string, array<string, mixed>> $sourceDataRows
     *
     * @return array<int|string, ListingResolution|null>
     */
    public function resolveMany(string $companyId, array $sourceDataRows): array;
}
