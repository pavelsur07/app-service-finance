<?php

namespace App\Balance\Provider;

use App\Balance\Enum\BalanceLinkSourceType;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Company\Entity\Company;

final class CashTotalsProvider implements BalanceValueProviderInterface
{
    public function __construct(
        private readonly MoneyAccountDailyBalanceRepository $moneyAccountDailyBalanceRepository,
    ) {
    }

    public function supports(BalanceLinkSourceType $type): bool
    {
        return BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL === $type;
    }

    /** @return array<string,float> */
    public function getTotalsForCompanyUpToDate(Company $company, \DateTimeImmutable $date): array
    {
        $totals = $this->moneyAccountDailyBalanceRepository->getLatestClosingTotalsUpToDate($company, $date);

        $result = [];
        foreach ($totals as $currency => $amount) {
            $result[$currency] = (float) $amount;
        }

        return $result;
    }
}
