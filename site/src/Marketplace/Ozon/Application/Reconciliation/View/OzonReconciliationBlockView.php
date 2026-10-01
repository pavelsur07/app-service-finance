<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\View;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationStatus;

final readonly class OzonReconciliationBlockView
{
    /**
     * @param list<OzonReconciliationCategoryView> $categories
     */
    public function __construct(
        public OzonReconciliationBlock $block,
        public OzonReconciliationStatus $status,
        public ?string $source,
        public ?string $target,
        public ?string $delta,
        public ?int $sourceCount,
        public ?int $targetCount,
        public ?string $note,
        public array $categories,
    ) {
    }
}
