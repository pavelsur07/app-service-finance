<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\View;

use App\Marketplace\Enum\OzonReconciliationCheck;

final readonly class OzonReconciliationCheckView
{
    /**
     * @param list<OzonReconciliationBlockView> $blocks
     */
    public function __construct(
        public OzonReconciliationCheck $check,
        public array $blocks,
    ) {
    }
}
