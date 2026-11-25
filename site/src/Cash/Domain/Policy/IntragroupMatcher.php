<?php

declare(strict_types=1);

namespace App\Cash\Domain\Policy;

use App\Cash\Application\DTO\IntragroupTransaction;
use App\Cash\Enum\Transaction\CashDirection;

final class IntragroupMatcher
{
    private const MAX_DAYS = 5;

    public static function acceptsDates(\DateTimeImmutable $outflow, \DateTimeImmutable $inflow): bool
    {
        $day = $inflow->format('Y-m-d');

        return $day >= $outflow->format('Y-m-d') && $day <= $outflow->modify('+'.self::MAX_DAYS.' days')->format('Y-m-d');
    }

    /**
     * @param list<IntragroupTransaction> $transactions операции одной группы, свободные от ручных пар
     * @param array<string, array<string, true>> $rejected запрещённые сочетания outflowId => [inflowId => true]
     *
     * @return array<string, string> outflowId => inflowId
     */
    public function match(array $transactions, array $rejected = []): array
    {
        $outflows = [];
        $inflows = [];
        usort($transactions, static fn (IntragroupTransaction $a, IntragroupTransaction $b): int => [$a->occurredAt, $a->id] <=> [$b->occurredAt, $b->id]);
        foreach ($transactions as $transaction) {
            if ('RUB' !== $transaction->amount->currency() || null === $transaction->companyInn || null === $transaction->counterpartyInn) {
                continue;
            }
            if (CashDirection::OUTFLOW === $transaction->direction) {
                $outflows[] = $transaction;
            } else {
                $inflows[$transaction->companyInn][$transaction->counterpartyInn][$transaction->amount->amountMinor()][$transaction->occurredAt->format('Y-m-d')][$transaction->id] = $transaction;
            }
        }

        $pairs = [];
        for ($gap = 0; $gap <= self::MAX_DAYS; ++$gap) {
            foreach ($outflows as $out) {
                if (isset($pairs[$out->id])) {
                    continue;
                }
                $day = $out->occurredAt->modify("+$gap days")->format('Y-m-d');
                $minor = $out->amount->amountMinor();
                if (!isset($inflows[$out->counterpartyInn][$out->companyInn][$minor][$day])) {
                    continue;
                }
                $bucket = &$inflows[$out->counterpartyInn][$out->companyInn][$minor][$day];
                $chosen = null;
                foreach ($bucket as $in) {
                    if ($in->companyId !== $out->companyId && !isset($rejected[$out->id][$in->id])) {
                        $chosen = $in->id;
                        break;
                    }
                }
                if (null !== $chosen) {
                    $pairs[$out->id] = $chosen;
                    unset($bucket[$chosen]);
                }
                unset($bucket);
            }
        }
        ksort($pairs);

        return $pairs;
    }
}
