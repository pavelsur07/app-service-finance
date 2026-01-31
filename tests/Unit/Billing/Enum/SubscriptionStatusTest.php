<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Enum;

use App\Billing\Enum\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

final class SubscriptionStatusTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('TRIAL', SubscriptionStatus::TRIAL->value);
        self::assertSame('ACTIVE', SubscriptionStatus::ACTIVE->value);
        self::assertSame('GRACE', SubscriptionStatus::GRACE->value);
        self::assertSame('SUSPENDED', SubscriptionStatus::SUSPENDED->value);
        self::assertSame('CANCELED', SubscriptionStatus::CANCELED->value);
    }
}
