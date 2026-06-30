<?php

namespace App\Cash\Enum\Transaction;

enum CashflowFlowKind: string
{
    case OPERATING = 'OPERATING';
    case INVESTING = 'INVESTING';
    case FINANCING = 'FINANCING';
    case TECHNICAL = 'TECHNICAL';

    public function label(): string
    {
        return match ($this) {
            self::OPERATING => 'Операционная деятельность',
            self::INVESTING => 'Инвестиционная деятельность',
            self::FINANCING => 'Финансовая деятельность',
            self::TECHNICAL => 'Технические операции',
        };
    }
}
