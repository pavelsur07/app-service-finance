<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

use App\Finance\Application\DTO\PLCategoryTreeNode;

final readonly class ImportPLCategoryTreeCommand
{
    /**
     * @param list<PLCategoryTreeNode> $sourceNodes дерево-источник в DFS pre-order
     * @param bool $onlyCreate только добавить недостающие категории: совпавшие
     *                         не меняются (базовый шаблон не перетирает
     *                         настройки компании), а служат родителями новым
     */
    public function __construct(
        public array $sourceNodes,
        public string $targetCompanyId,
        public bool $dryRun,
        public bool $onlyCreate = false,
    ) {
    }
}
