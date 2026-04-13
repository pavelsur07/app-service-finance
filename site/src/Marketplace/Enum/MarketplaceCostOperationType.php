<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum MarketplaceCostOperationType: string
{
    case CHARGE = 'charge';
    case STORNO = 'storno';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::CHARGE => 'Начисление',
            self::STORNO => 'Сторно',
        };
    }
}
