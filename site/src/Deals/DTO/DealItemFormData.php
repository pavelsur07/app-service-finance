<?php

declare(strict_types=1);

namespace App\Deals\DTO;

use App\Deals\Enum\DealItemKind;
use Symfony\Component\Validator\Constraints as Assert;

final class DealItemFormData
{
    #[Assert\NotBlank]
    public ?string $name = null;

    #[Assert\NotNull]
    public ?DealItemKind $kind = null;

    public ?string $unit = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?string $qty = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?string $price = null;
}
