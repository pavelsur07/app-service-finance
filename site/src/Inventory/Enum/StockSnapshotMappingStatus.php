<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum StockSnapshotMappingStatus: string
{
    case Mapped = 'mapped';
    case Unmapped = 'unmapped';
    case Ambiguous = 'ambiguous';
}

