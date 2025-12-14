<?php

namespace App\Balance\DTO;

final class BalanceRowView
{
    /**
     * @param array<string,float> $amountsByCurrency
     * @param list<self> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public int $level,
        public int $sortOrder,
        public bool $isVisible,
        public array $amountsByCurrency,
        public array $children = [],
    ) {
    }
}
