<?php

namespace App\Analytics\Application\Widget;

use App\Analytics\Api\Response\FreeCashWidgetResponse;
use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Company\Entity\Company;
use DateTimeImmutable;
use Symfony\Component\Intl\Currencies;

final readonly class FreeCashWidgetBuilder
{
    public function __construct(
        private MoneyAccountRepository $moneyAccountRepository,
        private AccountBalanceProvider $accountBalanceProvider,
        private MoneyFundMovementRepository $moneyFundMovementRepository,
        private DrilldownBuilder $drilldownBuilder,
    ) {
    }

    public function build(Company $company, Period $period): FreeCashWidgetResponse
    {
        $accounts = $this->moneyAccountRepository->findByFilters($company, null, null, true, null, ['name' => 'ASC']);
        $accountIds = array_map(static fn (MoneyAccount $account): string => (string) $account->getId(), $accounts);

        $balancesAtEnd = $this->accountBalanceProvider->getClosingBalancesUpToDate($company, $period->getTo(), $accountIds);
        $balancesAtStart = $this->accountBalanceProvider->getClosingBalancesUpToDate($company, $period->getFrom(), $accountIds);

        $cashAtEnd = $this->sumBalances($balancesAtEnd);
        $cashAtStart = $this->sumBalances($balancesAtStart);

        $fundBalancesAtEnd = $this->moneyFundMovementRepository->sumFundBalancesUpToDate($company, $period->getTo());
        $fundBalancesAtStart = $this->moneyFundMovementRepository->sumFundBalancesUpToDate($company, $period->getFrom());

        $currency = $this->resolveCurrency($accounts);
        $reservedAtEnd = $this->convertMinorToMoney(array_sum($fundBalancesAtEnd), $currency);
        $reservedAtStart = $this->convertMinorToMoney(array_sum($fundBalancesAtStart), $currency);

        $freeCashAtEnd = (float) bcsub($cashAtEnd, $reservedAtEnd, 2);
        $freeCashAtStart = (float) bcsub($cashAtStart, $reservedAtStart, 2);

        $prevPeriodTo = $period->prevPeriod()->getTo();
        $balancesAtPrevEnd = $this->accountBalanceProvider->getClosingBalancesUpToDate($company, $prevPeriodTo, $accountIds);
        $fundBalancesAtPrevEnd = $this->moneyFundMovementRepository->sumFundBalancesUpToDate($company, $prevPeriodTo);

        $cashAtPrevEnd = $this->sumBalances($balancesAtPrevEnd);
        $reservedAtPrevEnd = $this->convertMinorToMoney(array_sum($fundBalancesAtPrevEnd), $currency);
        $freeCashAtPrevEnd = (float) bcsub($cashAtPrevEnd, $reservedAtPrevEnd, 2);

        $deltaAbs = (float) bcsub((string) $freeCashAtEnd, (string) $freeCashAtStart, 2);
        $deltaPct = 0.0;
        if (0.0 !== $freeCashAtPrevEnd) {
            $deltaPct = round(((($freeCashAtEnd - $freeCashAtPrevEnd) / $freeCashAtPrevEnd) * 100), 2);
        }

        return new FreeCashWidgetResponse(
            value: $freeCashAtEnd,
            deltaAbs: $deltaAbs,
            deltaPct: $deltaPct,
            cashAtEnd: (float) $cashAtEnd,
            reservedAtEnd: (float) $reservedAtEnd,
            lastUpdatedAt: new DateTimeImmutable('now', new \DateTimeZone('UTC')),
            drilldown: [
                'cash_balances' => $this->drilldownBuilder->cashBalances($period->getTo()->format('Y-m-d')),
                'funds_reserved' => $this->drilldownBuilder->fundsReserved($period->getTo()->format('Y-m-d')),
            ],
        );
    }

    /**
     * @param array<int|string,string> $balancesByAccountId
     */
    private function sumBalances(array $balancesByAccountId): string
    {
        $sum = '0.00';
        foreach ($balancesByAccountId as $balance) {
            $sum = bcadd($sum, (string) $balance, 2);
        }

        return $sum;
    }

    /**
     * @param MoneyAccount[] $accounts
     */
    private function resolveCurrency(array $accounts): string
    {
        if (isset($accounts[0])) {
            return $accounts[0]->getCurrency();
        }

        return 'USD';
    }

    private function convertMinorToMoney(int $amountMinor, string $currency): string
    {
        $fractionDigits = Currencies::getFractionDigits($currency);
        if (0 === $fractionDigits) {
            return (string) $amountMinor;
        }

        return number_format($amountMinor / (10 ** $fractionDigits), $fractionDigits, '.', '');
    }
}
