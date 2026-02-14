<?php

namespace App\Analytics\Application\Widget;

use App\Analytics\Api\Response\InflowWidgetResponse;
use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;

final readonly class InflowWidgetBuilder
{
    public function __construct(
        private MoneyAccountRepository $moneyAccountRepository,
        private CashTransactionRepository $cashTransactionRepository,
        private DrilldownBuilder $drilldownBuilder,
    ) {
    }

    public function build(Company $company, Period $period): InflowWidgetResponse
    {
        $accounts = $this->moneyAccountRepository->findByFilters($company, null, null, true, null, ['name' => 'ASC']);
        $accountIds = array_map(static fn (MoneyAccount $account): string => (string) $account->getId(), $accounts);

        if ([] === $accountIds) {
            return new InflowWidgetResponse(
                sum: 0.0,
                deltaAbs: 0.0,
                deltaPct: 0.0,
                avgDaily: 0.0,
                series: [],
                drilldown: $this->drilldownBuilder->cashTransactions(['direction' => 'inflow', 'exclude_transfers' => true]),
            );
        }

        $sum = $this->cashTransactionRepository->sumInflowByCompanyAndPeriodExcludeTransfers($company, $period->getFrom(), $period->getTo());
        $prevPeriod = $period->prevPeriod();
        $prevSum = $this->cashTransactionRepository->sumInflowByCompanyAndPeriodExcludeTransfers($company, $prevPeriod->getFrom(), $prevPeriod->getTo());

        $seriesRows = $this->cashTransactionRepository->sumInflowByDayExcludeTransfers($company, $period->getFrom(), $period->getTo());
        $series = array_map(
            static fn (array $row): array => ['date' => $row['date'], 'value' => (float) $row['value']],
            $seriesRows,
        );

        $deltaAbs = (float) bcsub($sum, $prevSum, 2);
        $deltaPct = 0.0;
        if (0.0 !== (float) $prevSum) {
            $deltaPct = round((((float) $sum - (float) $prevSum) / (float) $prevSum) * 100, 2);
        }

        $avgDaily = (float) bcdiv($sum, (string) $period->days(), 2);

        return new InflowWidgetResponse(
            sum: (float) $sum,
            deltaAbs: $deltaAbs,
            deltaPct: $deltaPct,
            avgDaily: $avgDaily,
            series: $series,
            drilldown: $this->drilldownBuilder->cashTransactions([
                'direction' => 'inflow',
                'exclude_transfers' => true,
                'account_ids' => $accountIds,
                'from' => $period->getFrom()->format('Y-m-d'),
                'to' => $period->getTo()->format('Y-m-d'),
            ]),
        );
    }
}

