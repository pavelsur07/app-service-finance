<?php

namespace App\Marketplace\DTO;

use App\Marketplace\Enum\MarketplaceType;

readonly class ReturnData
{
    public function __construct(
        public MarketplaceType $marketplace,
        public string $marketplaceSku,
        public \DateTimeImmutable $returnDate,
        public int $quantity,
        public string $refundAmount,
        public ?string $returnReason = null,
        public ?string $returnLogisticsCost = null,
        public ?string $externalReturnId = null,
        public ?array $rawData = null
    ) {}
}
