<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Enum;

enum SnapshotRecalcScope: string
{
    case SINGLE_DAY = 'single_day';
    case DATE_RANGE = 'date_range';
}
