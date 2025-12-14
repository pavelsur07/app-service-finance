<?php

namespace App\Balance\Enum;

enum BalanceLinkSourceType: string
{
    case MONEY_ACCOUNTS_TOTAL = 'money_accounts_total';
    case MONEY_FUNDS_TOTAL = 'money_funds_total';
}
