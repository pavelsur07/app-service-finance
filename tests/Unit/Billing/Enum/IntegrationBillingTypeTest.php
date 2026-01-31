<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Enum;

use App\Billing\Enum\IntegrationBillingType;
use PHPUnit\Framework\TestCase;

final class IntegrationBillingTypeTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('INCLUDED', IntegrationBillingType::INCLUDED->value);
        self::assertSame('ADDON', IntegrationBillingType::ADDON->value);
    }
}
