<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Company\Entity\Company;
use App\Finance\Infrastructure\Query\BalanceDynamicsQuery;
use App\Shared\Domain\ValueObject\Money;

final readonly class FinanceBalanceDynamicsProvider
{
    private const ALLOWED_PERIODS = [30, 60, 90];

    public function __construct(private BalanceDynamicsQuery $query)
    {
    }

    /**
     * @return array{
     *     periodDays:int,
     *     from:\DateTimeImmutable,
     *     to:\DateTimeImmutable,
     *     currency:string,
     *     minimumBalance:?Money,
     *     points:list<array{
     *         date:string,
     *         balance:string,
     *         belowMinimum:bool,
     *         flows:array{operating:string,financing:string,investing:string}
     *     }>
     * }
     */
    public function build(
        Company $company,
        FiatCurrency $currency,
        int $periodDays,
        \DateTimeImmutable $today,
    ): array {
        if (!in_array($periodDays, self::ALLOWED_PERIODS, true)) {
            throw new \InvalidArgumentException('Balance dynamics period must be one of: 30, 60, 90.');
        }

        $to = $today->setTime(0, 0);
        $from = $to->modify(sprintf('-%d days', $periodDays - 1));
        $companyId = (string) $company->getId();
        $balanceRows = $this->query->fetchBalanceSeries($companyId, $currency->value, $from, $to);
        $hasAccounts = false;
        foreach ($balanceRows as $row) {
            if ($row['account_count'] > 0) {
                $hasAccounts = true;
                break;
            }
        }
        $minimumBalance = $company->getMinimumBalance()->currency() === $currency->value
            ? $company->getMinimumBalance()
            : null;

        if (!$hasAccounts) {
            return [
                'periodDays' => $periodDays,
                'from' => $from,
                'to' => $to,
                'currency' => $currency->value,
                'minimumBalance' => $minimumBalance,
                'points' => [],
            ];
        }

        $scale = $currency->scale();
        $zero = bcadd('0', '0', $scale);
        $flowsByDate = [];
        foreach ($this->query->fetchFlowSeries($companyId, $currency->value, $from, $to) as $row) {
            $key = match ($row['flow_kind']) {
                CashflowFlowKind::OPERATING->value => 'operating',
                CashflowFlowKind::FINANCING->value => 'financing',
                CashflowFlowKind::INVESTING->value => 'investing',
                default => null,
            };
            if (null !== $key) {
                $flowsByDate[$row['date']][$key] = bcadd(
                    $flowsByDate[$row['date']][$key] ?? '0',
                    $row['value'],
                    $scale,
                );
            }
        }

        $points = [];
        foreach ($balanceRows as $row) {
            $balance = bcadd($row['balance'], '0', $scale);
            $flows = $flowsByDate[$row['date']] ?? [];
            $points[] = [
                'date' => $row['date'],
                'balance' => $balance,
                'belowMinimum' => $row['account_count'] > 0
                    && null !== $minimumBalance
                    && bccomp($balance, $minimumBalance->toDecimalString(), $scale) < 0,
                'flows' => [
                    'operating' => $flows['operating'] ?? $zero,
                    'financing' => $flows['financing'] ?? $zero,
                    'investing' => $flows['investing'] ?? $zero,
                ],
            ];
        }

        return [
            'periodDays' => $periodDays,
            'from' => $from,
            'to' => $to,
            'currency' => $currency->value,
            'minimumBalance' => $minimumBalance,
            'points' => $points,
        ];
    }
}
