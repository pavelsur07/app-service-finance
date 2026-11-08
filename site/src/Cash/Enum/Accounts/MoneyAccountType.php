<?php

declare(strict_types=1);

namespace App\Cash\Enum\Accounts;

enum MoneyAccountType: string
{
    case BANK = 'bank';
    case CASH = 'cash';
    case EWALLET = 'ewallet';
    case CRYPTO_WALLET = 'crypto_wallet';

    public function label(): string
    {
        return match ($this) {
            self::BANK => 'Банк',
            self::CASH => 'Касса',
            self::EWALLET => 'Кошелёк',
            self::CRYPTO_WALLET => 'Крипто-кошелёк',
        };
    }
}
