<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;

final readonly class MarketplaceOtherProcessor implements MarketplaceRawProcessorInterface
{
    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::OTHER;
        }

        return $kind === StagingRecordType::OTHER->value;
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        // TODO: save to staging table for unknown operations
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return 0;
    }
}
