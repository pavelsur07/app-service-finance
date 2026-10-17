<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Судьба категории затрат при проведении в ОПиУ — для предпросмотра.
 */
enum PlPreviewCostStatus: string
{
    case MAPPED = 'mapped';
    case EXCLUDED = 'excluded';
    case UNMAPPED = 'unmapped';

    public function label(): string
    {
        return match ($this) {
            self::MAPPED => 'Размечено в ОПиУ',
            self::EXCLUDED => 'Исключено из ОПиУ',
            self::UNMAPPED => 'Не размечено',
        };
    }
}
