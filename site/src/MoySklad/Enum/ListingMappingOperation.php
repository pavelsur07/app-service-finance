<?php

declare(strict_types=1);

namespace App\MoySklad\Enum;

enum ListingMappingOperation: string
{
    case SET = 'set';
    case CLEAR = 'clear';

    public function label(): string
    {
        return match ($this) {
            self::SET => 'Назначение', self::CLEAR => 'Снятие связи',
        };
    }
}
