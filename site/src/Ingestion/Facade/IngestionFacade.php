<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;

final readonly class IngestionFacade
{
    public function __construct(
        private FinancialTransactionRepository $financialTransactionRepository,
        private NormalizationIssueRepository $normalizationIssueRepository,
    ) {
    }

    /**
     * @return iterable<FinancialTransaction>
     */
    public function getTransactions(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $shopRef = null,
    ): iterable {
        return $this->financialTransactionRepository->iterateByPeriod($companyId, $from, $to, $shopRef);
    }

    public function countOpenIssues(string $companyId): int
    {
        return $this->normalizationIssueRepository->countOpenForCompany($companyId);
    }
}
