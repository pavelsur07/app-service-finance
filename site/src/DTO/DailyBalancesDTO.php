<?php

namespace App\DTO;

class DailyBalancesDTO
{
    /** @var list<MoneyBalanceDTO> */
    public array $balances;
    public string $totalOpening;
    public string $totalInflow;
    public string $totalOutflow;
    public string $totalClosing;
    public string $currency;

    /**
     * @param list<MoneyBalanceDTO> $balances
     */
    public function __construct(array $balances, string $currency)
    {
        $this->balances = $balances;
        $this->currency = $currency;
        $this->totalOpening = $balances[0]->opening ?? '0';
        $this->totalClosing = $balances[count($balances)-1]->closing ?? '0';
        $this->totalInflow = '0';
        $this->totalOutflow = '0';
        foreach ($balances as $b) {
            $this->totalInflow = bcadd($this->totalInflow, $b->inflow, 2);
            $this->totalOutflow = bcadd($this->totalOutflow, $b->outflow, 2);
        }
    }
}
