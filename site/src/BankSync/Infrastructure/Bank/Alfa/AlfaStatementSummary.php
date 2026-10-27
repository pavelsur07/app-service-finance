<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

/**
 * Обороты Альфы по счёту за день (`/jp/v1/statement/summary`) в валюте счёта. Суммы —
 * строки с двумя знаками; остатки со знаком (овердрафт), обороты без знака. Хранение и
 * сверка с учётом — Stage 9; сейчас обороты нужны проверке песочницы.
 */
final readonly class AlfaStatementSummary
{
    public function __construct(
        public string $currency,
        public string $openingBalance,
        public string $closingBalance,
        public string $debitTurnover,
        public string $creditTurnover,
        public ?int $debitTransactions,
        public ?int $creditTransactions,
    ) {
    }

    /**
     * Полнота данных банка: входящий + приход − расход = исходящий.
     */
    public function isConsistent(): bool
    {
        $expected = bcsub(bcadd($this->openingBalance, $this->creditTurnover, 2), $this->debitTurnover, 2);

        return 0 === bccomp($expected, $this->closingBalance, 2);
    }
}
