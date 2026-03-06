<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.marketplace.raw_processor')]
interface MarketplaceRawProcessorInterface
{
    public function supports(string|StagingRecordType $type, string $kind = ''): bool;

    public function process(string $companyId, string $rawDocId): int;

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void;
}
