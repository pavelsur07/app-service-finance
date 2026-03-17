<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum CloseStage: string
{
    case SALES_RETURNS = 'sales_returns';
    case COSTS         = 'costs';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALES_RETURNS => 'Продажи и возвраты',
            self::COSTS         => 'Затраты',
        };
    }
}
