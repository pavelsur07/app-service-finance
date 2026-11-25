<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\CashflowReconciliation;
use App\Cash\Application\DTO\IntragroupTransaction;
use App\Cash\Domain\Policy\IntragroupMatcher;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Cash\Infrastructure\Query\CashIntragroupQuery;
use App\Cash\Repository\Transaction\CashIntragroupDecisionRepository;
use Psr\Log\LoggerInterface;

final readonly class BuildCashflowReconciliation
{
    public function __construct(
        private CashIntragroupQuery $query,
        private IntragroupMatcher $matcher,
        private LoggerInterface $logger,
        private CashIntragroupDecisionRepository $decisions,
    ) {
    }

    /** @param non-empty-list<string> $companyIds голова первой */
    public function __invoke(array $companyIds, \DateTimeImmutable $from, \DateTimeImmutable $to): CashflowReconciliation
    {
        $decisions = $this->decisions->forGroup($companyIds[0]);
        $start = $from->modify('-10 days');
        $end = $to->modify('+10 days');
        $rows = $this->query->transactions($companyIds, $start, $end, includeDecisions: true);
        $free = self::inPeriod($rows, $start, $end);
        $inPeriod = self::inPeriod($rows, $from, $to);
        $manual = $manualDecisionIds = $rejected = $unpairDecisions = $stale = [];
        foreach ($decisions as $decision) {
            $outId = $decision->getOutflowTransactionId();
            $inId = $decision->getInflowTransactionId();
            $out = $rows[$outId] ?? null;
            $in = $rows[$inId] ?? null;
            if (null === $out || null === $in || CashDirection::OUTFLOW !== $out->direction || !$out->isReciprocalWith($in) || !$out->amount->equals($in->amount)) {
                $stale[$decision->getId()] = $decision;
                continue;
            }
            if (IntragroupDecisionType::PAIR === $decision->getType()) {
                $manual[$outId] = $inId;
                $manualDecisionIds[$outId] = $decision->getId();
                unset($free[$outId], $free[$inId]);
            } else {
                $rejected[$outId][$inId] = true;
                if (isset($inPeriod[$outId]) || isset($inPeriod[$inId])) {
                    $unpairDecisions[$decision->getId()] = $decision;
                }
            }
        }
        $matched = $manual + $this->matcher->match(array_values($free), $rejected);
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

        return new CashflowReconciliation($rows, $pairs, $inTransit, $unpaired, $income, $expense, $unpairedNet, $transitNet, $balanced, $manualDecisionIds, $unpairDecisions, $stale);
    }

    /**
     * @param non-empty-list<string> $companyIds
     *
     * @return array<string, IntragroupTransaction>
     */
    public function manualCandidates(array $companyIds, IntragroupTransaction $source): array
    {
        $report = $this($companyIds, $source->occurredAt->modify('-15 days'), $source->occurredAt->modify('+15 days'));

        if ($report->requiresDecisionRemoval($source->id) || !isset($report->unpaired[$source->id])) {
            return [];
        }

        return array_filter($report->unpaired, static fn (IntragroupTransaction $other): bool => $source->isReciprocalWith($other)
            && $source->amount->equals($other->amount) && !$report->requiresDecisionRemoval($other->id));
    }

    /**
     * @param array<string, IntragroupTransaction> $rows
     *
     * @return array<string, IntragroupTransaction>
     */
    private static function inPeriod(array $rows, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');

        return array_filter($rows, static function (IntragroupTransaction $tx) use ($first, $last): bool {
            $day = $tx->occurredAt->format('Y-m-d');

            return $day >= $first && $day <= $last;
        });
    }
}
