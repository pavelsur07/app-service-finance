<?php

namespace App\Marketplace\Enum;

enum MarketplaceType: string
{
    case WILDBERRIES = 'wildberries';
    case OZON = 'ozon';
    case YANDEX_MARKET = 'yandex_market';
    case SBER_MEGAMARKET = 'sber_megamarket';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::WILDBERRIES => 'Wildberries',
            self::OZON => 'Ozon',
            self::YANDEX_MARKET => 'Яндекс.Маркет',
            self::SBER_MEGAMARKET => 'СберМегаМаркет',
        };
    }
}
