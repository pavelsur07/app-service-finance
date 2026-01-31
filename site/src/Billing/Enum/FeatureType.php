<?php

namespace App\Billing\Enum;

enum FeatureType: string
{
    case BOOLEAN = 'BOOLEAN';
    case LIMIT = 'LIMIT';
    case ENUM = 'ENUM';
}
