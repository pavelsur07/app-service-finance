<?php

namespace App\Cash\Message\Import;

use Ramsey\Uuid\Uuid;

final class BankImportMessage
{
    private string $importId;

    public function __construct(
        private readonly string $companyId,
        private readonly string $bankCode,
        ?string $importId = null,
    ) {
        $this->importId = $importId ?? Uuid::uuid4()->toString();
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getBankCode(): string
    {
        return $this->bankCode;
    }

    public function getImportId(): string
    {
        return $this->importId;
    }
}
