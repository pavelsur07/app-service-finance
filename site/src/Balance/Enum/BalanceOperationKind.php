<?php

declare(strict_types=1);

namespace App\Balance\Enum;

enum BalanceOperationKind: string
{
    case OPENING = 'opening';
    case OPERATION = 'operation';
    case CORRECTION = 'correction';
    case REVERSAL = 'reversal';
}
