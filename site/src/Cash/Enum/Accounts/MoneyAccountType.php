<?php

declare(strict_types=1);

namespace App\Cash\Enum\Accounts;

enum MoneyAccountType: string
{
    case BANK = 'bank';
    case CASH = 'cash';
    case EWALLET = 'ewallet';
    case CRYPTO_WALLET = 'crypto_wallet';
}
