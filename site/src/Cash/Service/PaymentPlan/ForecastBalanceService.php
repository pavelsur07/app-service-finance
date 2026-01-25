<?php

declare(strict_types=1);

namespace App\Cash\Service\PaymentPlan;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\DTO\ForecastDTO;
use App\Company\Entity\Company;

final class ForecastBalanceService
{
    public function __construct(
        private MoneyAccountDailyBalanceRepository $dailyBalanceRepository,
        private PaymentPlanRepository $paymentPlanRepository,
    ) {
    }

    public function buildForecast(
        Company $company,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?MoneyAccount $account = null,
    ): ForecastDTO {
        $dto = new ForecastDTO();

        $fromDate = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $toDate = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);

        if ($fromDate > $toDate) {
            return $dto;
        }

        $opening = $this->dailyBalanceRepository->getOpeningBalanceForDate($company, $fromDate, $account);
        $dto->openingBalance = (float) $opening;

        $dayTotals = $this->paymentPlanRepository->sumByDay($company, $fromDate, $toDate, $account);
        $balance = $opening;

        $current = $fromDate;
        while ($current <= $toDate) {
            $key = $current->format('Y-m-d');
            $inflow = $dayTotals[$key]['inflow'] ?? '0';
            $outflow = $dayTotals[$key]['outflow'] ?? '0';

            $balance = \bcsub(\bcadd($balance, $inflow, 2), $outflow, 2);
            $dto->series[$key] = (float) $balance;

            if (\bccomp($balance, '0', 2) < 0) {
                $dto->hasGap = true;
            }

            $current = $current->modify('+1 day');
        }

        $dto->closingForecast = (float) $balance;

        return $dto;
    }
}
