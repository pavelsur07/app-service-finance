<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Transaction\CashIntragroupDecision;
use App\Cash\Enum\Transaction\IntragroupDecisionType;

final readonly class CashflowReconciliation
{
    /**
     * @param array<string, IntragroupTransaction> $transactions операции в окне подбора, включая встречные ноги
     * @param array<string, string> $pairs обе ноги в периоде, outflowId => inflowId
     * @param array<string, string> $inTransit одна нога в периоде, outflowId => inflowId
     * @param array<string, IntragroupTransaction> $unpaired операции без пары в периоде
     * @param array<string, string> $manualDecisionIds outflowId => UUID решения PAIR
     * @param array<string, CashIntragroupDecision> $unpairDecisions решения UNPAIR с ногой в периоде
     * @param array<string, CashIntragroupDecision> $staleDecisions нарушенные решения в группе
     */
    public function __construct(
        public array $transactions,
        public array $pairs,
        public array $inTransit,
        public array $unpaired,
        public string $income,
        public string $expense,
        public string $unpairedNet,
        public string $transitNet,
        public bool $balanced,
        public array $manualDecisionIds,
        public array $unpairDecisions,
        public array $staleDecisions,
    ) {
    }

    public function requiresDecisionRemoval(string $transactionId): bool
    {
        foreach ($this->staleDecisions as $decision) {
            if (IntragroupDecisionType::PAIR === $decision->getType()
                && ($decision->getOutflowTransactionId() === $transactionId || $decision->getInflowTransactionId() === $transactionId)) {
                return true;
            }
        }

        return false;
    }
}
