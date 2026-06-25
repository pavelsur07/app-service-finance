<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum ExternalCategoryMappingStatus: string
{
    case ACTIVE = 'active';
    case NEEDS_REVIEW = 'needs_review';
    case DISABLED = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Активна',
            self::NEEDS_REVIEW => 'Требует проверки',
            self::DISABLED => 'Отключена',
        };
    }
}
