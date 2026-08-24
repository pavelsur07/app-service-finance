<?php

declare(strict_types=1);

namespace App\Balance\Domain\Policy;

use App\Balance\Enum\BalanceCategoryType;
use App\Balance\ReadModel\BalanceReport;

final readonly class BalanceEquationPolicy
{
    /**
     * @return list<string>
     */
    public function check(BalanceReport $report): array
    {
        $errors = [];

        foreach ($report->getCurrencies() as $currency) {
            $assets = '0';
            $liabilities = '0';
            $equity = '0';

            foreach ($report->getRoots() as $root) {
                $amount = $root->amountsByCurrency[$currency] ?? '0';
                $amount = $this->normalize($amount);

                switch ($root->type) {
                    case BalanceCategoryType::ASSET->value:
                        $assets = bcadd($assets, $amount, 2);
                        break;
                    case BalanceCategoryType::LIABILITY->value:
                        $liabilities = bcadd($liabilities, $amount, 2);
                        break;
                    case BalanceCategoryType::EQUITY->value:
                        $equity = bcadd($equity, $amount, 2);
                        break;
                }
            }

            $right = bcadd($liabilities, $equity, 2);
            if (0 !== bccomp($assets, $right, 2)) {
                $errors[] = sprintf(
                    '%s: активы (%s) ≠ обязательства (%s) + капитал (%s)',
                    $currency,
                    $assets,
                    $liabilities,
                    $equity,
                );
            }
        }

        return $errors;
    }

    private function normalize(string $amount): string
    {
        if ('' === $amount || '-' === $amount) {
            return '0';
        }

        return $amount;
    }
}
