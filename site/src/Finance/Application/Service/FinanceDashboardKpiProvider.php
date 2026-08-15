<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Company\Entity\Company;
use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Shared\Domain\ValueObject\RoundingMode;

final readonly class FinanceDashboardKpiProvider
{
    private const PERIOD_DAYS = 30;

    public function __construct(
        private AccountBalanceProvider $accountBalanceProvider,
        private CashflowReportBuilder $cashflowReportBuilder,
        private MoneyAccountRepository $moneyAccountRepository,
    ) {
    }

    /**
     * @return array{
     *     kpi: array{todayBalance:string,inflow30:string,outflow30:string,netFlow30:string},
     *     comparisons: array<string,array{previous:string,state:string,percent:?string,variant:string}>
     * }
     */
    public function build(
        Company $company,
        FiatCurrency $cashCurrency,
        string $activity,
        bool $withComparisons,
        \DateTimeImmutable $today,
    ): array {
        $today = $today->setTime(0, 0);
        $currentFrom = $today->modify('-29 days');
        $previousFrom = $today->modify('-59 days');
        $previousTo = $today->modify('-30 days');
        $scale = $cashCurrency->scale();

        $accounts = $this->moneyAccountRepository->findByFilters(
            $company,
            null,
            [$cashCurrency->value],
            true,
            null,
            ['name' => 'ASC'],
        );

        $todayBalance = $this->cashBalanceAtDate($company, $accounts, $today, $scale);
        $reportFrom = $withComparisons ? $previousFrom : $currentFrom;
        $report = $this->cashflowReportBuilder->build(
            new CashflowReportParams($company, 'day', $reportFrom, $today),
        );
        $currentPeriodIndexes = $this->periodIndexes($report['periods'], $currentFrom, $today);
        [$inflow30, $outflow30] = $this->cashflowTotalsForActivity(
            $report,
            $cashCurrency,
            $activity,
            $currentPeriodIndexes,
        );
        $netFlow30 = bcsub($inflow30, $outflow30, $scale);
        $kpi = [
            'todayBalance' => $todayBalance,
            'inflow30' => $inflow30,
            'outflow30' => $outflow30,
            'netFlow30' => $netFlow30,
        ];

        if (!$withComparisons) {
            return ['kpi' => $kpi, 'comparisons' => []];
        }

        $previousBalance = $this->cashBalanceAtDate($company, $accounts, $previousTo, $scale);
        [$previousInflow30, $previousOutflow30] = $this->cashflowTotalsForActivity(
            $report,
            $cashCurrency,
            $activity,
            $this->periodIndexes($report['periods'], $previousFrom, $previousTo),
        );
        $previousNetFlow30 = bcsub($previousInflow30, $previousOutflow30, $scale);

        return [
            'kpi' => $kpi,
            'comparisons' => [
                'todayBalance' => $this->comparison($todayBalance, $previousBalance, true, $scale),
                'inflow30' => $this->comparison($inflow30, $previousInflow30, true, $scale),
                'outflow30' => $this->comparison($outflow30, $previousOutflow30, false, $scale),
                'netFlow30' => $this->comparison($netFlow30, $previousNetFlow30, true, $scale),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param list<int>            $periodIndexes
     *
     * @return array{string, string}
     */
    private function cashflowTotalsForActivity(
        array $report,
        FiatCurrency $cashCurrency,
        string $activity,
        array $periodIndexes,
    ): array {
        $selectedFlowKind = match ($activity) {
            'operating' => CashflowFlowKind::OPERATING,
            'financing' => CashflowFlowKind::FINANCING,
            'investing' => CashflowFlowKind::INVESTING,
            default => null,
        };
        $scale = $cashCurrency->scale();
        $zero = bcadd('0', '0', $scale);
        $selectedPeriods = array_fill_keys($periodIndexes, true);
        $inflow = $zero;
        $outflow = $zero;
        $categoryTotals = $report['categoryTotals'];
        $accumulate = static function (array $node, bool $insideUnallocated = false) use (
            &$accumulate,
            &$inflow,
            &$outflow,
            $cashCurrency,
            $categoryTotals,
            $selectedFlowKind,
            $selectedPeriods,
            $scale,
            $zero,
        ): array {
            /** @var CashflowCategory $category */
            $category = $categoryTotals[$node['id']]['entity'];
            $insideUnallocated = $insideUnallocated || in_array($category->getSystemCode(), [
                CashflowCategory::CODE_UNALLOCATED,
                CashflowCategory::SYSTEM_UNALLOCATED,
            ], true);
            $childrenInflow = $zero;
            $childrenOutflow = $zero;

            foreach ($node['children'] ?? [] as $child) {
                [$childInflow, $childOutflow] = $accumulate($child, $insideUnallocated);
                $childrenInflow = bcadd($childrenInflow, $childInflow, $scale);
                $childrenOutflow = bcadd($childrenOutflow, $childOutflow, $scale);
            }

            $nodeInflow = $zero;
            $nodeOutflow = $zero;
            foreach (($node['totals'] ?? [])[$cashCurrency->value] ?? [] as $periodIndex => $amount) {
                if (!isset($selectedPeriods[$periodIndex])) {
                    continue;
                }

                // CashflowReportBuilder currently exposes float totals; normalize that legacy boundary once.
                $decimalAmount = number_format((float) $amount, $scale, '.', '');
                $amountSign = bccomp($decimalAmount, '0', $scale);
                if ($amountSign > 0) {
                    $nodeInflow = bcadd($nodeInflow, $decimalAmount, $scale);
                } elseif ($amountSign < 0) {
                    $nodeOutflow = bcadd($nodeOutflow, ltrim($decimalAmount, '-'), $scale);
                }
            }

            $ownInflow = bcsub($nodeInflow, $childrenInflow, $scale);
            $ownOutflow = bcsub($nodeOutflow, $childrenOutflow, $scale);
            $flowKind = $category->getEffectiveFlowKind();
            $include = CashflowFlowKind::TECHNICAL !== $flowKind
                && (null === $selectedFlowKind || (!$insideUnallocated && $selectedFlowKind === $flowKind));

            if ($include) {
                if (bccomp($ownInflow, '0', $scale) > 0) {
                    $inflow = bcadd($inflow, $ownInflow, $scale);
                }

                if (bccomp($ownOutflow, '0', $scale) > 0) {
                    $outflow = bcadd($outflow, $ownOutflow, $scale);
                }
            }

            return [$nodeInflow, $nodeOutflow];
        };

        foreach ($report['tree'] as $node) {
            $accumulate($node);
        }

        return [$inflow, $outflow];
    }

    /**
     * @param list<MoneyAccount> $accounts
     */
    private function cashBalanceAtDate(
        Company $company,
        array $accounts,
        \DateTimeImmutable $date,
        int $scale,
    ): string {
        $accountIds = array_map(
            static fn (MoneyAccount $account): string => (string) $account->getId(),
            $accounts,
        );
        $openingBalances = $this->accountBalanceProvider->getOpeningBalancesUpToDate(
            $company,
            $date,
            $accountIds,
        );
        $total = bcadd('0', '0', $scale);

        foreach ($accounts as $account) {
            // A balance does not exist before the account's opening date.
            if ($date < $account->getOpeningBalanceDate()->setTime(0, 0)) {
                continue;
            }

            $opening = $openingBalances[(string) $account->getId()] ?? $account->getOpeningBalance();
            $total = bcadd($total, (string) $opening, $scale);
        }

        return $total;
    }

    /**
     * `variant` is the UI Kit sentiment: up=favourable, down=unfavourable.
     * Direction remains explicit in the signed percent or cross state.
     *
     * @return array{previous:string,state:string,percent:?string,variant:string}
     */
    private function comparison(
        string $current,
        string $previous,
        bool $increaseIsPositive,
        int $scale,
    ): array {
        $currentSign = bccomp($current, '0', $scale);
        $previousSign = bccomp($previous, '0', $scale);
        $deltaSign = bccomp($current, $previous, $scale);

        if (0 === $previousSign) {
            return [
                'previous' => $previous,
                'state' => 'no_base',
                'percent' => null,
                'variant' => 'neutral',
            ];
        }

        if (0 !== $currentSign && $currentSign !== $previousSign) {
            return [
                'previous' => $previous,
                'state' => $currentSign > 0 ? 'cross_up' : 'cross_down',
                'percent' => null,
                'variant' => $this->comparisonVariant($deltaSign, $increaseIsPositive),
            ];
        }

        $delta = bcsub($current, $previous, $scale);
        $rawPercent = bcdiv(
            bcmul($delta, '100', 6),
            ltrim($previous, '-'),
            6,
        );
        $tenths = RoundingMode::HALF_UP->roundToInteger(bcmul($rawPercent, '10', 6));
        $percent = bcdiv($tenths, '10', 1);

        return [
            'previous' => $previous,
            'state' => 'percent',
            'percent' => $percent,
            'variant' => $this->comparisonVariant(bccomp($percent, '0', 1), $increaseIsPositive),
        ];
    }

    private function comparisonVariant(int $deltaSign, bool $increaseIsPositive): string
    {
        if (0 === $deltaSign) {
            return 'neutral';
        }

        return ($deltaSign > 0) === $increaseIsPositive ? 'up' : 'down';
    }

    /**
     * @param list<array{start:\DateTimeInterface,end:\DateTimeInterface}> $periods
     *
     * @return list<int>
     */
    private function periodIndexes(array $periods, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $indexes = [];
        foreach ($periods as $index => $period) {
            $start = \DateTimeImmutable::createFromInterface($period['start'])->setTime(0, 0);
            if ($start >= $from && $start <= $to) {
                $indexes[] = $index;
            }
        }

        if (self::PERIOD_DAYS !== count($indexes)) {
            throw new \LogicException(sprintf('Finance dashboard period %s..%s must contain exactly %d days, got %d.', $from->format('Y-m-d'), $to->format('Y-m-d'), self::PERIOD_DAYS, count($indexes)));
        }

        return $indexes;
    }
}
