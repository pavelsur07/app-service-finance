<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

interface MarketplaceRawDocumentProcessorInterface extends MarketplaceRawProcessorInterface
{
    public function process(string $companyId, string $rawDocId): int;
}
