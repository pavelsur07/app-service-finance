<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer\Contract;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;

interface RowClassifierInterface
{
    public function supports(MarketplaceType $type): bool;

    public function classify(array $rawRow): StagingRecordType;
}
