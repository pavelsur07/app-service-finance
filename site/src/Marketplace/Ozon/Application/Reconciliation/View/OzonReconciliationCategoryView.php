<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\View;

use App\Marketplace\Enum\OzonReconciliationStatus;

final readonly class OzonReconciliationCategoryView
{
    public function __construct(
        public string $code,
        public string $name,
        public OzonReconciliationStatus $status,
        public ?string $source,
        public ?string $target,
        public ?string $delta,
        public ?int $sourceCount,
        public ?int $targetCount,
        public ?string $note,
    ) {
    }
}
