<?php

declare(strict_types=1);

namespace App\Analytics\Application\Widget;

use App\Analytics\Api\Response\InflowWidgetResponse;
use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Application\DTO\CashDailyAmount;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;

final readonly class InflowWidgetBuilder
{
    public function __construct(
        private CashFacade $cashFacade,
        private DrilldownBuilder $drilldownBuilder,
    ) {
    }

    public function build(Company $company, Period $period, FiatCurrency $cashCurrency): InflowWidgetResponse
    {
        $companyId = (string) $company->getId();
        $accountIds = $this->cashFacade->listActiveMoneyAccountIds($companyId, $cashCurrency->value);

        if ([] === $accountIds) {
            return new InflowWidgetResponse(
                sum: 0.0,
                deltaAbs: 0.0,
                deltaPct: 0.0,
                avgDaily: 0.0,
                series: [],
                drilldown: $this->drilldownBuilder->cashTransactions(['direction' => 'inflow', 'exclude_transfers' => true, 'currency' => $cashCurrency->value]),
            );
        }

        $sum = $this->cashFacade->sumInflowExcludingTransfers($companyId, $period->getFrom(), $period->getTo(), $cashCurrency->value);
        $prevPeriod = $period->prevPeriod();
        $prevSum = $this->cashFacade->sumInflowExcludingTransfers($companyId, $prevPeriod->getFrom(), $prevPeriod->getTo(), $cashCurrency->value);

        $seriesRows = $this->cashFacade->sumInflowByDayExcludingTransfers($companyId, $period->getFrom(), $period->getTo(), $cashCurrency->value);
        $series = array_map(
            static fn (CashDailyAmount $row): array => ['date' => $row->date, 'value' => (float) $row->amount],
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
                'currency' => $cashCurrency->value,
            ]),
        );
    }
}
