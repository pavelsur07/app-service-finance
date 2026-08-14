<?php

declare(strict_types=1);

namespace App\Balance\DTO;

final class BalanceRowView
{
    /**
     * @param array<string, string> $amountsByCurrency currency => decimal string
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
