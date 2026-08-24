<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

enum ProductStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case DISCONTINUED = 'discontinued';
}
