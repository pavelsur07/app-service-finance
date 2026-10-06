<?php

declare(strict_types=1);

namespace App\Balance\DTO;

use App\Balance\Enum\BalanceCategoryType;

final readonly class BalanceStructureTemplateNode
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $code,
        public string $name,
        public BalanceCategoryType $type,
        public string $kind,
        public int $sortOrder,
        public array $children = [],
    ) {
    }
}
