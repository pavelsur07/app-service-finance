<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

use App\Finance\Application\DTO\PLCategoryTreeNode;

final readonly class ImportPLCategoryTreeCommand
{
    /**
     * @param list<PLCategoryTreeNode> $sourceNodes дерево-источник в DFS pre-order
     */
    public function __construct(
        public array $sourceNodes,
        public string $targetCompanyId,
        public bool $dryRun,
    ) {
    }
}
