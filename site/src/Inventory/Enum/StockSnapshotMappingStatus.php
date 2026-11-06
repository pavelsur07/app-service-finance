<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum StockSnapshotMappingStatus: string
{
    case Mapped = 'mapped';
    case Unmapped = 'unmapped';
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::Mapped => 'Сопоставлен',
            self::Unmapped => 'Не сопоставлен',
            self::Ambiguous => 'Неоднозначно',
        };
    }
}
