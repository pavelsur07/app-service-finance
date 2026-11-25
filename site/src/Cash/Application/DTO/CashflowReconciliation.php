<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashflowReconciliation
{
    /**
     * @param array<string, IntragroupTransaction> $transactions операции в окне подбора, включая встречные ноги
     * @param array<string, string> $pairs обе ноги в периоде, outflowId => inflowId
     * @param array<string, string> $inTransit одна нога в периоде, outflowId => inflowId
     * @param array<string, IntragroupTransaction> $unpaired операции без пары в периоде
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
    ) {
    }
}
