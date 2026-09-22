<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Entity;

use App\MoySklad\Domain\StoreSnapshot;
use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use App\MoySklad\Entity\MoySkladStore;
use App\Tests\Builders\MoySklad\MoySkladStockSnapshotBuilder;
use App\Tests\Builders\MoySklad\MoySkladStockSnapshotLineBuilder;
use PHPUnit\Framework\TestCase;

final class MoySkladStockEntitiesTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-7111-8111-111111111111';
    private const CONNECTION_ID = '33333333-3333-7333-8333-333333333333';

    public function testStoreNormalizesIdentityAndDetectsChanges(): void
    {
        $now = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');
        $snapshot = new StoreSnapshot('AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', 'Store', 'external', null, '', false, $now);
        $store = new MoySkladStore('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', self::COMPANY_ID, self::CONNECTION_ID, $snapshot, $now);

        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $store->getExternalId());
        self::assertFalse($store->applySnapshot($snapshot, $now));
        self::assertTrue($store->applySnapshot(new StoreSnapshot($snapshot->externalId, 'Renamed', 'external', null, '', false, $now), $now));
        self::assertSame('Renamed', $store->getName());
    }

    public function testSnapshotHasGuardedTerminalTransitions(): void
    {
        $snapshot = MoySkladStockSnapshotBuilder::aSnapshot()->withTenant(self::COMPANY_ID, self::CONNECTION_ID)->build();
        self::assertSame('building', $snapshot->getStatus());

        $snapshot->complete(new \DateTimeImmutable('2026-09-21T08:01:00+00:00'));
        self::assertSame('completed', $snapshot->getStatus());
        self::assertNotNull($snapshot->getCompletedAt());

        $this->expectException(\LogicException::class);
        $snapshot->fail(new \DateTimeImmutable('2026-09-21T08:02:00+00:00'));
    }

    public function testLineKeepsExactlyOneAssortmentReference(): void
    {
        $product = MoySkladStockSnapshotLineBuilder::aLine()->build();
        self::assertSame('88888888-8888-4888-8888-888888888888', $product->getProductExternalId());
        self::assertNull($product->getVariantExternalId());
        self::assertSame('1', $product->getStock());

        $variant = new MoySkladStockSnapshotLine(
            'eeeeeeee-eeee-7eee-8eee-eeeeeeeeeeee',
            self::COMPANY_ID,
            self::CONNECTION_ID,
            'bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb',
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'variant',
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            '4.125',
            '0.125',
            '0',
        );
        self::assertNull($variant->getProductExternalId());
        self::assertSame('ffffffff-ffff-4fff-8fff-ffffffffffff', $variant->getVariantExternalId());
    }

    public function testLineRejectsUnknownAssortmentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MoySkladStockSnapshotLine(
            'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            self::COMPANY_ID,
            self::CONNECTION_ID,
            'bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb',
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'bundle',
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            '1',
            '0',
            '0',
        );
    }
}
