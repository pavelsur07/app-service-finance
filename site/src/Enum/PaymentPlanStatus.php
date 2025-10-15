<?php

namespace App\Enum;

enum PaymentPlanStatus: string
{
    case DRAFT = 'DRAFT';
    case PLANNED = 'PLANNED';
    case APPROVED = 'APPROVED';
    case PAID = 'PAID';
    case CANCELED = 'CANCELED';
}
