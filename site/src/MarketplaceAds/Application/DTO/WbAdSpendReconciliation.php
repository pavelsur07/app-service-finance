<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

use App\Shared\Domain\ValueObject\Money;

final readonly class WbAdSpendReconciliation
{
    public function __construct(
        public Money $documentTotal,
        public Money $lineTotal,
        public Money $withoutLineTotal,
        public Money $unallocatedTotal,
        public Money $unmappedTotal,
        public int $unmappedCount,
    ) {
    }

    public function reconciles(Money $sourceTotal, Money $sourceUnallocatedTotal): bool
    {
        return $sourceTotal->equals($this->documentTotal)
            && $this->documentTotal->equals($this->lineTotal->add($this->withoutLineTotal))
            && $this->withoutLineTotal->equals($this->unallocatedTotal->add($this->unmappedTotal))
            && $sourceUnallocatedTotal->equals($this->unallocatedTotal);
    }
}
