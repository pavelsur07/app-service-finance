<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;

interface RowClassifierRegistryInterface
{
    public function get(MarketplaceType $type, ?MarketplaceRawFormat $format = null): RowClassifierInterface;
}
