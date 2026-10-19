<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Группа из закрытого списка источника — для выбора в формах.
 */
final readonly class CategoryGroupView
{
    public function __construct(
        public string $code,
        public string $label,
    ) {
    }
}
