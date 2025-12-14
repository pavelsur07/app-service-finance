<?php

namespace App\Balance\Enum;

enum BalanceCategoryType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
}
