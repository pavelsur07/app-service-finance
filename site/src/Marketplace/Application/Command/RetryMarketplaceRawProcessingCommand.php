<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

final readonly class RetryMarketplaceRawProcessingCommand
{
    public function __construct(
        public string $companyId,
        public string $processingRunId,
    ) {}
}
