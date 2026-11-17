<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashAutoRuleQualityMetric: string
{
    case ASSIGNED = 'assigned';
    case CORRECTED = 'corrected';
    case CONFLICTS = 'conflicts';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNED => 'Назначенные операции',
            self::CORRECTED => 'Исправленные человеком операции',
            self::CONFLICTS => 'Конфликты по статье',
        };
    }
}
