<?php

declare(strict_types=1);

namespace App\Analytics\Application\Widget;

use App\Analytics\Api\Response\FreeCashWidgetResponse;
use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use Symfony\Component\Intl\Currencies;

final readonly class FreeCashWidgetBuilder
{
    public function __construct(
        private CashFacade $cashFacade,
        private DrilldownBuilder $drilldownBuilder,
    ) {
    }

    public function build(Company $company, Period $period, FiatCurrency $cashCurrency): FreeCashWidgetResponse
    {
        $companyId = (string) $company->getId();
        $accountIds = $this->cashFacade->listActiveMoneyAccountIds($companyId, $cashCurrency->value);

        $cashAtEnd = $this->cashFacade->sumClosingBalancesUpToDate($companyId, $period->getTo(), $accountIds);
        $cashAtStart = $this->cashFacade->sumClosingBalancesUpToDate($companyId, $period->getFrom(), $accountIds);

        $reservedAtEnd = $this->convertMinorToMoney($this->cashFacade->sumFundBalancesMinorUpToDate($companyId, $period->getTo(), $cashCurrency->value), $cashCurrency->value);
        $reservedAtStart = $this->convertMinorToMoney($this->cashFacade->sumFundBalancesMinorUpToDate($companyId, $period->getFrom(), $cashCurrency->value), $cashCurrency->value);

        $freeCashAtEnd = (float) bcsub($cashAtEnd, $reservedAtEnd, 2);
        $freeCashAtStart = (float) bcsub($cashAtStart, $reservedAtStart, 2);

        $prevPeriodTo = $period->prevPeriod()->getTo();
        $cashAtPrevEnd = $this->cashFacade->sumClosingBalancesUpToDate($companyId, $prevPeriodTo, $accountIds);
        $reservedAtPrevEnd = $this->convertMinorToMoney($this->cashFacade->sumFundBalancesMinorUpToDate($companyId, $prevPeriodTo, $cashCurrency->value), $cashCurrency->value);
        $freeCashAtPrevEnd = (float) bcsub($cashAtPrevEnd, $reservedAtPrevEnd, 2);

        $deltaAbs = (float) bcsub((string) $freeCashAtEnd, (string) $freeCashAtStart, 2);
        $deltaPct = 0.0;
        if (0.0 !== $freeCashAtPrevEnd) {
            $deltaPct = round((($freeCashAtEnd - $freeCashAtPrevEnd) / $freeCashAtPrevEnd) * 100, 2);
        }

        return new FreeCashWidgetResponse(
            value: $freeCashAtEnd,
            deltaAbs: $deltaAbs,
            deltaPct: $deltaPct,
            cashAtEnd: (float) $cashAtEnd,
            reservedAtEnd: (float) $reservedAtEnd,
            lastUpdatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            drilldown: [
                'cash_balances' => $this->drilldownBuilder->cashBalances($period->getTo()->format('Y-m-d'), $cashCurrency->value),
                'funds_reserved' => $this->drilldownBuilder->fundsReserved($period->getTo()->format('Y-m-d'), $cashCurrency->value),
            ],
        );
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
