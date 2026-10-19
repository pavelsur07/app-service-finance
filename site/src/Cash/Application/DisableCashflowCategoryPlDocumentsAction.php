<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Repository\Transaction\CashflowCategoryRepository;

final readonly class DisableCashflowCategoryPlDocumentsAction
{
    public function __construct(
        private CashflowCategoryRepository $repository,
    ) {
    }

    public function __invoke(string $companyId): int
    {
        return $this->repository->disablePlDocuments($companyId);
    }
}
