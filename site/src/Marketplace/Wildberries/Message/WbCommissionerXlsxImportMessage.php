<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Message;

final class WbCommissionerXlsxImportMessage
{
    public function __construct(
        private readonly string $companyId,
        private readonly string $reportId,
    ) {
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getReportId(): string
    {
        return $this->reportId;
    }
}
