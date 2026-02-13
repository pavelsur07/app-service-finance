<?php

namespace App\Analytics\Application;

use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Repository\PLDailyTotalRepository;

final class LastUpdatedAtResolver
{
    public function __construct(
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly MoneyFundMovementRepository $moneyFundMovementRepository,
        private readonly PLDailyTotalRepository $plDailyTotalRepository,
    ) {
    }

    public function resolve(Company $company): ?\DateTimeImmutable
    {
        $timestamps = array_filter([
            $this->cashTransactionRepository->maxUpdatedAtForCompany($company),
            $this->moneyFundMovementRepository->maxUpdatedAtForCompany($company),
            $this->plDailyTotalRepository->maxUpdatedAtForCompany($company),
        ]);

        if ([] === $timestamps) {
            return null;
        }

        usort($timestamps, static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);

        return $timestamps[array_key_last($timestamps)];
    }
}
