<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum LocationType: string
{
    case MpWarehouse = 'mp_warehouse';
    case MpAcceptance = 'mp_acceptance';
    case MpInTransitToCustomer = 'mp_in_transit_to_customer';
    case MpInTransitFromCustomer = 'mp_in_transit_from_customer';
}
