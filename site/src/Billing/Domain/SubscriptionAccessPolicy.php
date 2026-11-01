<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Billing\Entity\CompanySubscription;
use App\Billing\Enum\AccessStatus;

/**
 * Статус доступа на дату по датам подписки: оплачено → льготные дни → только чтение.
 */
final readonly class SubscriptionAccessPolicy
{
    /** Льготные дни после «оплачено по»: работает всё, показывается напоминание. */
    public const GRACE_DAYS = 5;

    public function statusOn(?CompanySubscription $subscription, \DateTimeImmutable $now): AccessStatus
    {
        if (null === $subscription) {
            return AccessStatus::NONE;
        }

        $paidUntil = $subscription->getPaidUntil();
        if (null === $paidUntil) {
            return AccessStatus::ACTIVE;
        }

        $today = PaidPeriodCalculator::day($now);
        $paidUntil = PaidPeriodCalculator::date($paidUntil);
        if ($today <= $paidUntil) {
            return AccessStatus::ACTIVE;
        }
        if ($today <= $paidUntil->modify('+'.self::GRACE_DAYS.' days')) {
            return AccessStatus::GRACE;
        }

        return AccessStatus::READ_ONLY;
    }

    /**
     * Дней до окончания включительно сегодняшний: последний день периода → 1; истёк → 0;
     * бессрочный или подписки нет → null.
     */
    public function daysLeft(?CompanySubscription $subscription, \DateTimeImmutable $now): ?int
    {
        $paidUntil = $subscription?->getPaidUntil();
        if (null === $paidUntil) {
            return null;
        }

        $today = PaidPeriodCalculator::day($now);
        $paidUntil = PaidPeriodCalculator::date($paidUntil);
        if ($today > $paidUntil) {
            return 0;
        }

        return (int) $today->diff($paidUntil)->days + 1;
    }
}
