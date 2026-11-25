<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum IntragroupDecisionType: string
{
    case PAIR = 'PAIR';
    case UNPAIR = 'UNPAIR';
}
