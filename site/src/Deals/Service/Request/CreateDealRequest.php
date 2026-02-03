<?php

namespace App\Deals\Service\Request;

use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealType;

final class CreateDealRequest
{
    public function __construct(
        public readonly DealType $type,
        public readonly DealChannel $channel,
        public readonly \DateTimeImmutable $recognizedAt,
        public readonly ?string $number = null,
        public readonly ?string $title = null,
        public readonly ?string $counterpartyId = null,
        public readonly ?\DateTimeImmutable $occurredAt = null,
        public readonly ?string $currency = null,
    ) {
    }
}
