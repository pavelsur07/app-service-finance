<?php

namespace App\Domain\PaymentPlan;

final class PaymentPlanStatus
{
    public const DRAFT = 'DRAFT';
    public const PLANNED = 'PLANNED';
    public const APPROVED = 'APPROVED';
    public const PAID = 'PAID';
    public const CANCELED = 'CANCELED';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::PLANNED,
            self::APPROVED,
            self::PAID,
            self::CANCELED,
        ];
    }

    public static function isTerminal(string $status): bool
    {
        return $status === self::PAID;
    }
}
