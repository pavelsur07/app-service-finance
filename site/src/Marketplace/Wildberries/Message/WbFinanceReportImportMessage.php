<?php

namespace App\Marketplace\Wildberries\Message;

use Ramsey\Uuid\Uuid;

final class WbFinanceReportImportMessage
{
    private string $importId;

    public function __construct(
        private readonly string $companyId,
        private readonly string $dateFrom,
        private readonly string $dateTo,
        private readonly int $rrdId = 0,
        ?string $importId = null,
    ) {
        $this->importId = $importId ?? Uuid::uuid4()->toString();
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getDateFrom(): string
    {
        return $this->dateFrom;
    }

    public function getDateTo(): string
    {
        return $this->dateTo;
    }

    public function getRrdId(): int
    {
        return $this->rrdId;
    }

    public function getImportId(): string
    {
        return $this->importId;
    }
}
