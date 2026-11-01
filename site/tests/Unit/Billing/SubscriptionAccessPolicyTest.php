<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\SubscriptionAccessPolicy;
use App\Billing\Enum\AccessStatus;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionAccessPolicyTest extends TestCase
{
    public function testNoSubscription(): void
    {
        $policy = new SubscriptionAccessPolicy();

        self::assertSame(AccessStatus::NONE, $policy->statusOn(null, new \DateTimeImmutable('2027-01-10')));
        self::assertNull($policy->daysLeft(null, new \DateTimeImmutable('2027-01-10')));
    }

    public function testPerpetualIsAlwaysActive(): void
    {
        $policy = new SubscriptionAccessPolicy();
        $subscription = CompanySubscriptionBuilder::aSubscription()->perpetualFrom('2026-11-01')->build();

        self::assertSame(AccessStatus::ACTIVE, $policy->statusOn($subscription, new \DateTimeImmutable('2040-01-01')));
        self::assertNull($policy->daysLeft($subscription, new \DateTimeImmutable('2040-01-01')));
    }

    /**
     * @return iterable<string, array{string, AccessStatus, int}>
     */
    public static function dates(): iterable
    {
        // Оплачено по 31.01.2027, льготных дней — 5.
        yield 'первый день' => ['2027-01-01 00:00:00', AccessStatus::ACTIVE, 31];
        yield 'последний оплаченный день, 23:59' => ['2027-01-31 23:59:59', AccessStatus::ACTIVE, 1];
        yield 'первый льготный день' => ['2027-02-01 00:00:00', AccessStatus::GRACE, 0];
        yield 'последний льготный день' => ['2027-02-05 23:59:59', AccessStatus::GRACE, 0];
        yield 'только чтение' => ['2027-02-06 00:00:00', AccessStatus::READ_ONLY, 0];
    }

    #[DataProvider('dates')]
    public function testPaidSubscription(string $now, AccessStatus $status, int $daysLeft): void
    {
        $policy = new SubscriptionAccessPolicy();
        $subscription = CompanySubscriptionBuilder::aSubscription()->paid('2027-01-01', '2027-01-31')->build();
        $moment = new \DateTimeImmutable($now, new \DateTimeZone('Europe/Moscow'));

        self::assertSame($status, $policy->statusOn($subscription, $moment));
        self::assertSame($daysLeft, $policy->daysLeft($subscription, $moment));
    }

    public function testDayIsTakenInMoscow(): void
    {
        $policy = new SubscriptionAccessPolicy();
        $subscription = CompanySubscriptionBuilder::aSubscription()->paid('2027-01-01', '2027-01-31')->build();

        // 31.01 21:30 UTC = 01.02 00:30 МСК — оплаченный период уже закончился.
        self::assertSame(AccessStatus::GRACE, $policy->statusOn($subscription, new \DateTimeImmutable('2027-01-31 21:30:00', new \DateTimeZone('UTC'))));
    }
}
