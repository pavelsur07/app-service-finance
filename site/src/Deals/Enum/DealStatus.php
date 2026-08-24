<?php

declare(strict_types=1);

namespace App\Deals\Enum;

enum DealStatus: string
{
    case DRAFT = 'draft';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
    case CLOSED = 'closed';
}
