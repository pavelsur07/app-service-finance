<?php

declare(strict_types=1);

namespace App\Balance\Enum;

enum BalanceCategoryType: string
{
    case ASSET = 'asset';
    case PASSIVE = 'passive';
}
