<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Enum;

enum ProjectionStatus: string
{
    case MAPPED = 'mapped';
    /** Правило компании без статьи: в ОПиУ не идёт. */
    case EXCLUDED = 'excluded';
    /** Правила нет. */
    case UNMAPPED = 'unmapped';
}
