<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum TransactionDirection: string
{
    case IN = 'in';
    case OUT = 'out';

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Поступление',
            self::OUT => 'Списание',
        };
    }
}
