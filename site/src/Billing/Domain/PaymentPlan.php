<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Webmozart\Assert\Assert;

/**
 * Результат расчёта оплаты: вид, оплаченный период (уже с перенесёнными днями) и сколько
 * перенесено из остатка текущего тарифа (только у повышения).
 */
final readonly class PaymentPlan
{
    public function __construct(
        public PaymentPlanKind $kind,
        public PaidPeriod $period,
        public int $carriedDays = 0,
        public int $carriedKopecks = 0,
    ) {
        Assert::greaterThanEq($carriedDays, 0);
        Assert::greaterThanEq($carriedKopecks, 0);
        if (PaymentPlanKind::UPGRADE !== $kind) {
            Assert::same($carriedDays + $carriedKopecks, 0, 'Остаток переносится только при повышении.');
        }
    }
}
