<?php

declare(strict_types=1);

namespace App\Deals\DTO;

use App\Deals\Enum\DealItemKind;
use Symfony\Component\Validator\Constraints as Assert;

final class DealItemFormData
{
    #[Assert\NotBlank]
    public string $name = '';

    #[Assert\NotNull]
    public ?DealItemKind $kind = DealItemKind::GOOD;

    public ?string $unit = null;

    #[Assert\Positive]
    public string $qty = '0';

    #[Assert\Positive]
    public string $price = '0';
}
