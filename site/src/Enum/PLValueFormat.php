<?php

declare(strict_types=1);

namespace App\Enum;

enum PLValueFormat: string
{
    case MONEY   = 'MONEY';
    case PERCENT = 'PERCENT';
    case RATIO   = 'RATIO';
    case QTY     = 'QTY';
}
