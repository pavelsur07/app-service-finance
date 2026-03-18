<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Enum;

enum MarketplaceAnalyticsStatus: string
{
    case NEW = 'new';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
