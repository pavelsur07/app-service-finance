<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

use App\Finance\Application\DTO\PLCategoryTreeNode;

final readonly class ImportPLCategoryTreeCommand
{
    /**
     * @param list<PLCategoryTreeNode> $sourceNodes дерево-источник в DFS pre-order
     * @param bool $onlyCreate режим шаблона: только добавить недостающие категории,
     *                         совпавшие не меняются (базовый шаблон не перетирает
     *                         настройки компании), а служат родителями новым;
     *                         похожие по названию статьи компании не дублируются
     * @param bool $updateExisting перезаписывать совпавшие категории полями источника;
     *                             false — только добавить недостающие
     * @param string|null $expectedFingerprint отпечаток плана из предпросмотра
     *                                         (ImportPLCategoryTreeResult::$fingerprint):
     *                                         другой план не применяется
     */
    public function __construct(
        public array $sourceNodes,
        public string $targetCompanyId,
        public bool $dryRun,
        public bool $onlyCreate = false,
        public bool $updateExisting = true,
        public ?string $expectedFingerprint = null,
    ) {
    }
}
