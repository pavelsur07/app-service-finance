<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum OrderStatus: string
{
    case ORDERED = 'ordered';
    case DELIVERED = 'delivered';
    case RETURNED = 'returned';
    case CANCELLED = 'cancelled';
}
