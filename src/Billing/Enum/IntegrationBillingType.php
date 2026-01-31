<?php

namespace App\Billing\Enum;

enum IntegrationBillingType: string
{
    case INCLUDED = 'INCLUDED';
    case ADDON = 'ADDON';
}
