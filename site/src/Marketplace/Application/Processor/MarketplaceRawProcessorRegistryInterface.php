<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;

interface MarketplaceRawProcessorRegistryInterface
{
    public function get(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): MarketplaceRawProcessorInterface;
}
