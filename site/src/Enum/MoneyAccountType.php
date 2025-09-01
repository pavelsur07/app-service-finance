<?php

namespace App\Enum;

enum MoneyAccountType: string
{
    case BANK = 'bank';
    case CASH = 'cash';
    case EWALLET = 'ewallet';
}
