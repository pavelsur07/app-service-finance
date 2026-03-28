<?php

declare(strict_types=1);

namespace App\Cash\Enum\PaymentPlan;

enum PaymentPlanStatus: string
{
    case DRAFT = 'DRAFT';
    case PLANNED = 'PLANNED';
    case APPROVED = 'APPROVED';
    case PAID = 'PAID';
    case CANCELED = 'CANCELED';
}
