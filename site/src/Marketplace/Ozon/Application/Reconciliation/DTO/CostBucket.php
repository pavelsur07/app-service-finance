<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\DTO;

use App\Shared\Domain\ValueObject\Money;

/**
 * Нетто-расход по категории: затраты минус сторно, положительное число — расход продавца.
 */
final readonly class CostBucket
{
    public function __construct(
        public Money $net,
        public int $count,
    ) {
    }

    public function plus(self $other): self
    {
        return new self($this->net->add($other->net), $this->count + $other->count);
    }
}
