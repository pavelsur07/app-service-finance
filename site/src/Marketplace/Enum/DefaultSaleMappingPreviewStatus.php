<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum DefaultSaleMappingPreviewStatus: string
{
    case WILL_CREATE = 'will_create';
    case SKIPPED_EXISTING = 'skipped_existing';
    case MISSING_PL_CATEGORY = 'missing_pl_category';
    case INVALID_TARGET_CATEGORY = 'invalid_target_category';

    public function getLabel(): string
    {
        return match ($this) {
            self::WILL_CREATE => 'Будет создано',
            self::SKIPPED_EXISTING => 'Уже настроено',
            self::MISSING_PL_CATEGORY => 'Нет статьи ОПиУ',
            self::INVALID_TARGET_CATEGORY => 'Невалидная статья ОПиУ',
        };
    }
}
