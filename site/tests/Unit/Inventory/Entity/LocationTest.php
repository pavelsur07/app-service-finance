<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Entity;

use App\Inventory\Entity\Location;
use App\Inventory\Enum\LocationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\LocationBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class LocationTest extends TestCase
{
    public function testLocationCreationSetsExpectedFields(): void
    {
        $location = new Location(
            companyId: LocationBuilder::DEFAULT_COMPANY_ID,
            type: LocationType::MpWarehouse,
            externalSystem: MarketplaceType::OZON,
            code: 'MSK-01',
            name: 'Москва склад',
            externalId: 'oz-warehouse-001',
            metadata: ['city' => 'Moscow'],
        );

        self::assertTrue(Uuid::isValid($location->getId()));
        self::assertSame(LocationBuilder::DEFAULT_COMPANY_ID, $location->getCompanyId());
        self::assertSame(LocationType::MpWarehouse, $location->getType());
        self::assertSame(MarketplaceType::OZON, $location->getExternalSystem());
        self::assertSame('oz-warehouse-001', $location->getExternalId());
        self::assertSame('MSK-01', $location->getCode());
        self::assertSame('Москва склад', $location->getName());
        self::assertTrue($location->isActive());
        self::assertSame(['city' => 'Moscow'], $location->getMetadata());
        self::assertInstanceOf(\DateTimeImmutable::class, $location->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $location->getUpdatedAt());
    }

    public function testSetNameUpdatesNameAndUpdatedAt(): void
    {
        $location = LocationBuilder::aLocation()->build();
        $before = $location->getUpdatedAt();

        $location->setName('Новый склад');

        self::assertSame('Новый склад', $location->getName());
        self::assertGreaterThanOrEqual($before, $location->getUpdatedAt());
    }

    public function testSetIsActiveUpdatesFlagAndUpdatedAt(): void
    {
        $location = LocationBuilder::aLocation()->build();
        $before = $location->getUpdatedAt();

        $location->setIsActive(false);

        self::assertFalse($location->isActive());
        self::assertGreaterThanOrEqual($before, $location->getUpdatedAt());
    }

    public function testSetMetadataUpdatesMetadataAndUpdatedAt(): void
    {
        $location = LocationBuilder::aLocation()->build();
        $before = $location->getUpdatedAt();

        $location->setMetadata(['zone' => 'A1']);

        self::assertSame(['zone' => 'A1'], $location->getMetadata());
        self::assertGreaterThanOrEqual($before, $location->getUpdatedAt());
    }

    public function testSetCodeUpdatesCodeAndUpdatedAt(): void
    {
        $location = LocationBuilder::aLocation()->build();
        $before = $location->getUpdatedAt();

        $location->setCode('NEW-CODE');

        self::assertSame('NEW-CODE', $location->getCode());
        self::assertGreaterThanOrEqual($before, $location->getUpdatedAt());
    }

    public function testSetExternalIdUpdatesExternalIdAndUpdatedAt(): void
    {
        $location = LocationBuilder::aLocation()->build();
        $before = $location->getUpdatedAt();

        $location->setExternalId('new-external-id');

        self::assertSame('new-external-id', $location->getExternalId());
        self::assertGreaterThanOrEqual($before, $location->getUpdatedAt());
    }

    public function testLocationHasNoPublicMutableSettersForImmutableFields(): void
    {
        self::assertFalse(method_exists(Location::class, 'setCompanyId'));
        self::assertFalse(method_exists(Location::class, 'setType'));
        self::assertFalse(method_exists(Location::class, 'setExternalSystem'));
    }
}
