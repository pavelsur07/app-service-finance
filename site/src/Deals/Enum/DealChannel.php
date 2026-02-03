<?php

namespace App\Deals\Enum;

enum DealChannel: string
{
    case SHOP = 'shop';
    case RETAIL = 'retail';
    case WHOLESALE = 'wholesale';
    case SERVICES = 'services';
    case OTHER = 'other';
}
