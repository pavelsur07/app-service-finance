<?php

declare(strict_types=1);

namespace App\Deals\DTO;

use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealType;
use App\Entity\Counterparty;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateDealFormData
{
    #[Assert\NotNull]
    public \DateTimeImmutable $recognizedAt;

    #[Assert\Length(max: 255)]
    public ?string $title = null;

    #[Assert\NotNull]
    #[Assert\Choice(callback: [DealType::class, 'cases'])]
    public DealType $type;

    #[Assert\NotNull]
    #[Assert\Choice(callback: [DealChannel::class, 'cases'])]
    public DealChannel $channel;

    public ?Counterparty $counterpartyId = null;

    public function __construct()
    {
        $this->recognizedAt = new \DateTimeImmutable();
    }
}
