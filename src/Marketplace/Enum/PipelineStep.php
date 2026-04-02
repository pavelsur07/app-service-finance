<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum PipelineStep: string
{
    case SALES   = 'sales';
    case RETURNS = 'returns';
    case COSTS   = 'costs';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALES   => 'Продажи',
            self::RETURNS => 'Возвраты',
            self::COSTS   => 'Затраты',
        };
    }
}
