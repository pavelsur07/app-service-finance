<?php

namespace App\Deals\Enum;

enum DealItemKind: string
{
    case GOOD = 'good';
    case SERVICE = 'service';
    case WORK = 'work';
    case TRIP = 'trip';
}
