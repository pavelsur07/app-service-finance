<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\CashflowReconciliation;
use App\Cash\Application\DTO\IntragroupTransaction;
use App\Cash\Domain\Policy\IntragroupMatcher;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Infrastructure\Query\CashIntragroupQuery;
use Psr\Log\LoggerInterface;

final readonly class BuildCashflowReconciliation
{
    public function __construct(
        private CashIntragroupQuery $query,
        private IntragroupMatcher $matcher,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<string> $companyIds */
    public function __invoke(array $companyIds, \DateTimeImmutable $from, \DateTimeImmutable $to): CashflowReconciliation
    {
        $rows = $this->query->transactions($companyIds, $from->modify('-10 days'), $to->modify('+10 days'));
        $matched = $this->matcher->match(array_values($rows));
        $firstDate = $from->format('Y-m-d');
        $lastDate = $to->format('Y-m-d');
        $inPeriod = array_filter($rows, static function (IntragroupTransaction $tx) use ($firstDate, $lastDate): bool {
            $day = $tx->occurredAt->format('Y-m-d');

            return $day >= $firstDate && $day <= $lastDate;
        });
        $unpaired = $inPeriod;
        $pairs = $inTransit = [];
        $transitNet = $income = $expense = $unpairedNet = '0.00';
        foreach ($matched as $out => $in) {
            $hasOut = isset($inPeriod[$out]);
            $hasIn = isset($inPeriod[$in]);
            if ($hasOut && $hasIn) {
                $pairs[$out] = $in;
            } elseif ($hasOut || $hasIn) {
                $inTransit[$out] = $in;
                $transitNet = $hasOut ? bcsub($transitNet, $rows[$out]->amount->toDecimalString(), 2) : bcadd($transitNet, $rows[$in]->amount->toDecimalString(), 2);
            }
            unset($unpaired[$out], $unpaired[$in]);
        }
        foreach ($inPeriod as $tx) {
            $amount = $tx->amount->toDecimalString();
            if (CashDirection::INFLOW === $tx->direction) {
                $income = bcadd($income, $amount, 2);
            } else {
                $expense = bcadd($expense, $amount, 2);
            }
        }
        foreach ($unpaired as $tx) {
            $amount = $tx->amount->toDecimalString();
            $unpairedNet = CashDirection::INFLOW === $tx->direction ? bcadd($unpairedNet, $amount, 2) : bcsub($unpairedNet, $amount, 2);
        }
        $balanced = bcsub($income, $expense, 2) === bcadd($unpairedNet, $transitNet, 2);
        if (!$balanced) {
            $this->logger->error('Не сошёлся контроль внутригрупповых оборотов ДДС.', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
        }

        return new CashflowReconciliation($rows, $pairs, $inTransit, $unpaired, $income, $expense, $unpairedNet, $transitNet, $balanced);
    }
}
