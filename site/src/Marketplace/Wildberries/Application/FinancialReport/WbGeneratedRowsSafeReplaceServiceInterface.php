<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Application\FinancialReport;

use App\Company\Entity\Company;

interface WbGeneratedRowsSafeReplaceServiceInterface
{
    public function cleanupForRawDocument(Company $company, string $rawDocumentId, \DateTimeImmutable $businessDate): void;
}
