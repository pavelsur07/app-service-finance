<?php

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRuleSkipReason: string
{
    case DELETED = 'SKIPPED_DELETED';
    case LOCKED_PERIOD = 'SKIPPED_LOCKED_PERIOD';
    case MANUAL_SPLIT = 'SKIPPED_MANUAL_SPLIT';

    public function label(): string
    {
        return match ($this) {
            self::DELETED => 'Удалённые операции нельзя изменять.',
            self::LOCKED_PERIOD => 'Операции закрытого периода нельзя изменять.',
            self::MANUAL_SPLIT => 'Операция разбита по статьям вручную — правило её не трогает.',
        };
    }
}
