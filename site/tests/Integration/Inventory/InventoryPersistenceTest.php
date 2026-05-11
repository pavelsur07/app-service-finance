<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Entity\Location;
use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
use App\Tests\Builders\Inventory\InventorySnapshotSessionBuilder;
use App\Tests\Builders\Inventory\LocationBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class InventoryPersistenceTest extends IntegrationTestCase
{
    public function testInventoryEntitiesPersistAndReloadWithExpectedTypes(): void
    {
        $location = LocationBuilder::aLocation()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withMetadata(['zone' => 'A-1', 'tags' => ['cold', 'priority']])
            ->build();

        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withExpectedPages(7)
            ->build();

        $rawSnapshot = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withSnapshotSessionId($session->getId())
            ->withRequestParams(['page' => 2, 'filters' => ['active' => true]])
            ->withResponseBody(['rows' => [['sku' => 'SKU-42', 'qty' => 12.345]]])
            ->build();

        $stockSnapshot = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withSnapshotSessionId($session->getId())
            ->withLocationId($location->getId())
            ->withRawSnapshotId($rawSnapshot->getId())
            ->withListingId(null)
            ->withProductId(null)
            ->withQuantity('42.125')
            ->withReservedQuantity('5.000')
            ->withSourceSku('SKU-42')
            ->withSourceOfferId('OFFER-42')
            ->withFulfillmentType('fbs')
            ->withMappingStatus(StockSnapshotMappingStatus::Unmapped)
            ->build();

        $this->em->persist($location);
        $this->em->persist($session);
        $this->em->persist($rawSnapshot);
        $this->em->persist($stockSnapshot);
        $this->em->flush();
        $this->em->clear();

        $loadedLocation = $this->em->getRepository(Location::class)->find($location->getId());
        $loadedSession = $this->em->getRepository(InventorySnapshotSession::class)->find($session->getId());
        $loadedRaw = $this->em->getRepository(InventoryRawSnapshot::class)->find($rawSnapshot->getId());
        $loadedStock = $this->em->getRepository(StockSnapshot::class)->find($stockSnapshot->getId());

        self::assertInstanceOf(Location::class, $loadedLocation);
        self::assertInstanceOf(InventorySnapshotSession::class, $loadedSession);
        self::assertInstanceOf(InventoryRawSnapshot::class, $loadedRaw);
        self::assertInstanceOf(StockSnapshot::class, $loadedStock);

        self::assertSame(['zone' => 'A-1', 'tags' => ['cold', 'priority']], $loadedLocation->getMetadata());
        self::assertSame(['page' => 2, 'filters' => ['active' => true]], $loadedRaw->getRequestParams());
        self::assertSame(['rows' => [['sku' => 'SKU-42', 'qty' => 12.345]]], $loadedRaw->getResponseBody());

        self::assertSame('42.125', $loadedStock->getQuantity());
        self::assertSame('5.000', $loadedStock->getReservedQuantity());
        self::assertSame('SKU-42', $loadedStock->getSourceSku());
        self::assertSame('OFFER-42', $loadedStock->getSourceOfferId());
        self::assertSame('fbs', $loadedStock->getFulfillmentType());
        self::assertSame(StockSnapshotMappingStatus::Unmapped, $loadedStock->getMappingStatus());
        self::assertNull($loadedStock->getListingId());
        self::assertNull($loadedStock->getProductId());
    }

    public function testUnmappedSnapshotsWithDifferentSourceSkuDoNotConflictWithinSameDay(): void
    {
        $location = LocationBuilder::aLocation()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->build();

        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->build();

        $rawSnapshot = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withSnapshotSessionId($session->getId())
            ->build();

        $first = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withSnapshotSessionId($session->getId())
            ->withSnapshotDate(new \DateTimeImmutable('2026-05-11T00:00:00+00:00'))
            ->withLocationId($location->getId())
            ->withRawSnapshotId($rawSnapshot->getId())
            ->withSource(MarketplaceType::OZON)
            ->withListingId(null)
            ->withProductId(null)
            ->withFulfillmentType('fbo')
            ->withSourceSku('SKU-111')
            ->withMappingStatus(StockSnapshotMappingStatus::Unmapped)
            ->build();

        $second = StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withSnapshotSessionId($session->getId())
            ->withSnapshotDate(new \DateTimeImmutable('2026-05-11T00:00:00+00:00'))
            ->withLocationId($location->getId())
            ->withRawSnapshotId($rawSnapshot->getId())
            ->withSource(MarketplaceType::OZON)
            ->withListingId(null)
            ->withProductId(null)
            ->withFulfillmentType('fbo')
            ->withSourceSku('SKU-222')
            ->withMappingStatus(StockSnapshotMappingStatus::Unmapped)
            ->build();

        $this->em->persist($location);
        $this->em->persist($session);
        $this->em->persist($rawSnapshot);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        self::assertNotSame($first->getId(), $second->getId());
    }
}
