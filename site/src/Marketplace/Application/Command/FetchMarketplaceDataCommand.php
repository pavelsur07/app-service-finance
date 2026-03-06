<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Enum\MarketplaceType;

final readonly class FetchMarketplaceDataCommand
{
    public function __construct(
        public string $companyId,
        public MarketplaceType $type,
        public \DateTimeImmutable $dateFrom,
    ) {
    }
}
