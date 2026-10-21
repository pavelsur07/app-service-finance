<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class ExtendPLTreeResult
{
    /**
     * @param list<PLFormulaChange> $formulaChanges
     */
    public function __construct(
        public ImportPLCategoryTreeResult $import,
        public array $formulaChanges,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->import->created && [] === $this->formulaChanges;
    }
}
