<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum ExternalSystemType: string
{
    case Wildberries = 'wildberries';
    case Ozon = 'ozon';
}
