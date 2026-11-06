<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Enum;

use App\Inventory\Enum\FulfillmentScheme;
use App\Inventory\Enum\LocationType;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use PHPUnit\Framework\TestCase;

final class InventoryEnumsTest extends TestCase
{
    public function testLocationTypeHasExpectedValuesCount(): void
    {
        $this->assertSame(
            [
                'mp_warehouse',
                'mp_acceptance',
                'mp_in_transit_to_customer',
                'mp_in_transit_from_customer',
                'seller_warehouse',
            ],
            self::backingValues(LocationType::class),
        );
    }

    public function testStockStatusHasExpectedValuesCount(): void
    {
        $this->assertSame(
            [
                'available',
                'in_transit_to_customer',
                'in_transit_from_customer',
                'on_acceptance',
                'defect',
                'blocked',
            ],
            array_map(static fn (StockStatus $case): string => $case->value, StockStatus::cases()),
        );
    }

    public function testSnapshotSessionStatusHasExpectedValuesCount(): void
    {
        $this->assertSame(
            [
                'pending',
                'in_progress',
                'completed',
                'partial',
                'failed',
            ],
            array_map(static fn (SnapshotSessionStatus $case): string => $case->value, SnapshotSessionStatus::cases()),
        );
    }

    public function testSnapshotTriggerTypeHasExpectedValuesCount(): void
    {
        $this->assertSame(
            [
                'scheduled_night',
                'scheduled_day',
                'manual',
                'retry',
            ],
            array_map(static fn (SnapshotTriggerType $case): string => $case->value, SnapshotTriggerType::cases()),
        );
    }

    public function testStockSnapshotMappingStatusHasExpectedValuesCount(): void
    {
        $this->assertSame(
            [
                'mapped',
                'unmapped',
                'ambiguous',
            ],
            array_map(static fn (StockSnapshotMappingStatus $case): string => $case->value, StockSnapshotMappingStatus::cases()),
        );
    }

    public function testStockSnapshotMappingStatusLabels(): void
    {
        self::assertSame('Сопоставлен', StockSnapshotMappingStatus::Mapped->label());
        self::assertSame('Не сопоставлен', StockSnapshotMappingStatus::Unmapped->label());
        self::assertSame('Неоднозначно', StockSnapshotMappingStatus::Ambiguous->label());
    }

    public function testFulfillmentSchemeFromFulfillmentType(): void
    {
        self::assertSame(FulfillmentScheme::Fbs, FulfillmentScheme::fromFulfillmentType('fbs'));
        self::assertSame(FulfillmentScheme::Fbs, FulfillmentScheme::fromFulfillmentType(' RFBS '));
        self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType('fbo'));
        self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType('fbw'));
        self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType(null));
        self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType(''));
        self::assertSame(['fbo', 'fbs'], self::backingValues(FulfillmentScheme::class));
        self::assertSame('FBO', FulfillmentScheme::Fbo->label());
        self::assertSame('FBS', FulfillmentScheme::Fbs->label());
    }

    /**
     * Через Reflection, а не ::cases(): PHPStan знает значения cases() и считает сравнение
     * с литералом всегда истинным (alreadyNarrowedType).
     *
     * @param class-string<\BackedEnum> $enum
     *
     * @return list<string>
     */
    private static function backingValues(string $enum): array
    {
        return array_values(array_map(
            static fn (\ReflectionEnumBackedCase $case): string => (string) $case->getBackingValue(),
            (new \ReflectionEnum($enum))->getCases(),
        ));
    }
}
