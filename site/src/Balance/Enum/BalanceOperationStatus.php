<?php

declare(strict_types=1);

namespace App\Balance\Enum;

enum BalanceOperationStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
}
