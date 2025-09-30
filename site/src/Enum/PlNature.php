<?php

declare(strict_types=1);

namespace App\Enum;

enum PlNature: string
{
    case INCOME = 'INCOME';
    case EXPENSE = 'EXPENSE';

    public function sign(): int
    {
        return $this === self::INCOME ? 1 : -1;
    }
}
