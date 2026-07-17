<?php

declare(strict_types=1);

namespace App\Company\Enum;

enum FinancialResponsibilityCenterStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
