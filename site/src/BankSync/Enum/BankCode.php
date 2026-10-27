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

    /**
     * Банк выдаёт клиенту токен, который можно ввести в форму. У Альфы токен клиента — это
     * Alfa API Direct (только своя организация партнёра), для SaaS вход только через Alfa ID.
     */
    public function supportsStaticToken(): bool
    {
        return match ($this) {
            self::ALFA => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ALFA => 'Альфа-Банк',
        };
    }
}
