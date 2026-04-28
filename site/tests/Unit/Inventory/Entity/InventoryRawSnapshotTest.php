<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Entity;

use App\Inventory\Entity\InventoryRawSnapshot;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class InventoryRawSnapshotTest extends TestCase
{
    public function testIdIsGenerated(): void
    {
        $snapshot = InventoryRawSnapshotBuilder::aRawSnapshot()->build();

        self::assertTrue(Uuid::isValid($snapshot->getId()));
        self::assertSame(7, Uuid::fromString($snapshot->getId())->getFields()->getVersion());
    }

    public function testConstructorStoresAllRawFields(): void
    {
        $fetchedAt = new \DateTimeImmutable('2026-04-21T12:00:00+00:00');
        $requestParams = ['cursor' => 'next-token', 'limit' => 500];
        $responseBody = ['result' => [['sku' => 'SKU-001', 'quantity' => 15]]];

        $snapshot = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId(InventoryRawSnapshotBuilder::DEFAULT_COMPANY_ID)
            ->withSnapshotSessionId(InventoryRawSnapshotBuilder::DEFAULT_SNAPSHOT_SESSION_ID)
            ->withSource(MarketplaceType::OZON)
            ->withEndpoint('/v4/product/info/stocks')
            ->withRequestParams($requestParams)
            ->withResponseStatus(207)
            ->withResponseBody($responseBody)
            ->withPageNumber(3)
            ->withFetchedAt($fetchedAt)
            ->withFetchDurationMs(999)
            ->withCorrelationId(InventoryRawSnapshotBuilder::DEFAULT_CORRELATION_ID)
            ->build();

        self::assertSame(InventoryRawSnapshotBuilder::DEFAULT_COMPANY_ID, $snapshot->getCompanyId());
        self::assertSame(InventoryRawSnapshotBuilder::DEFAULT_SNAPSHOT_SESSION_ID, $snapshot->getSnapshotSessionId());
        self::assertSame(MarketplaceType::OZON, $snapshot->getSource());
        self::assertSame('/v4/product/info/stocks', $snapshot->getSourceEndpoint());
        self::assertSame($requestParams, $snapshot->getRequestParams());
        self::assertSame(207, $snapshot->getResponseStatus());
        self::assertSame($responseBody, $snapshot->getResponseBody());
        self::assertSame(3, $snapshot->getPageNumber());
        self::assertSame($fetchedAt, $snapshot->getFetchedAt());
        self::assertSame(999, $snapshot->getFetchDurationMs());
        self::assertSame(InventoryRawSnapshotBuilder::DEFAULT_CORRELATION_ID, $snapshot->getCorrelationId());
    }

    public function testDefaultsAreInitialized(): void
    {
        $snapshot = InventoryRawSnapshotBuilder::aRawSnapshot()->build();

        self::assertFalse($snapshot->isProcessed());
        self::assertNull($snapshot->getProcessedAt());
        self::assertNull($snapshot->getProcessingError());
        self::assertInstanceOf(\DateTimeImmutable::class, $snapshot->getCreatedAt());
    }

    public function testMarkAsProcessedSetsFlagsAndTimestamp(): void
    {
        $snapshot = InventoryRawSnapshotBuilder::aRawSnapshot()->build();

        $snapshot->markAsProcessed();

        self::assertTrue($snapshot->isProcessed());
        self::assertInstanceOf(\DateTimeImmutable::class, $snapshot->getProcessedAt());
        self::assertNull($snapshot->getProcessingError());
    }

    public function testMarkAsFailedStoresProcessingError(): void
    {
        $snapshot = InventoryRawSnapshotBuilder::aRawSnapshot()->build();
        $snapshot->markAsProcessed();

        $snapshot->markAsFailed('Invalid schema in raw response payload');

        self::assertFalse($snapshot->isProcessed());
        self::assertNull($snapshot->getProcessedAt());
        self::assertSame('Invalid schema in raw response payload', $snapshot->getProcessingError());
    }

    public function testInvalidCompanyIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId('not-a-uuid')
            ->build();
    }

    public function testInvalidSnapshotSessionIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withSnapshotSessionId('not-a-uuid')
            ->build();
    }

    public function testInvalidCorrelationIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCorrelationId('not-a-uuid')
            ->build();
    }

    public function testNegativeFetchDurationThrows(): void
    {
        $this->expectException(\DomainException::class);

        InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withFetchDurationMs(-1)
            ->build();
    }

    public function testPageNumberZeroThrows(): void
    {
        $this->expectException(\DomainException::class);

        InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withPageNumber(0)
            ->build();
    }

    public function testEntityHasNoPublicSettersForRawFields(): void
    {
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setCompanyId'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setSnapshotSessionId'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setSource'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setSourceEndpoint'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setRequestParams'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setResponseStatus'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setResponseBody'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setFetchedAt'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setFetchDurationMs'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setCorrelationId'));
        self::assertFalse(method_exists(InventoryRawSnapshot::class, 'setPageNumber'));
    }
}
