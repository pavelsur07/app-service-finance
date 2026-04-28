<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum StockStatus: string
{
    case Available = 'available';
    case InTransitToCustomer = 'in_transit_to_customer';
    case InTransitFromCustomer = 'in_transit_from_customer';
    case OnAcceptance = 'on_acceptance';
    case Defect = 'defect';
    case Blocked = 'blocked';
}
