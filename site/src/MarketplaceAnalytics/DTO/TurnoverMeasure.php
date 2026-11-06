<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\DTO;

use App\MarketplaceAnalytics\Enum\TurnoverBucket;

/** Результат StockTurnoverPolicy::measure() для одного листинга. */
final readonly class TurnoverMeasure
{
    public function __construct(
        public float $salesPerDay,
        public ?int $daysOfCover,
        public TurnoverBucket $bucket,
    ) {
    }
}
