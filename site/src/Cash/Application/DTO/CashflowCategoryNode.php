<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashflowFlowKind;

/**
 * Статья ДДС в дереве компании. `level` — глубина с 1 у корня; `children` —
 * все дочерние статьи в порядке `sort`, как у сущности.
 */
final readonly class CashflowCategoryNode
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $level,
        public ?string $parentId,
        public CashflowFlowKind $flowKind,
        public ?string $systemCode,
        public array $children,
    ) {
    }
}
