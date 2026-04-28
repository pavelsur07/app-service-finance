<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Entity;

use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class StockSnapshotTest extends TestCase
{
    public function testIdIsGenerated(): void
    {
        $snapshot = StockSnapshotBuilder::aStockSnapshot()->build();

        self::assertTrue(Uuid::isValid($snapshot->getId()));
        self::assertSame(7, Uuid::fromString($snapshot->getId())->getFields()->getVersion());
    }

    public function testConstructorStoresAllFields(): void
    {
        $snapshotDate = new \DateTimeImmutable('2026-04-22T21:45:33+00:00');
        $snapshotAt = new \DateTimeImmutable('2026-04-22T12:15:00+00:00');

        $snapshot = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId(StockSnapshotBuilder::DEFAULT_COMPANY_ID)
            ->withSnapshotSessionId(StockSnapshotBuilder::DEFAULT_SNAPSHOT_SESSION_ID)
            ->withSnapshotDate($snapshotDate)
            ->withSnapshotAt($snapshotAt)
            ->withListingId(StockSnapshotBuilder::DEFAULT_LISTING_ID)
            ->withProductId(StockSnapshotBuilder::DEFAULT_PRODUCT_ID)
            ->withLocationId(StockSnapshotBuilder::DEFAULT_LOCATION_ID)
            ->withStatus(StockStatus::InTransitToCustomer)
            ->withQuantity('9999.125')
            ->withSource(MarketplaceType::OZON)
            ->withRawSnapshotId(StockSnapshotBuilder::DEFAULT_RAW_SNAPSHOT_ID)
            ->build();

        self::assertSame(StockSnapshotBuilder::DEFAULT_COMPANY_ID, $snapshot->getCompanyId());
        self::assertSame(StockSnapshotBuilder::DEFAULT_SNAPSHOT_SESSION_ID, $snapshot->getSnapshotSessionId());
        self::assertSame('2026-04-22 00:00:00', $snapshot->getSnapshotDate()->format('Y-m-d H:i:s'));
        self::assertSame($snapshotAt, $snapshot->getSnapshotAt());
        self::assertSame(StockSnapshotBuilder::DEFAULT_LISTING_ID, $snapshot->getListingId());
        self::assertSame(StockSnapshotBuilder::DEFAULT_PRODUCT_ID, $snapshot->getProductId());
        self::assertSame(StockSnapshotBuilder::DEFAULT_LOCATION_ID, $snapshot->getLocationId());
        self::assertSame(StockStatus::InTransitToCustomer, $snapshot->getStatus());
        self::assertSame('9999.125', $snapshot->getQuantity());
        self::assertSame(MarketplaceType::OZON, $snapshot->getSource());
        self::assertSame(StockSnapshotBuilder::DEFAULT_RAW_SNAPSHOT_ID, $snapshot->getRawSnapshotId());
    }

    public function testListingIdCanBeNull(): void
    {
        $snapshot = StockSnapshotBuilder::aStockSnapshot()
            ->withListingId(null)
            ->build();

        self::assertNull($snapshot->getListingId());
    }

    public function testProductIdCanBeNull(): void
    {
        $snapshot = StockSnapshotBuilder::aStockSnapshot()
            ->withProductId(null)
            ->build();

        self::assertNull($snapshot->getProductId());
    }

    public function testCreatedAtIsInitialized(): void
    {
        $snapshot = StockSnapshotBuilder::aStockSnapshot()->build();

        self::assertInstanceOf(\DateTimeImmutable::class, $snapshot->getCreatedAt());
    }

    public function testQuantityIsString(): void
    {
        $snapshot = StockSnapshotBuilder::aStockSnapshot()
            ->withQuantity('100.500')
            ->build();

        self::assertIsString($snapshot->getQuantity());
        self::assertSame('100.500', $snapshot->getQuantity());
    }

    public function testEntityHasNoPublicSetters(): void
    {
        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(StockSnapshot::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach ($publicMethods as $method) {
            self::assertStringStartsNotWith('set', $method);
        }
    }

    public function testInvalidCompanyIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId('not-a-uuid')
            ->build();
    }

    public function testInvalidListingIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockSnapshotBuilder::aStockSnapshot()
            ->withListingId('not-a-uuid')
            ->build();
    }

    public function testInvalidProductIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockSnapshotBuilder::aStockSnapshot()
            ->withProductId('not-a-uuid')
            ->build();
    }

    public function testInvalidLocationIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockSnapshotBuilder::aStockSnapshot()
            ->withLocationId('not-a-uuid')
            ->build();
    }

    public function testInvalidRawSnapshotIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockSnapshotBuilder::aStockSnapshot()
            ->withRawSnapshotId('not-a-uuid')
            ->build();
    }

    public function testInvalidQuantityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockSnapshotBuilder::aStockSnapshot()
            ->withQuantity('not-a-number')
            ->build();
    }
}
