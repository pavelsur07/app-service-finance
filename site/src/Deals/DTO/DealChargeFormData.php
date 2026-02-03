<?php

declare(strict_types=1);

namespace App\Deals\DTO;

use App\Deals\Entity\ChargeType;
use Symfony\Component\Validator\Constraints as Assert;

final class DealChargeFormData
{
    #[Assert\NotNull]
    public ?ChargeType $chargeType = null;

    #[Assert\NotNull]
    public \DateTimeImmutable $recognizedAt;

    #[Assert\Positive]
    public string $amount = '0';

    #[Assert\Length(max: 500)]
    public ?string $comment = null;

    public function __construct()
    {
        $this->recognizedAt = new \DateTimeImmutable();
    }
}
