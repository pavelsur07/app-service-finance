<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum AdvertisingType: string
{
    case CPC = 'cpc';
    case OTHER = 'other';
    case EXTERNAL = 'external';
}
