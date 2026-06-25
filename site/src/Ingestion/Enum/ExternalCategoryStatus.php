<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum ExternalCategoryStatus: string
{
    case NEW = 'new';
    case MAPPED = 'mapped';
    case IGNORED = 'ignored';
    case DEPRECATED = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Требует классификации',
            self::MAPPED => 'Сопоставлена',
            self::IGNORED => 'Игнорируется',
            self::DEPRECATED => 'Устарела',
        };
    }
}
