<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Enum;

use App\Billing\Enum\SubscriptionIntegrationStatus;
use PHPUnit\Framework\TestCase;

final class SubscriptionIntegrationStatusTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('ACTIVE', SubscriptionIntegrationStatus::ACTIVE->value);
        self::assertSame('DISABLED', SubscriptionIntegrationStatus::DISABLED->value);
    }
}
