<?php

namespace App\Deals\Enum;

enum DealType: string
{
    case SALE = 'sale';
    case SERVICE = 'service';
    case WORK = 'work';
    case CONTRACT = 'contract';
    case PROJECT = 'project';
}
