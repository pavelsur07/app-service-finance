<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum MarketplaceConnectionType: string
{
    case SELLER = 'seller';
    case PERFORMANCE = 'performance';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::SELLER => 'Основное',
            self::PERFORMANCE => 'Реклама (Performance)',
        };
    }
}
