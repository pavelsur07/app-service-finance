<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Normalizer;

use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Normalizer\WbWarehouseStocksRawNormalizer;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WbWarehouseStocksRawNormalizerTest extends TestCase
{
    public function testAggregatesSizesAndPagesByArticleAndWarehouse(): void
    {
        $first = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withPageNumber(1)
            ->withResponseBody(['data' => ['items' => [
                ['nmId' => 100, 'chrtId' => 1, 'warehouseId' => 507, 'warehouseName' => 'Коледино', 'regionName' => 'Центральный', 'quantity' => 4, 'inWayToClient' => 2, 'inWayFromClient' => 1],
                ['nmId' => 100, 'chrtId' => 2, 'warehouseId' => 507, 'warehouseName' => 'Коледино', 'regionName' => 'Центральный', 'quantity' => 6, 'inWayToClient' => 3, 'inWayFromClient' => 0],
            ]]])
            ->build();
        $second = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withPageNumber(2)
            ->withResponseBody(['data' => ['items' => [
                ['nmId' => 100, 'chrtId' => 3, 'warehouseId' => 507, 'warehouseName' => 'Коледино', 'regionName' => 'Центральный', 'quantity' => 5, 'inWayToClient' => 0, 'inWayFromClient' => 2],
                ['nmId' => 100, 'chrtId' => 3, 'warehouseId' => 117, 'warehouseName' => 'Электросталь', 'quantity' => 7],
            ]]])
            ->build();

        $rows = $this->normalizer()->normalize([$first, $second]);

        self::assertCount(6, $rows);
        $byWarehouseAndStatus = [];
        foreach ($rows as $row) {
            $byWarehouseAndStatus[$row->locationExternalId][$row->status->value] = $row;
        }

        $available = $byWarehouseAndStatus['507'][StockStatus::Available->value];
        self::assertSame('100', $available->sourceSku);
        self::assertSame('15.000', $available->quantity);
        self::assertSame('fbw', $available->fulfillmentType);
        self::assertSame('WB-507', $available->locationCode);
        self::assertSame('Коледино', $available->locationName);
        self::assertSame(['regionName' => 'Центральный'], $available->locationMetadata);
        self::assertSame('5.000', $byWarehouseAndStatus['507'][StockStatus::InTransitToCustomer->value]->quantity);
        self::assertSame('3.000', $byWarehouseAndStatus['507'][StockStatus::InTransitFromCustomer->value]->quantity);
        self::assertSame('0.000', $byWarehouseAndStatus['117'][StockStatus::InTransitToCustomer->value]->quantity);
    }

    public function testSkipsRowsWithoutRequiredIdentifiers(): void
    {
        $raw = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withResponseBody(['data' => ['items' => [
                ['nmId' => 100, 'quantity' => 3],
                ['warehouseId' => 507, 'quantity' => 4],
            ]]])
            ->build();

        self::assertSame([], $this->normalizer()->normalize([$raw]));
    }

    private function normalizer(): WbWarehouseStocksRawNormalizer
    {
        return new WbWarehouseStocksRawNormalizer(new AppLogger(new NullLogger()));
    }
}
