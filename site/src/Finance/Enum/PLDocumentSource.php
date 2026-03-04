<?php

declare(strict_types=1);

namespace App\Finance\Enum;

/**
 * Источник данных для документа ОПиУ.
 * Позволяет фильтровать и пересоздавать документы по источнику.
 */
enum PLDocumentSource: string
{
    case MARKETPLACE_WB = 'marketplace_wb';
    case MARKETPLACE_OZON = 'marketplace_ozon';
    case MARKETPLACE_YANDEX = 'marketplace_yandex';
    case MARKETPLACE_SBER = 'marketplace_sber';
    case MANUAL = 'manual';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::MARKETPLACE_WB => 'Wildberries',
            self::MARKETPLACE_OZON => 'Ozon',
            self::MARKETPLACE_YANDEX => 'Яндекс.Маркет',
            self::MARKETPLACE_SBER => 'СберМегаМаркет',
            self::MANUAL => 'Ручной ввод',
        };
    }

    public function isMarketplace(): bool
    {
        return $this !== self::MANUAL;
    }
}
