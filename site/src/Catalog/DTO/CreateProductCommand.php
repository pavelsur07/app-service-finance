<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

use App\Catalog\Enum\ProductStatus;

final class CreateProductCommand
{
    public ?string $name = null;

    public ?string $sku = null;

    public ?ProductStatus $status = null;

    public ?string $description = null;

    public ?string $purchasePrice = null;
}
