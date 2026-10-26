<?php

declare(strict_types=1);

namespace App\BankSync\Enum;

/**
 * Банк, с которым работает адаптер. Значение хранится в `bank_sync_connection.bank_code`
 * и пишется в `cash_transaction.import_source` (`alfa`), поэтому не меняется.
 */
enum BankCode: string
{
    case ALFA = 'alfa';

    public function label(): string
    {
        return match ($this) {
            self::ALFA => 'Альфа-Банк',
        };
    }
}
