<?php

namespace App\Balance\Provider;

use App\Balance\Enum\BalanceLinkSourceType;
use App\Cash\Service\Accounts\FundBalanceService;
use App\Company\Entity\Company;

final class FundsTotalsProvider implements BalanceValueProviderInterface
{
    public function __construct(
        private readonly FundBalanceService $fundBalanceService,
    ) {
    }

    public function supports(BalanceLinkSourceType $type): bool
    {
        return BalanceLinkSourceType::MONEY_FUNDS_TOTAL === $type;
    }

    /** @return array<string,float> */
    public function getTotalsForCompanyUpToDate(Company $company, \DateTimeImmutable $date): array
    {
        $fundTotalsMinor = $this->fundBalanceService->getTotals($company->getId());

        $fundTotals = [];
        foreach ($fundTotalsMinor as $currency => $amountMinor) {
            $fundTotals[$currency] = $this->fundBalanceService->convertMinorToDecimal($amountMinor, $currency);
        }

        return $fundTotals;
    }
}
