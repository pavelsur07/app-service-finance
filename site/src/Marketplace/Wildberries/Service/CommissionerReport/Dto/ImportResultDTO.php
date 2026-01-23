<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service\CommissionerReport\Dto;

final readonly class ImportResultDTO
{
    public function __construct(
        public int $rowsTotal,
        public int $rowsParsed,
        public int $errorsCount,
        public int $warningsCount,
    ) {
    }
}
