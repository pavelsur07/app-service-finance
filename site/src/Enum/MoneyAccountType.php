<?php

namespace App\Enum;

enum MoneyAccountType: string
{
    case BANK = 'bank';
    case CASH = 'cash';
    case EWALLET = 'ewallet';
    case CRYPTO_WALLET = 'crypto_wallet';
}
