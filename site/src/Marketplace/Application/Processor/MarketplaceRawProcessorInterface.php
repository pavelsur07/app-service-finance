<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

interface MarketplaceRawProcessorInterface
{
    public function supports(string $marketplaceValue, string $kind): bool;

    public function process(string $companyId, string $rawDocId): int;
}
