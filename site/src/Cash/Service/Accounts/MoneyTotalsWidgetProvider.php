<?php

namespace App\Cash\Service\Accounts;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Entity\Company;
use Symfony\Component\Intl\Currencies;

class MoneyTotalsWidgetProvider
{
    public function __construct(
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly FundBalanceService $fundBalanceService,
    ) {
    }

    /**
     * @return array{rows: list<array{currency:string,available:float,funds:float,fundsMinor:int,inTransit:float}>}
     */
    public function build(Company $company): array
    {
        $accounts = $this->moneyAccountRepository->findBy(['company' => $company]);
        $availableTotals = [];
        foreach ($accounts as $account) {
            $currency = $account->getCurrency();
            $scale = $this->getFractionDigits($currency);
            $current = $account->getCurrentBalance();
            $availableTotals[$currency] = $this->bcAdd($availableTotals[$currency] ?? '0', $current, $scale);
        }

        $fundTotalsMinor = $this->fundBalanceService->getTotals($company->getId());
        $currencies = array_unique(array_merge(array_keys($availableTotals), array_keys($fundTotalsMinor)));
        sort($currencies);

        $rows = [];
        foreach ($currencies as $currency) {
            $available = $availableTotals[$currency] ?? '0';
            $fundMinor = $fundTotalsMinor[$currency] ?? 0;
            $rows[] = [
                'currency' => $currency,
                'available' => $this->stringToFloat($available),
                'funds' => $this->fundBalanceService->convertMinorToDecimal($fundMinor, $currency),
                'fundsMinor' => $fundMinor,
                'inTransit' => 0.0,
            ];
        }

        return ['rows' => $rows];
    }

    private function bcAdd(string $left, string $right, int $scale): string
    {
        return bcadd($left, $right, $scale);
    }

    private function getFractionDigits(string $currency): int
    {
        return Currencies::getFractionDigits($currency);
    }

    private function stringToFloat(string $value): float
    {
        return (float) $value;
    }
}
