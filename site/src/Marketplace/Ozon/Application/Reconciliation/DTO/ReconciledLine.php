<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\DTO;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Shared\Domain\ValueObject\Money;

final readonly class ReconciledLine
{
    public function __construct(
        public OzonReconciliationCheck $check,
        public OzonReconciliationBlock $block,
        public string $categoryCode,
        public ?Money $source,
        public ?Money $target,
        public OzonReconciliationStatus $status,
        public ?int $sourceCount = null,
        public ?int $targetCount = null,
        public ?string $note = null,
    ) {
    }

    public function isBlockTotal(): bool
    {
        return '' === $this->categoryCode;
    }
}
