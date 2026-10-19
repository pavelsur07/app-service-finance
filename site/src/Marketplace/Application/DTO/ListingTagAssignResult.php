<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class ListingTagAssignResult
{
    /**
     * @param int $assigned сколько связей создано
     * @param int $matched сколько листингов компании выбрано; matched − assigned — у скольких тег уже был
     */
    public function __construct(
        public string $tagId,
        public string $tagName,
        public int $assigned,
        public int $matched,
    ) {
    }
}
