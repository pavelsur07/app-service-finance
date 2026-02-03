<?php

namespace App\Deals\Service;

use App\Deals\Entity\Deal;
use App\Deals\Enum\DealAdjustmentType;

class DealTotalsCalculator
{
    private const SCALE = 2;

    public function recalc(Deal $deal): void
    {
        $totalItems = '0';
        foreach ($deal->getItems() as $item) {
            $totalItems = $this->add($totalItems, $item->getAmount());
        }

        $adjustmentsSigned = '0';
        foreach ($deal->getAdjustments() as $adjustment) {
            $amount = $adjustment->getAmount();
            $type = $adjustment->getType();

            if ($type === DealAdjustmentType::RETURN || $type === DealAdjustmentType::DISCOUNT) {
                $amount = $this->negate($amount);
            }

            $adjustmentsSigned = $this->add($adjustmentsSigned, $amount);
        }

        $totalAmount = $this->add($totalItems, $adjustmentsSigned);

        $totalDirectCost = '0';
        foreach ($deal->getCharges() as $charge) {
            $totalDirectCost = $this->add($totalDirectCost, $charge->getAmount());
        }

        $deal->setTotalAmount($totalAmount);
        $deal->setTotalDirectCost($totalDirectCost);
    }

    private function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::SCALE);
    }

    private function negate(string $amount): string
    {
        $normalized = ltrim($amount, '+');

        if (str_starts_with($normalized, '-')) {
            return $normalized;
        }

        return '-' . $normalized;
    }
}
