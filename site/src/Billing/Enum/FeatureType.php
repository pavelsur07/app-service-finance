<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum FeatureType: string
{
    case BOOLEAN = 'BOOLEAN';
    case LIMIT = 'LIMIT';
    case ENUM = 'ENUM';
}
