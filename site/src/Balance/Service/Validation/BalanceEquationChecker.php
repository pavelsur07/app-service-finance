<?php

namespace App\Balance\Service\Validation;

use App\Balance\ReadModel\BalanceReport;

final class BalanceEquationChecker
{
    /**
     * @return list<string>
     */
    public function check(BalanceReport $report): array
    {
        return [];
    }
}
