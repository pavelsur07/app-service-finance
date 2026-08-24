<?php

declare(strict_types=1);

namespace App\Deals\Enum;

enum DealType: string
{
    case SALE = 'sale';
    case SERVICE = 'service';
    case WORK = 'work';
    case CONTRACT = 'contract';
    case PROJECT = 'project';
}
