<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Enum;

use App\Billing\Enum\FeatureType;
use PHPUnit\Framework\TestCase;

final class FeatureTypeTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('BOOLEAN', FeatureType::BOOLEAN->value);
        self::assertSame('LIMIT', FeatureType::LIMIT->value);
        self::assertSame('ENUM', FeatureType::ENUM->value);
    }
}
