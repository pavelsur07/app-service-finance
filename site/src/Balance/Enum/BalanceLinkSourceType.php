<?php

declare(strict_types=1);

namespace App\Balance\Enum;

enum BalanceLinkSourceType: string
{
    case MONEY_ACCOUNTS_TOTAL = 'money_accounts_total';
    case MONEY_FUNDS_TOTAL = 'money_funds_total';
}
