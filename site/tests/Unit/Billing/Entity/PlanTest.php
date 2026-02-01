<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Entity;

use App\Billing\Entity\Plan;
use App\Billing\Enum\BillingPeriod;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    public function testPlanCreation(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 00:00:00');
        $plan = new Plan(
            '11111111-1111-1111-1111-111111111111',
            'BASIC',
            'Basic Plan',
            9900,
            'USD',
            BillingPeriod::MONTH,
            true,
            $createdAt,
        );

        self::assertSame('11111111-1111-1111-1111-111111111111', $plan->getId());
        self::assertSame('BASIC', $plan->getCode());
        self::assertSame('Basic Plan', $plan->getName());
        self::assertSame(9900, $plan->getPriceAmount());
        self::assertSame('USD', $plan->getPriceCurrency());
        self::assertSame(BillingPeriod::MONTH, $plan->getBillingPeriod());
        self::assertTrue($plan->isActive());
        self::assertSame($createdAt, $plan->getCreatedAt());
    }
}
