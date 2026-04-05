<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Обязательные шаги daily raw pipeline.
 *
 * В daily-контракт включены только sales/returns/costs.
 * Realization обрабатывается отдельным monthly flow.
 */
enum PipelineStep: string
{
    case SALES = 'sales';
    case RETURNS = 'returns';
    case COSTS = 'costs';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALES => 'Продажи',
            self::RETURNS => 'Возвраты',
            self::COSTS => 'Затраты',
        };
    }
}
