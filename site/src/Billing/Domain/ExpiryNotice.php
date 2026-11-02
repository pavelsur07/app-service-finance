<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Billing\Enum\AccessStatus;
use App\Billing\Facade\DTO\BillingAccessDTO;

/**
 * Предупреждение клиенту об окончании оплаты: за NOTICE_DAYS дней до «оплачено по» (и в сам
 * этот день — `daysLeft` считает его, поэтому порог NOTICE_DAYS + 1) до конца периода
 * (или пробного), в льготные дни и в «только чтение». Бессрочный тариф и «нет тарифа» — без него.
 */
final readonly class ExpiryNotice
{
    public const NOTICE_DAYS = 7;

    public const KIND_TRIAL_ENDING = 'trial_ending';
    public const KIND_ENDING = 'ending';
    public const KIND_GRACE = 'grace';
    public const KIND_READ_ONLY = 'read_only';

    private function __construct(
        public string $kind,
        public \DateTimeImmutable $paidUntil,
        public int $daysLeft,
        /** Последний льготный день: после него — только чтение. */
        public \DateTimeImmutable $graceUntil,
        /** Период был пробным — тексты без «оплаченного». */
        public bool $trial,
    ) {
    }

    public static function forAccess(BillingAccessDTO $access): ?self
    {
        if (null === $access->paidUntil || null === $access->daysLeft) {
            return null;
        }

        $kind = match ($access->status) {
            AccessStatus::ACTIVE => $access->daysLeft <= self::NOTICE_DAYS + 1
                ? ($access->trial ? self::KIND_TRIAL_ENDING : self::KIND_ENDING)
                : null,
            AccessStatus::GRACE => self::KIND_GRACE,
            AccessStatus::READ_ONLY => self::KIND_READ_ONLY,
            AccessStatus::NONE => null,
        };
        if (null === $kind) {
            return null;
        }

        $paidUntil = PaidPeriodCalculator::date($access->paidUntil);

        return new self($kind, $paidUntil, $access->daysLeft, $paidUntil->modify('+'.SubscriptionAccessPolicy::GRACE_DAYS.' days'), $access->trial);
    }
}
