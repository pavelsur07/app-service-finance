<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Company\Entity\Company;
use App\Shared\Domain\ValueObject\RoundingMode;

final readonly class FinanceDashboardKpiProvider
{
    public function __construct(
        private AccountBalanceProvider $accountBalanceProvider,
        private CashTransactionRepository $cashTransactionRepository,
        private MoneyAccountRepository $moneyAccountRepository,
    ) {
    }

    /**
     * KPI keys keep the historical `30` suffix for compatibility; turnover values cover `$periodDays`.
     *
     * @return array{
     *     kpi: array{todayBalance:string,inflow30:string,outflow30:string,netFlow30:string},
     *     comparisons: array<string,array{previous:string,state:string,percent:?string,variant:string}>,
     *     periods: array{
     *         current:array{from:\DateTimeImmutable,to:\DateTimeImmutable},
     *         previous:array{from:\DateTimeImmutable,to:\DateTimeImmutable},
     *         balanceComparisonDate:\DateTimeImmutable
     *     }
     * }
     */
    public function build(
        Company $company,
        FiatCurrency $cashCurrency,
        string $activity,
        bool $withComparisons,
        \DateTimeImmutable $today,
        int $periodDays = 30,
    ): array {
        if ($periodDays < 1) {
            throw new \InvalidArgumentException(sprintf('Period days must be positive, got %d.', $periodDays));
        }

        $today = $today->setTime(0, 0);
        $currentFrom = $today->modify(sprintf('-%d days', $periodDays - 1));
        $previousTo = $currentFrom->modify('-1 day');
        $previousFrom = $previousTo->modify(sprintf('-%d days', $periodDays - 1));
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
        $selectedFlowKind = match ($activity) {
            'operating' => CashflowFlowKind::OPERATING,
            'financing' => CashflowFlowKind::FINANCING,
            'investing' => CashflowFlowKind::INVESTING,
            default => null,
        };
        $currentTurnover = $this->cashTransactionRepository->sumGrossTurnoverByPeriodExcludeTransfers(
            $company,
            $currentFrom,
            $today,
            $cashCurrency->value,
            $selectedFlowKind,
        );
        $inflow30 = bcadd($currentTurnover['inflow'], '0', $scale);
        $outflow30 = bcadd($currentTurnover['outflow'], '0', $scale);
        $netFlow30 = bcsub($inflow30, $outflow30, $scale);
        $kpi = [
            'todayBalance' => $todayBalance,
            'inflow30' => $inflow30,
            'outflow30' => $outflow30,
            'netFlow30' => $netFlow30,
        ];
        $periods = [
            'current' => ['from' => $currentFrom, 'to' => $today],
            'previous' => ['from' => $previousFrom, 'to' => $previousTo],
            'balanceComparisonDate' => $previousTo,
        ];

        if (!$withComparisons) {
            return ['kpi' => $kpi, 'comparisons' => [], 'periods' => $periods];
        }

        $previousBalance = $this->cashBalanceAtDate($company, $accounts, $previousTo, $scale);
        $previousTurnover = $this->cashTransactionRepository->sumGrossTurnoverByPeriodExcludeTransfers(
            $company,
            $previousFrom,
            $previousTo,
            $cashCurrency->value,
            $selectedFlowKind,
        );
        $previousInflow30 = bcadd($previousTurnover['inflow'], '0', $scale);
        $previousOutflow30 = bcadd($previousTurnover['outflow'], '0', $scale);
        $previousNetFlow30 = bcsub($previousInflow30, $previousOutflow30, $scale);

        return [
            'kpi' => $kpi,
            'comparisons' => [
                'todayBalance' => $this->comparison($todayBalance, $previousBalance, true, $scale),
                'inflow30' => $this->comparison($inflow30, $previousInflow30, true, $scale),
                'outflow30' => $this->comparison($outflow30, $previousOutflow30, false, $scale),
                'netFlow30' => $this->comparison($netFlow30, $previousNetFlow30, true, $scale),
            ],
            'periods' => $periods,
        ];
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
}
