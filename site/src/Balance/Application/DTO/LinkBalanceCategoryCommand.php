<?php

declare(strict_types=1);

namespace App\Balance\Application\DTO;

use App\Balance\Enum\BalanceLinkSourceType;

final readonly class LinkBalanceCategoryCommand
{
    public function __construct(
        public string $categoryId,
        public BalanceLinkSourceType $sourceType,
        public ?string $sourceId,
        public int $sign = 1,
        public int $position = 0,
    ) {
    }
}
