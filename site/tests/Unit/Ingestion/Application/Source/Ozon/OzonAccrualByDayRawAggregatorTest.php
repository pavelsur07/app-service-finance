<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayRawAggregator;
use App\Ingestion\Application\Source\Ozon\OzonMoneyParser;
use PHPUnit\Framework\TestCase;

final class OzonAccrualByDayRawAggregatorTest extends TestCase
{
    public function testAggregatesByDayAccrualRowsByCategoryAndNestedTypeIds(): void
    {
        $aggregate = $this->aggregator()->aggregate([
            [
                'date' => '2026-06-13',
                'total_amount' => ['amount' => '-7.86', 'currency' => 'RUB'],
                'accrued_category' => 'POSTING',
                'posting' => [
                    'products' => [[
                        'delivery' => [
                            'services' => [
                                ['type_id' => 29, 'accrued' => ['amount' => '-7.86', 'currency' => 'RUB']],
                                ['type_id' => 45, 'accrued' => ['amount' => '-15', 'currency' => 'RUB']],
                            ],
                        ],
                        'commission' => [
                            'sale_commission' => ['amount' => '-120.05', 'currency' => 'RUB'],
                            'bonus' => ['amount' => '12.79', 'currency' => 'RUB'],
                        ],
                    ]],
                ],
            ],
            [
                'date' => '2026-06-13',
                'total_amount' => ['amount' => '18.66', 'currency' => 'RUB'],
                'accrued_category' => 'ITEM',
                'item_fees' => [
                    'fees' => [[
                        'sku' => 1402860328,
                        'fees' => [
                            ['type_id' => 1, 'accrued' => ['amount' => '18.66', 'currency' => 'RUB']],
                        ],
                    ]],
                ],
            ],
            [
                'date' => '2026-06-14',
                'total_amount' => ['amount' => '-78.28', 'currency' => 'RUB'],
                'accrued_category' => 'NON_ITEM',
                'non_item_fee' => ['type_id' => 46, 'accrued' => ['amount' => '-78.28', 'currency' => 'RUB']],
            ],
            [
                'date' => '2026-06-14',
                'total_amount' => ['amount' => '-3.50', 'currency' => 'RUB'],
                'accrued_category' => 'CONTAINER',
                'container_fees' => [
                    'fees' => [
                        ['type_id' => 77, 'accrued' => ['amount' => '-3.50', 'currency' => 'RUB']],
                    ],
                ],
            ],
        ]);

        self::assertSame(4, $aggregate->scannedRows);
        self::assertSame([
            ['date' => '2026-06-13', 'category' => 'ITEM', 'count' => 1, 'totalMinor' => 1866],
            ['date' => '2026-06-13', 'category' => 'POSTING', 'count' => 1, 'totalMinor' => -786],
            ['date' => '2026-06-14', 'category' => 'CONTAINER', 'count' => 1, 'totalMinor' => -350],
            ['date' => '2026-06-14', 'category' => 'NON_ITEM', 'count' => 1, 'totalMinor' => -7828],
        ], $aggregate->dateCategoryRows);
        self::assertSame([
            ['date' => '2026-06-13', 'typeId' => '29', 'count' => 1, 'totalMinor' => -786],
            ['date' => '2026-06-13', 'typeId' => '45', 'count' => 1, 'totalMinor' => -1500],
        ], $aggregate->deliveryServiceRows);
        self::assertSame([
            ['date' => '2026-06-13', 'field' => 'bonus', 'count' => 1, 'totalMinor' => 1279],
            ['date' => '2026-06-13', 'field' => 'sale_commission', 'count' => 1, 'totalMinor' => -12005],
        ], $aggregate->commissionRows);
        self::assertSame([
            ['date' => '2026-06-13', 'typeId' => '1', 'count' => 1, 'totalMinor' => 1866],
        ], $aggregate->itemFeeRows);
        self::assertSame([
            ['date' => '2026-06-14', 'typeId' => '46', 'count' => 1, 'totalMinor' => -7828],
        ], $aggregate->nonItemFeeRows);
        self::assertSame([
            ['date' => '2026-06-14', 'typeId' => '77', 'count' => 1, 'totalMinor' => -350],
        ], $aggregate->containerFeeRows);
    }

    public function testIgnoresMissingNestedBlocks(): void
    {
        $aggregate = $this->aggregator()->aggregate([
            [
                'date' => '2026-06-13',
                'total_amount' => ['amount' => '0', 'currency' => 'RUB'],
                'accrued_category' => 'POSTING',
                'posting' => null,
                'item_fees' => null,
                'non_item_fee' => null,
                'container_fees' => null,
            ],
        ]);

        self::assertSame(1, $aggregate->scannedRows);
        self::assertSame([
            ['date' => '2026-06-13', 'category' => 'POSTING', 'count' => 1, 'totalMinor' => 0],
        ], $aggregate->dateCategoryRows);
        self::assertSame([], $aggregate->deliveryServiceRows);
        self::assertSame([], $aggregate->commissionRows);
        self::assertSame([], $aggregate->itemFeeRows);
        self::assertSame([], $aggregate->nonItemFeeRows);
        self::assertSame([], $aggregate->containerFeeRows);
    }

    private function aggregator(): OzonAccrualByDayRawAggregator
    {
        return new OzonAccrualByDayRawAggregator(new OzonMoneyParser());
    }
}
