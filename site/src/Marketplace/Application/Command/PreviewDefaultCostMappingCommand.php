<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

final readonly class PreviewDefaultCostMappingCommand
{
    public function __construct(
        public string $companyId,
        public string $marketplace,
    ) {
    }
}
