<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Enum;

use App\Inventory\Enum\ExternalSystemType;
use App\Inventory\Enum\LocationType;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Enum\StockStatus;
use PHPUnit\Framework\TestCase;

final class InventoryEnumsTest extends TestCase
{
    public function testExternalSystemTypeHasOnlyExpectedValues(): void
    {
        $this->assertSame(
            ['wildberries', 'ozon'],
            array_map(static fn (ExternalSystemType $case): string => $case->value, ExternalSystemType::cases()),
        );
    }

    public function testLocationTypeHasExpectedValuesCount(): void
    {
        $this->assertSame(
            [
                'mp_warehouse',
                'mp_acceptance',
                'mp_in_transit_to_customer',
                'mp_in_transit_from_customer',
            ],
            array_map(static fn (LocationType $case): string => $case->value, LocationType::cases()),
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
}
