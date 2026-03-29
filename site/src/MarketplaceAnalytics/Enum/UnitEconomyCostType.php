<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Enum;

enum UnitEconomyCostType: string
{
    case LOGISTICS_TO = 'logistics_to';
    case LOGISTICS_BACK = 'logistics_back';
    case STORAGE = 'storage';
    case ADVERTISING_CPC = 'advertising_cpc';
    case ADVERTISING_OTHER = 'advertising_other';
    case ADVERTISING_EXTERNAL = 'advertising_external';
    case COMMISSION = 'commission';
    case OTHER = 'other';

    public function isAdvertising(): bool
    {
        return match ($this) {
            self::ADVERTISING_CPC, self::ADVERTISING_OTHER, self::ADVERTISING_EXTERNAL => true,
            default => false,
        };
    }
}
