<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service\Dto;

final readonly class WbCommissionerReportRawIngestResult
{
    public function __construct(
        public int $rowsTotal,
        public int $rowsParsed,
        public int $errorsCount,
        public int $warningsCount,
    ) {
    }
}
