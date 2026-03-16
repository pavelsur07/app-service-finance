<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum JobType: string
{
    case BARCODE_SYNC_OZON    = 'barcode_sync_ozon';
    case COST_PRICE_IMPORT    = 'cost_price_import';

    public function getLabel(): string
    {
        return match ($this) {
            self::BARCODE_SYNC_OZON => 'Синхронизация баркодов Ozon',
            self::COST_PRICE_IMPORT => 'Импорт себестоимости из файла',
        };
    }
}
