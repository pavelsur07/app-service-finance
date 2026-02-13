<?php

namespace App\Analytics\Application;

use App\Analytics\Api\Response\SnapshotContextResponse;
use App\Analytics\Api\Response\SnapshotResponse;
use App\Analytics\Application\Widget\FreeCashWidgetBuilder;
use App\Analytics\Application\Widget\InflowWidgetBuilder;
use App\Analytics\Application\Widget\OutflowWidgetBuilder;
use App\Analytics\Application\Widget\CashflowSplitWidgetBuilder;
use App\Analytics\Application\Widget\ProfitWidgetBuilder;
use App\Analytics\Application\Widget\RevenueWidgetBuilder;
use App\Analytics\Application\Widget\TopCashWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardSnapshotService
{
    private const SNAPSHOT_TTL_SECONDS = 120;
    private const VAT_MODE_EXCLUDE = 'exclude';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly FreeCashWidgetBuilder $freeCashWidgetBuilder,
        private readonly InflowWidgetBuilder $inflowWidgetBuilder,
        private readonly OutflowWidgetBuilder $outflowWidgetBuilder,
        private readonly CashflowSplitWidgetBuilder $cashflowSplitWidgetBuilder,
        private readonly RevenueWidgetBuilder $revenueWidgetBuilder,
        private readonly ProfitWidgetBuilder $profitWidgetBuilder,
        private readonly TopCashWidgetBuilder $topCashWidgetBuilder,
    )
    {
    }

    public function getSnapshot(Company $company, Period $period): SnapshotResponse
    {
        $cacheKey = sprintf(
            'dashboard_v1_snapshot_%s_%s_%s_%s',
            (string) $company->getId(),
            $period->getFrom()->format('Y-m-d'),
            $period->getTo()->format('Y-m-d'),
            self::VAT_MODE_EXCLUDE,
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($company, $period) {
            $item->expiresAfter(self::SNAPSHOT_TTL_SECONDS);

            $prevPeriod = $period->prevPeriod();

            $freeCash = $this->freeCashWidgetBuilder->build($company, $period);
            $inflow = $this->inflowWidgetBuilder->build($company, $period);
            $outflow = $this->outflowWidgetBuilder->build($company, $period, $inflow->toArray());
            $cashflowSplit = $this->cashflowSplitWidgetBuilder->build($company, $period);
            $revenue = $this->revenueWidgetBuilder->build($company, $period);
            $profit = $this->profitWidgetBuilder->build($company, $period);
            $topCash = $this->topCashWidgetBuilder->build($company, $period);

            return new SnapshotResponse(
                new SnapshotContextResponse(
                    companyId: (string) $company->getId(),
                    from: $period->getFrom(),
                    to: $period->getTo(),
                    days: $period->days(),
                    prevFrom: $prevPeriod->getFrom(),
                    prevTo: $prevPeriod->getTo(),
                    vatMode: self::VAT_MODE_EXCLUDE,
                    lastUpdatedAt: null,
                ),
                $freeCash,
                $inflow,
                $outflow,
                $cashflowSplit,
                $revenue['widget'],
                $profit,
                $topCash,
                $this->buildAlerts($freeCash->toArray(), $revenue, $profit),
            );
        });
    }

    /**
     * @param array<string, mixed> $freeCash
     * @param array{widget: \\App\\Analytics\\Api\\Response\\RevenueWidgetResponse, registryEmpty: bool} $revenue
     * @param array<string, mixed> $profit
     *
     * @return list<array{code: string}>
     */
    private function buildAlerts(array $freeCash, array $revenue, array $profit): array
    {
        $alerts = [];

        if (($revenue['registryEmpty'] ?? false) === true) {
            $alerts[] = ['code' => 'PL_REGISTRY_EMPTY'];
        }

        // Rule OUTFLOW_GT_INFLOW is intentionally skipped until outflow widget is implemented.
        if (((float) ($freeCash['delta_pct'] ?? 0.0)) < 0) {
            $alerts[] = ['code' => 'FREE_CASH_DOWN'];
        }

        $revenueDeltaAbs = (float) (($revenue['widget']->toArray()['delta_abs'] ?? 0.0));
        if ($revenueDeltaAbs < 0) {
            $alerts[] = ['code' => 'REV_DOWN'];
        }

        if (((float) ($profit['delta']['margin_pp'] ?? 0.0)) < 0) {
            $alerts[] = ['code' => 'MARGIN_DOWN'];
        }

        return array_slice($alerts, 0, 5);
    }
}
