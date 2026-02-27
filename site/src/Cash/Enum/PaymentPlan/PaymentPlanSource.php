<?php

namespace App\Cash\Enum\PaymentPlan;

enum PaymentPlanSource: string
{
    case MANUAL = 'MANUAL';
    case API_MOYSKLAD = 'API_MOYSKLAD';
    case XLS_IMPORT = 'XLS_IMPORT';
    case API_1C = 'API_1C';
}
