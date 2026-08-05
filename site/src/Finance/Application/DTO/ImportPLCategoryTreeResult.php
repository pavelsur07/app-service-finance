<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class ImportPLCategoryTreeResult
{
    /**
     * @param ImportPLCategoryTreeRow[] $created
     * @param ImportPLCategoryTreeRow[] $updated
     * @param list<string>              $unresolvedFormulaCodes токены формул, которых не будет в целевой компании после импорта
     */
    public function __construct(
        public array $created,
        public array $updated,
        public int $unchangedCount,
        public array $unresolvedFormulaCodes = [],
    ) {
    }
}
