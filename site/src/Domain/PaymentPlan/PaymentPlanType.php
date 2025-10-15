<?php

namespace App\Domain\PaymentPlan;

final class PaymentPlanType
{
    public const INFLOW = 'INFLOW';
    public const OUTFLOW = 'OUTFLOW';
    public const TRANSFER = 'TRANSFER';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::INFLOW,
            self::OUTFLOW,
            self::TRANSFER,
        ];
    }
}
