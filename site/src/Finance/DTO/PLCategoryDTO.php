<?php

declare(strict_types=1);

namespace App\Finance\DTO;

use App\Finance\Enum\PLCategoryType;

/**
 * Scalar DTO категории ОПиУ для межмодульного общения.
 * Не содержит Entity — безопасно для передачи между модулями.
 */
final class PLCategoryDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $level,
        public readonly ?string $parentId,
        public readonly int $sortOrder,
        public readonly ?string $code,
        public readonly PLCategoryType $type,
    ) {
    }

    /**
     * Отступ для отображения в select (визуальная иерархия).
     */
    public function getIndentedName(): string
    {
        if ($this->level <= 1) {
            return $this->name;
        }

        return str_repeat('— ', $this->level - 1).$this->name;
    }
}
