<?php

declare(strict_types=1);

namespace App\Finance\Enum;

enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case TO_DELETE = 'TO_DELETE';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::ACTIVE => 'Активен',
            self::INACTIVE => 'Неактивен',
            self::TO_DELETE => 'На удаление',
        };
    }
}
