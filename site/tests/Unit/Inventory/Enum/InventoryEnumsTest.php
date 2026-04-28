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
        $this->assertCount(
            4,
            array_map(static fn (LocationType $case): string => $case->value, LocationType::cases()),
        );
    }

    public function testStockStatusHasExpectedValuesCount(): void
    {
        $this->assertCount(
            6,
            array_map(static fn (StockStatus $case): string => $case->value, StockStatus::cases()),
        );
    }

    public function testSnapshotSessionStatusHasExpectedValuesCount(): void
    {
        $this->assertCount(
            5,
            array_map(static fn (SnapshotSessionStatus $case): string => $case->value, SnapshotSessionStatus::cases()),
        );
    }

    public function testSnapshotTriggerTypeHasExpectedValuesCount(): void
    {
        $this->assertCount(
            4,
            array_map(static fn (SnapshotTriggerType $case): string => $case->value, SnapshotTriggerType::cases()),
        );
    }
}
