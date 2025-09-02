<?php

namespace App\DTO;

class MoneyBalanceDTO
{
    public \DateTimeImmutable $date;
    public string $opening;
    public string $inflow;
    public string $outflow;
    public string $closing;
    public string $currency;

    public function __construct(\DateTimeImmutable $date, string $opening, string $inflow, string $outflow, string $closing, string $currency)
    {
        $this->date = $date;
        $this->opening = $opening;
        $this->inflow = $inflow;
        $this->outflow = $outflow;
        $this->closing = $closing;
        $this->currency = $currency;
    }
}
