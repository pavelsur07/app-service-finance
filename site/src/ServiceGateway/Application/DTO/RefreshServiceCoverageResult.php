<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class RefreshServiceCoverageResult
{
    public function __construct(
        public string $service,
        public int $rows,
        public int $cabinetsTotal,
    ) {
    }
}
