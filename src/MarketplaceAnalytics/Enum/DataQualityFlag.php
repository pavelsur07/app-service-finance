<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Enum;

enum DataQualityFlag: string
{
    case COST_PRICE_MISSING = 'cost_price_missing';
    case API_ADVERTISING_MISSING = 'api_advertising_missing';
    case API_STORAGE_MISSING = 'api_storage_missing';
    case API_ORDERS_MISSING = 'api_orders_missing';
    case DATA_DELAYED = 'data_delayed';
}
