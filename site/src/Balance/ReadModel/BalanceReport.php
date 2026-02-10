<?php

namespace App\Balance\ReadModel;

use App\Balance\DTO\BalanceRowView;

final class BalanceReport
{
    /**
     * @param list<string> $currencies
     * @param list<BalanceRowView> $roots
     * @param array<string,float> $totals
     */
    public function __construct(
        private \DateTimeImmutable $date,
        private array $currencies,
        private array $roots,
        private array $totals,
    ) {
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return list<string>
     */
    public function getCurrencies(): array
    {
        return $this->currencies;
    }

    /**
     * @return list<BalanceRowView>
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * @return array<string,float>
     */
    public function getTotals(): array
    {
        return $this->totals;
    }
}
