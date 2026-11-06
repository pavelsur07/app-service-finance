<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Normalizer;

use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Api\Wildberries\WbInventoryClient;
use App\Inventory\Infrastructure\Normalizer\WbSellerWarehouseStocksRawNormalizer;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class WbSellerWarehouseStocksRawNormalizerTest extends TestCase
{
    public function testBuildsFbsRowsFromSellerStockPagesAndSkipsOtherEndpoints(): void
    {
        $sellerPage = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withEndpoint(WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/7')
            ->withRequestParams(['warehouseId' => 7, 'warehouseName' => 'Мой склад', 'chunk' => 1])
            ->withResponseBody(['stocks' => [
                ['sku' => 'b1', 'amount' => 3],
                ['sku' => 'b2', 'amount' => 0],
                ['amount' => 9],
            ]])
            ->build();
        $fbwPage = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withEndpoint(WbInventoryClient::ENDPOINT)
            ->withResponseBody(['data' => ['items' => [
                ['nmId' => 100, 'chrtId' => 1, 'warehouseId' => 507, 'quantity' => 4],
            ]]])
            ->build();

        $rows = (new WbSellerWarehouseStocksRawNormalizer())->normalize([$fbwPage, $sellerPage]);

        self::assertCount(2, $rows);
        $first = $rows[0];
        self::assertSame(MarketplaceType::WILDBERRIES, $first->source);
        self::assertSame('b1', $first->sourceSku);
        self::assertNull($first->sourceOfferId);
        self::assertSame('fbs', $first->fulfillmentType);
        self::assertSame(StockStatus::Available, $first->status);
        self::assertSame('3.000', $first->quantity);
        self::assertSame('0.000', $first->reservedQuantity);
        self::assertSame($sellerPage->getId(), $first->rawSnapshotId);
        self::assertSame('seller:7', $first->locationExternalId);
        self::assertSame('WB-FBS-7', $first->locationCode);
        self::assertSame('Мой склад', $first->locationName);
        self::assertSame('0.000', $rows[1]->quantity);
        self::assertSame('b2', $rows[1]->sourceSku);
    }

    public function testPageWithoutWarehouseIdOrNegativeAmountIsHandled(): void
    {
        $withoutWarehouse = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withEndpoint(WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/7')
            ->withRequestParams(['chunk' => 1])
            ->withResponseBody(['stocks' => [['sku' => 'b1', 'amount' => 3]]])
            ->build();
        $negative = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withEndpoint(WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/8')
            ->withRequestParams(['warehouseId' => 8, 'warehouseName' => ''])
            ->withResponseBody(['stocks' => [['sku' => 'b9', 'amount' => -2]]])
            ->build();

        $rows = (new WbSellerWarehouseStocksRawNormalizer())->normalize([$withoutWarehouse, $negative]);

        self::assertCount(1, $rows);
        self::assertSame('b9', $rows[0]->sourceSku);
        self::assertSame('0.000', $rows[0]->quantity);
        self::assertSame('WB seller warehouse 8', $rows[0]->locationName);
    }
}
