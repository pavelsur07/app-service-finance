<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum DefaultCostMappingConfidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
}
