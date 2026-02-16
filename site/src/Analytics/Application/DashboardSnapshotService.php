<?php

namespace App\Analytics\Application;

use App\Analytics\Api\Response\SnapshotContextResponse;
use App\Analytics\Api\Response\SnapshotResponse;
use App\Analytics\Application\Widget\CashflowSplitWidgetBuilder;
use App\Analytics\Application\Widget\FreeCashWidgetBuilder;
use App\Analytics\Application\Widget\InflowWidgetBuilder;
use App\Analytics\Application\Widget\OutflowWidgetBuilder;
use App\Analytics\Application\Widget\ProfitWidgetBuilder;
use App\Analytics\Application\Widget\RevenueWidgetBuilder;
use App\Analytics\Application\Widget\TopCashWidgetBuilder;
use App\Analytics\Application\Widget\TopPnlWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Analytics\Infrastructure\Telemetry\SnapshotTelemetry;
use App\Company\Entity\Company;
use Psr\Log\LoggerInterface;
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
        private readonly TopPnlWidgetBuilder $topPnlWidgetBuilder,
        private readonly SnapshotCacheInvalidator $snapshotCacheInvalidator,
        private readonly LastUpdatedAtResolver $lastUpdatedAtResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSnapshot(Company $company, Period $period): SnapshotResponse
    {
        $telemetry = new SnapshotTelemetry();
        $telemetry->start(SnapshotTelemetry::globalTimerName());
        $cacheHit = true;
        $snapshotVersion = $this->snapshotCacheInvalidator->resolveVersionForCompany($company);

        $cacheKey = sprintf(
            'dashboard_v1_snapshot_%s_%s_%s_%s_%s',
            (string) $company->getId(),
            $snapshotVersion,
            $period->getFrom()->format('Y-m-d'),
            $period->getTo()->format('Y-m-d'),
            self::VAT_MODE_EXCLUDE,
        );

        $snapshot = $this->cache->get($cacheKey, function (ItemInterface $item) use ($company, $period, $telemetry, &$cacheHit) {
            $cacheHit = false;
            $item->expiresAfter(self::SNAPSHOT_TTL_SECONDS);

            $prevPeriod = $period->prevPeriod();

            $telemetry->start('free_cash');
            $freeCash = $this->freeCashWidgetBuilder->build($company, $period);
            $telemetry->stop('free_cash');

            $telemetry->start('inflow');
            $inflow = $this->inflowWidgetBuilder->build($company, $period);
            $telemetry->stop('inflow');

            $telemetry->start('outflow');
            $outflow = $this->outflowWidgetBuilder->build($company, $period, $inflow->toArray());
            $telemetry->stop('outflow');

            $telemetry->start('cashflow_split');
            $cashflowSplit = $this->cashflowSplitWidgetBuilder->build($company, $period);
            $telemetry->stop('cashflow_split');

            $telemetry->start('revenue');
            $revenue = $this->revenueWidgetBuilder->build($company, $period);
            $telemetry->stop('revenue');

            $telemetry->start('profit');
            $profit = $this->profitWidgetBuilder->build($company, $period);
            $telemetry->stop('profit');

            $telemetry->start('top_cash');
            $topCash = $this->topCashWidgetBuilder->build($company, $period);
            $telemetry->stop('top_cash');

            $telemetry->start('top_pnl');
            $topPnl = $this->topPnlWidgetBuilder->build($company, $period);
            $telemetry->stop('top_pnl');

            $lastUpdatedAt = $this->lastUpdatedAtResolver->resolve($company);

            return new SnapshotResponse(
                new SnapshotContextResponse(
                    companyId: (string) $company->getId(),
                    from: $period->getFrom(),
                    to: $period->getTo(),
                    days: $period->days(),
                    prevFrom: $prevPeriod->getFrom(),
                    prevTo: $prevPeriod->getTo(),
                    vatMode: self::VAT_MODE_EXCLUDE,
                    lastUpdatedAt: $lastUpdatedAt,
                ),
                $freeCash,
                $inflow,
                $outflow,
                $cashflowSplit,
                $revenue['widget'],
                $profit,
                $topCash,
                $topPnl,
                $this->buildAlerts($freeCash->toArray(), $revenue, $profit),
                $this->buildWarnings($freeCash->toArray(), $inflow->toArray(), $outflow, $revenue),
            );
        });

        $telemetry->stop(SnapshotTelemetry::globalTimerName());
        $durations = $telemetry->finish();

        $this->logger->info('Dashboard snapshot telemetry', [
            'company_id' => (string) $company->getId(),
            'from' => $period->getFrom()->format('Y-m-d'),
            'to' => $period->getTo()->format('Y-m-d'),
            'cache_hit' => $cacheHit,
            'total_duration_ms' => $durations['total_duration_ms'],
            'widgets_duration_ms' => $durations['widgets_duration_ms'],
        ]);

        return $snapshot;
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

        // Rule OUTFLOW_GT_INFLOW is intentionally skipped until outflow widget is implemented.
        if (((float) ($freeCash['delta_pct'] ?? 0.0)) < 0) {
            $alerts[] = ['code' => 'FREE_CASH_DOWN'];
        }

        $revenueDeltaAbs = (float) ($revenue['widget']->toArray()['delta_abs'] ?? 0.0);
        if ($revenueDeltaAbs < 0) {
            $alerts[] = ['code' => 'REV_DOWN'];
        }

        if (((float) ($profit['delta']['margin_pp'] ?? 0.0)) < 0) {
            $alerts[] = ['code' => 'MARGIN_DOWN'];
        }

        return array_slice($alerts, 0, 5);
    }

    /**
     * @param array<string, mixed> $freeCash
     * @param array<string, mixed> $inflow
     * @param array<string, mixed> $outflow
     * @param array{widget: \App\Analytics\Api\Response\RevenueWidgetResponse, registryEmpty: bool} $revenue
     *
     * @return list<array{code: string, message: string}>
     */
    private function buildWarnings(array $freeCash, array $inflow, array $outflow, array $revenue): array
    {
        $warnings = [];

        if (($revenue['registryEmpty'] ?? false) === true) {
            $warnings[] = [
                'code' => 'PL_REGISTRY_EMPTY',
                'message' => 'Finance registry is empty for selected period.',
            ];
        }

        if (0.0 === (float) ($freeCash['cash_at_end'] ?? 0.0) && [] === ($inflow['series'] ?? [])) {
            $warnings[] = [
                'code' => 'NO_ACTIVE_ACCOUNTS',
                'message' => 'No active accounts found. Free cash is 0.',
            ];
        }

        if (0.0 === (float) ($inflow['sum'] ?? 0.0) && 0.0 === (float) ($outflow['sum_abs'] ?? 0.0)) {
            $warnings[] = [
                'code' => 'NO_CASH_TRANSACTIONS',
                'message' => 'No cash transactions found for selected period.',
            ];
        }

        return $warnings;
    }
}
