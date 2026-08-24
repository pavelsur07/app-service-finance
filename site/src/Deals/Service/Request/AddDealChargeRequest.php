<?php

declare(strict_types=1);

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
