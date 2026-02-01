<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Entity;

use App\Billing\Entity\Integration;
use App\Billing\Entity\Plan;
use App\Billing\Entity\Subscription;
use App\Billing\Entity\SubscriptionIntegration;
use App\Billing\Enum\BillingPeriod;
use App\Billing\Enum\IntegrationBillingType;
use App\Billing\Enum\SubscriptionIntegrationStatus;
use App\Billing\Enum\SubscriptionStatus;
use App\Company\Entity\Company;
use PHPUnit\Framework\TestCase;

final class SubscriptionIntegrationTest extends TestCase
{
    public function testConstructorDefaultsToActiveWithStartedAt(): void
    {
        $subscriptionIntegration = new SubscriptionIntegration(
            '44444444-4444-4444-4444-444444444444',
            $this->createSubscription(),
            $this->createIntegration(),
        );

        self::assertSame(SubscriptionIntegrationStatus::ACTIVE, $subscriptionIntegration->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $subscriptionIntegration->getStartedAt());
        self::assertNull($subscriptionIntegration->getEndedAt());
    }

    public function testDisableThenActivateTransitions(): void
    {
        $subscriptionIntegration = new SubscriptionIntegration(
            '55555555-5555-5555-5555-555555555555',
            $this->createSubscription(),
            $this->createIntegration(),
        );

        $disabledAt = new \DateTimeImmutable('2024-02-01 12:00:00');
        $subscriptionIntegration->disable($disabledAt);

        self::assertSame(SubscriptionIntegrationStatus::DISABLED, $subscriptionIntegration->getStatus());
        self::assertSame($disabledAt, $subscriptionIntegration->getEndedAt());

        $activatedAt = new \DateTimeImmutable('2024-02-02 12:00:00');
        $subscriptionIntegration->activate($activatedAt);

        self::assertSame(SubscriptionIntegrationStatus::ACTIVE, $subscriptionIntegration->getStatus());
        self::assertSame($activatedAt, $subscriptionIntegration->getStartedAt());
        self::assertNull($subscriptionIntegration->getEndedAt());
    }

    public function testActivateWhileActiveThrows(): void
    {
        $subscriptionIntegration = new SubscriptionIntegration(
            '66666666-6666-6666-6666-666666666666',
            $this->createSubscription(),
            $this->createIntegration(),
        );

        $this->expectException(\LogicException::class);
        $subscriptionIntegration->activate(new \DateTimeImmutable('2024-03-01 12:00:00'));
    }

    public function testDisableWhileDisabledThrows(): void
    {
        $subscriptionIntegration = new SubscriptionIntegration(
            '77777777-7777-7777-7777-777777777777',
            $this->createSubscription(),
            $this->createIntegration(),
        );

        $subscriptionIntegration->disable(new \DateTimeImmutable('2024-03-10 12:00:00'));

        $this->expectException(\LogicException::class);
        $subscriptionIntegration->disable(new \DateTimeImmutable('2024-03-11 12:00:00'));
    }

    private function createSubscription(): Subscription
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 00:00:00');

        return new Subscription(
            '33333333-3333-3333-3333-333333333333',
            new Company('22222222-2222-2222-2222-222222222222'),
            $this->createPlan(),
            SubscriptionStatus::ACTIVE,
            null,
            $createdAt,
            $createdAt->modify('+1 month'),
            false,
            $createdAt,
        );
    }

    private function createPlan(): Plan
    {
        return new Plan(
            '11111111-1111-1111-1111-111111111111',
            'BASIC',
            'Basic Plan',
            9900,
            'USD',
            BillingPeriod::MONTH,
            true,
            new \DateTimeImmutable('2024-01-01 00:00:00'),
        );
    }

    private function createIntegration(): Integration
    {
        return new Integration(
            '88888888-8888-8888-8888-888888888888',
            'INTEGRATION_CODE',
            'Integration Name',
            IntegrationBillingType::PAID,
            1500,
            'USD',
            true,
        );
    }
}
