<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRulePairIssue: string
{
    case CONFLICT = 'PAIR_CONFLICT';
    case INCOMPLETE = 'PAIR_INCOMPLETE';
    case UNAVAILABLE = 'PAIR_UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::CONFLICT => 'Конфликт проекта или ЦФО: пара не будет изменена',
            self::INCOMPLETE => 'Для изменения требуются и проект, и ЦФО',
            self::UNAVAILABLE => 'Итоговая пара проекта и ЦФО недоступна',
        };
    }
}
