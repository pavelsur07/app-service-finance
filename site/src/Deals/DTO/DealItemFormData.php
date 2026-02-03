<?php

declare(strict_types=1);

namespace App\Deals\DTO;

use App\Catalog\Entity\Product;
use Symfony\Component\Validator\Constraints as Assert;

final class DealItemFormData
{
    #[Assert\NotNull]
    public ?Product $productId = null;

    #[Assert\Positive]
    public string $qty = '0';

    #[Assert\Positive]
    public string $price = '0';
}
