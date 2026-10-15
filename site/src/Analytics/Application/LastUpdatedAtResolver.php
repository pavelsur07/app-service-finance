<?php

declare(strict_types=1);

namespace App\Analytics\Application;

use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Finance\Repository\PLDailyTotalRepository;

final class LastUpdatedAtResolver
{
    public function __construct(
        private readonly CashFacade $cashFacade,
        private readonly PLDailyTotalRepository $plDailyTotalRepository,
    ) {
    }

    public function resolve(Company $company): ?\DateTimeImmutable
    {
        $timestamps = array_filter([
            $this->cashFacade->getLastCashActivityAt((string) $company->getId()),
            $this->plDailyTotalRepository->maxUpdatedAtForCompany($company),
        ]);

        if ([] === $timestamps) {
            return null;
        }

        usort($timestamps, static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);

        return $timestamps[array_key_last($timestamps)];
    }
}
