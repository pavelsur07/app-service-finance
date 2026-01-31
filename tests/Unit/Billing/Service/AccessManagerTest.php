<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Service;

use App\Billing\Service\AccessManager;
use PHPUnit\Framework\TestCase;

final class AccessManagerTest extends TestCase
{
    public function testCanAlwaysReturnsTrue(): void
    {
        $manager = new AccessManager();

        self::assertTrue($manager->can('any.permission'));
    }

    public function testDenyUnlessCanDoesNothing(): void
    {
        $manager = new AccessManager();

        $manager->denyUnlessCan('any.permission');

        self::assertTrue(true);
    }

    public function testIntegrationEnabledAlwaysReturnsTrue(): void
    {
        $manager = new AccessManager();

        self::assertTrue($manager->integrationEnabled('integration.code'));
    }

    public function testLimitReturnsPermitAllState(): void
    {
        $manager = new AccessManager();
        $limit = $manager->limit('metric.code');

        self::assertSame('metric.code', $limit->getMetric());
        self::assertSame(0, $limit->getUsed());
        self::assertNull($limit->getSoftLimit());
        self::assertNull($limit->getHardLimit());
        self::assertNull($limit->getRemaining());
        self::assertFalse($limit->isSoftExceeded());
        self::assertFalse($limit->isHardExceeded());
        self::assertTrue($limit->canWrite());
    }
}
