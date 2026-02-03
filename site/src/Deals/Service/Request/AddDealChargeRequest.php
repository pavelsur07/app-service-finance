<?php

namespace App\Deals\Service\Request;

final class AddDealChargeRequest
{
    public function __construct(
        public readonly \DateTimeImmutable $recognizedAt,
        public readonly string $amount,
        public readonly string $chargeTypeId,
        public readonly ?string $comment = null,
    ) {
    }
}
