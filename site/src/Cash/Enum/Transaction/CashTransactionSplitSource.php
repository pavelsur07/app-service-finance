<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

/**
 * Кто проставил строку разбивки.
 *
 * Для строк это единственный источник правды о происхождении: провенанс-резолвер
 * (CashTransactionAutoRuleProvenanceResolver) восстанавливает его из истории AuditLog
 * по скалярному полю и на коллекции не работает.
 */
enum CashTransactionSplitSource: string
{
    case MANUAL = 'manual';
    case AUTO = 'auto';
    case IMPORT = 'import';

    public function isAutoAssigned(): bool
    {
        return self::AUTO === $this;
    }
}
