<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum MatchLogic: string
{
    case ALL = 'all';
    case ANY = 'any';
}
