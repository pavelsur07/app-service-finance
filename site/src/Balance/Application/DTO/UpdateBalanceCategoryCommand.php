<?php

declare(strict_types=1);

namespace App\Balance\Application\DTO;

use App\Balance\Enum\BalanceCategoryType;

final readonly class UpdateBalanceCategoryCommand
{
    public function __construct(
        public string $id,
        public string $name,
        public BalanceCategoryType $type,
        public ?string $parentId,
        public ?string $code,
        public bool $isVisible,
    ) {
    }
}
