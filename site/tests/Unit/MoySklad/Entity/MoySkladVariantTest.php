<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Entity;

use App\MoySklad\Domain\VariantSnapshot;
use App\MoySklad\Entity\MoySkladVariant;
use PHPUnit\Framework\TestCase;

final class MoySkladVariantTest extends TestCase
{
    public function testNormalizesParentIdOnCreateAndUpdate(): void
    {
        $date = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $snapshot = new VariantSnapshot('00000000-0000-4000-8000-000000000101', 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', 'Test', '', null, null, [], false, $date);
        $variant = new MoySkladVariant('00000000-0000-7000-8000-000000000111', '00000000-0000-4000-8000-000000000003', '00000000-0000-4000-8000-000000000004', $snapshot, $date);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $variant->getProductExternalId());
        self::assertFalse($variant->applySnapshot($snapshot, $date));
    }
}
