<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class ImportPLCategoryTreeResult
{
    /**
     * @param ImportPLCategoryTreeRow[] $created
     * @param ImportPLCategoryTreeRow[] $updated
     */
    public function __construct(
        public array $created,
        public array $updated,
        public int $unchangedCount,
    ) {
    }
}
