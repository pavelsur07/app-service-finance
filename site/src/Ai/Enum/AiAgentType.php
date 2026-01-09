<?php

declare(strict_types=1);

namespace App\Ai\Enum;

enum AiAgentType: string
{
    case CASHFLOW = 'cashflow';
    case PNL = 'pnl';
    case PAYMENT_CALENDAR = 'payment_calendar';
    case QA = 'qa';

    public function label(): string
    {
        return match ($this) {
            self::CASHFLOW => 'Анализ ДДС',
            self::PNL => 'План/факт P&L',
            self::PAYMENT_CALENDAR => 'Платёжный календарь',
            self::QA => 'Вопрос-ответ',
        };
    }
}
