<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Normalizer;

use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Normalizer\OzonProductStocksRawNormalizer;
use App\Marketplace\Enum\MarketplaceType;
use PHPUnit\Framework\TestCase;

final class OzonProductStocksRawNormalizerTest extends TestCase
{
    private OzonProductStocksRawNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new OzonProductStocksRawNormalizer();
    }

    public function testNormalizesSingleItemSingleStockFromTopLevelItems(): void
    {
        $rows = $this->normalizer->normalize([
            'items' => [[
                'offer_id' => 'offer-1',
                'stocks' => [[
                    'sku' => 12345,
                    'type' => 'fbo',
                    'present' => 7,
                    'reserved' => 2,
                ]],
            ]],
        ]);

        self::assertCount(1, $rows);
        self::assertSame(MarketplaceType::OZON, $rows[0]->source);
        self::assertSame('12345', $rows[0]->sourceSku);
        self::assertSame('offer-1', $rows[0]->sourceOfferId);
        self::assertSame('fbo', $rows[0]->fulfillmentType);
        self::assertSame(StockStatus::Available, $rows[0]->status);
        self::assertSame('7.000', $rows[0]->quantity);
        self::assertSame('2.000', $rows[0]->reservedQuantity);
        self::assertSame('', $rows[0]->rawSnapshotId);
    }

    public function testNormalizesMultipleStocksFromResultItems(): void
    {
        $rows = $this->normalizer->normalize([
            'result' => [
                'items' => [[
                    'offer_id' => 'offer-2',
                    'stocks' => [
                        ['sku' => 'sku-fbo', 'type' => 'fbo', 'present' => 10, 'reserved' => 1],
                        ['sku' => 'sku-rfbs', 'type' => 'rfbs', 'present' => 4, 'reserved' => 0],
                    ],
                ]],
            ],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('sku-fbo', $rows[0]->sourceSku);
        self::assertSame('fbo', $rows[0]->fulfillmentType);
        self::assertSame('sku-rfbs', $rows[1]->sourceSku);
        self::assertSame('rfbs', $rows[1]->fulfillmentType);
    }

    public function testSkipsItemWithEmptyStocks(): void
    {
        $rows = $this->normalizer->normalize([
            'items' => [[
                'offer_id' => 'offer-3',
                'stocks' => [],
            ]],
        ]);

        self::assertSame([], $rows);
    }

    public function testSupportsReservedGreaterThanZero(): void
    {
        $rows = $this->normalizer->normalize([
            'items' => [[
                'offer_id' => 'offer-4',
                'stocks' => [[
                    'sku' => 'sku-1',
                    'type' => 'fbo',
                    'present' => 3,
                    'reserved' => 9,
                ]],
            ]],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('3.000', $rows[0]->quantity);
        self::assertSame('9.000', $rows[0]->reservedQuantity);
    }

    public function testMissingOptionalFieldsAreHandled(): void
    {
        $rows = $this->normalizer->normalize([
            'items' => [[
                'stocks' => [[
                    'sku' => 'sku-2',
                    'present' => '11',
                ]],
            ]],
        ]);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->sourceOfferId);
        self::assertNull($rows[0]->fulfillmentType);
        self::assertSame('11.000', $rows[0]->quantity);
        self::assertSame('0.000', $rows[0]->reservedQuantity);
    }


    public function testSkipsEmptySkuValuesAndKeepsValidSku(): void
    {
        $rows = $this->normalizer->normalize([
            'items' => [[
                'offer_id' => 'offer-6',
                'stocks' => [
                    ['sku' => null, 'type' => 'fbo', 'present' => 1, 'reserved' => 0],
                    ['sku' => '', 'type' => 'fbo', 'present' => 2, 'reserved' => 0],
                    ['sku' => '   ', 'type' => 'fbo', 'present' => 3, 'reserved' => 1],
                    ['sku' => '220279573', 'type' => 'fbo', 'present' => 4, 'reserved' => 2],
                ],
            ]],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('220279573', $rows[0]->sourceSku);
        self::assertSame('4.000', $rows[0]->quantity);
        self::assertSame('2.000', $rows[0]->reservedQuantity);
    }

    public function testInvalidPayloadStructureReturnsEmptyList(): void
    {
        self::assertSame([], $this->normalizer->normalize(['result' => ['items' => 'invalid']]));
        self::assertSame([], $this->normalizer->normalize(['items' => 'invalid']));
        self::assertSame([], $this->normalizer->normalize(['foo' => 'bar']));
    }

    public function testCanNormalizeFromInventoryRawSnapshotEntity(): void
    {
        $snapshot = new InventoryRawSnapshot(
            companyId: '11111111-1111-1111-1111-111111111111',
            snapshotSessionId: '22222222-2222-2222-2222-222222222222',
            source: MarketplaceType::OZON,
            sourceEndpoint: '/v4/product/info/stocks',
            requestParams: ['limit' => 100],
            responseStatus: 200,
            responseBody: [
                'result' => [
                    'items' => [[
                        'offer_id' => 'offer-5',
                        'stocks' => [[
                            'sku' => 'sku-5',
                            'type' => 'fbo',
                            'present' => 1,
                            'reserved' => 0,
                        ]],
                    ]],
                ],
            ],
            fetchedAt: new \DateTimeImmutable(),
            fetchDurationMs: 120,
            correlationId: '33333333-3333-3333-3333-333333333333',
            pageNumber: 1,
        );

        $rows = $this->normalizer->normalize($snapshot);

        self::assertCount(1, $rows);
        self::assertSame($snapshot->getId(), $rows[0]->rawSnapshotId);
    }
}
