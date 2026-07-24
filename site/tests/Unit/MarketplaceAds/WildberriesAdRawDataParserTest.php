<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdRawDataParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WildberriesAdRawDataParserTest extends TestCase
{
    private WildberriesAdRawDataParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WildberriesAdRawDataParser();
    }

    public function testSupportsOnlyWildberries(): void
    {
        self::assertTrue($this->parser->supports(MarketplaceType::WILDBERRIES->value));
        self::assertFalse($this->parser->supports(MarketplaceType::OZON->value));
    }

    public function testAllocatesAggregatedActualExpenseByNestedSkuWeights(): void
    {
        $entries = $this->parser->parse($this->payload(
            expenses: [
                ['advertId' => '123', 'updSum' => '100.01', 'campName' => 'Campaign'],
                ['advertId' => '123', 'updSum' => '49.99', 'campName' => 'Campaign'],
            ],
            statistics: [[
                'advertId' => '123',
                'sum' => '9999.99',
                'days' => [[
                    'date' => '2026-07-20',
                    'sum' => '8888.88',
                    'apps' => [
                        [
                            'appType' => '1',
                            'sum' => '7777.77',
                            'nms' => [
                                ['nmId' => '456', 'sum' => '10.00', 'views' => '100', 'clicks' => '5'],
                                ['nmId' => '789', 'sum' => '20.00', 'views' => '200', 'clicks' => '10'],
                            ],
                        ],
                        [
                            'appType' => '32',
                            'sum' => '6666.66',
                            'nms' => [
                                ['nmId' => '456', 'sum' => '20.00', 'views' => '50', 'clicks' => '3'],
                            ],
                        ],
                    ],
                ]],
            ]],
        ));

        self::assertCount(2, $entries);
        self::assertEntry($entries[0], '123', 'Campaign', '456', '90.00', 150, 8);
        self::assertEntry($entries[1], '123', 'Campaign', '789', '60.00', 200, 10);
        self::assertSame(
            '150.00',
            bcadd($entries[0]->cost, $entries[1]->cost, 2),
            'Only nm-level sums are weights; campaign/day/app totals must not be double-counted.',
        );
    }

    public function testAddsRoundingResidueToSkuWithLargestWeight(): void
    {
        $entries = $this->parser->parse($this->payload(
            expenses: [['advertId' => '1', 'updSum' => '10.01', 'campName' => 'A']],
            statistics: [[
                'advertId' => '1',
                'days' => [[
                    'apps' => [[
                        'nms' => [
                            ['nmId' => '10', 'sum' => '1.00', 'views' => '1', 'clicks' => '0'],
                            ['nmId' => '20', 'sum' => '2.00', 'views' => '1', 'clicks' => '0'],
                        ],
                    ]],
                ]],
            ]],
        ));

        self::assertSame('3.33', $entries[0]->cost);
        self::assertSame('6.68', $entries[1]->cost);
        self::assertSame('10.01', bcadd($entries[0]->cost, $entries[1]->cost, 2));
    }

    public function testEqualWeightResidueUsesLowestNaturalNmIdDeterministically(): void
    {
        $entries = $this->parser->parse($this->payload(
            expenses: [['advertId' => '1', 'updSum' => '0.01', 'campName' => 'A']],
            statistics: [[
                'advertId' => '1',
                'days' => [[
                    'apps' => [[
                        'nms' => [
                            ['nmId' => '20', 'sum' => '1', 'views' => '0', 'clicks' => '0'],
                            ['nmId' => '3', 'sum' => '1', 'views' => '0', 'clicks' => '0'],
                        ],
                    ]],
                ]],
            ]],
        ));

        self::assertSame('3', $entries[0]->parentSku);
        self::assertSame('0.01', $entries[0]->cost);
        self::assertSame('20', $entries[1]->parentSku);
        self::assertSame('0.00', $entries[1]->cost);
    }

    public function testAllocatedSkuAmountsAlwaysEqualActualCampaignExpense(): void
    {
        for ($scenario = 1; $scenario <= 50; ++$scenario) {
            $actualMinor = $scenario * 37 + 1;
            $nms = [];
            for ($sku = 1; $sku <= 7; ++$sku) {
                $nms[] = [
                    'nmId' => (string) (1000 + $sku),
                    'sum' => (string) (($scenario + $sku) % 11 + 1),
                    'views' => (string) $sku,
                    'clicks' => '0',
                ];
            }

            $entries = $this->parser->parse($this->payload(
                expenses: [[
                    'advertId' => (string) $scenario,
                    'updSum' => sprintf('%d.%02d', intdiv($actualMinor, 100), $actualMinor % 100),
                    'campName' => 'Invariant',
                ]],
                statistics: [[
                    'advertId' => (string) $scenario,
                    'days' => [['apps' => [['nms' => $nms]]]],
                ]],
            ));

            $sum = '0.00';
            foreach ($entries as $entry) {
                $sum = bcadd($sum, $entry->cost, 2);
            }

            self::assertSame(
                sprintf('%d.%02d', intdiv($actualMinor, 100), $actualMinor % 100),
                $sum,
                'Allocation invariant failed for scenario '.$scenario,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $statistics
     */
    #[DataProvider('missingWeightsProvider')]
    public function testPreservesActualAsUnallocatedWhenPositiveWeightsAreUnavailable(array $statistics): void
    {
        $entries = $this->parser->parse($this->payload(
            expenses: [['advertId' => '55', 'updSum' => '42.37', 'campName' => 'No weights']],
            statistics: $statistics,
        ));

        self::assertCount(1, $entries);
        self::assertEntry(
            $entries[0],
            '55',
            'No weights',
            AdRawEntry::UNALLOCATED_PARENT_SKU,
            '42.37',
            0,
            0,
        );
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function missingWeightsProvider(): iterable
    {
        yield 'no campaign stats' => [[]];
        yield 'empty days' => [[['advertId' => '55', 'days' => []]]];
        yield 'zero nm sum' => [[[
            'advertId' => '55',
            'days' => [[
                'apps' => [[
                    'nms' => [['nmId' => '99', 'sum' => '0.00', 'views' => '10', 'clicks' => '1']],
                ]],
            ]],
        ]]];
    }

    public function testIgnoresStatisticsForCampaignWithoutActualExpense(): void
    {
        $entries = $this->parser->parse($this->payload(
            expenses: [['advertId' => '1', 'updSum' => '1.00', 'campName' => 'Actual']],
            statistics: [[
                'advertId' => '999',
                'days' => [[
                    'apps' => [[
                        'nms' => [['nmId' => '123', 'sum' => '100', 'views' => '1', 'clicks' => '1']],
                    ]],
                ]],
            ]],
        ));

        self::assertCount(1, $entries);
        self::assertSame(AdRawEntry::UNALLOCATED_PARENT_SKU, $entries[0]->parentSku);
        self::assertSame('1.00', $entries[0]->cost);
    }

    public function testAggregatesBeforeMoneyRoundingAndSupportsSignedCorrections(): void
    {
        $entries = $this->parser->parse($this->payload(
            expenses: [
                ['advertId' => '1', 'updSum' => '-1.005', 'campName' => 'Correction'],
                ['advertId' => '1', 'updSum' => '-1.005', 'campName' => 'Correction'],
            ],
            statistics: [[
                'advertId' => '1',
                'days' => [[
                    'apps' => [[
                        'nms' => [['nmId' => '10', 'sum' => '1', 'views' => '2', 'clicks' => '1']],
                    ]],
                ]],
            ]],
        ));

        self::assertSame('-2.01', $entries[0]->cost);
    }

    public function testUsesDeterministicCampaignNameFallback(): void
    {
        $entries = $this->parser->parse($this->payload(
            expenses: [['advertId' => '77', 'updSum' => '1.00', 'campName' => '']],
            statistics: [],
        ));

        self::assertSame('WB campaign 77', $entries[0]->campaignName);
    }

    public function testEmptyExpenseListProducesNoEntries(): void
    {
        self::assertSame([], $this->parser->parse($this->payload([], [])));
    }

    public function testZeroActualExpenseDoesNotCreateAProjectionEntry(): void
    {
        self::assertSame([], $this->parser->parse($this->payload(
            expenses: [['advertId' => '1', 'updSum' => '0.00', 'campName' => 'Zero']],
            statistics: [[
                'advertId' => '1',
                'days' => [['apps' => [['nms' => [[
                    'nmId' => '999',
                    'sum' => '1.00',
                    'views' => '10',
                    'clicks' => '1',
                ]]]]]],
            ]],
        )));
    }

    public function testRejectsUnsupportedSchemaAndFloatMoney(): void
    {
        $invalidPayloads = [
            '{"schema":"other","expenses":[],"statistics":[]}',
            '{"schema":"wb-ad-daily-spend-v1","expenses":[{"advertId":"1","updSum":10.5}],"statistics":[]}',
            '{"schema":"wb-ad-daily-spend-v1","expenses":[{"advertId":"1","updSum":"1.1234567890123"}],"statistics":[]}',
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $this->parser->parse($payload);
                self::fail('Expected invalid WB payload to be rejected.');
            } catch (\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsNegativeAnalyticsWeightAndInvalidCounters(): void
    {
        foreach ([
            ['sum' => '-0.01', 'views' => '1', 'clicks' => '0'],
            ['sum' => '1.00', 'views' => '-1', 'clicks' => '0'],
        ] as $nm) {
            $payload = $this->payload(
                expenses: [['advertId' => '1', 'updSum' => '1.00', 'campName' => 'A']],
                statistics: [[
                    'advertId' => '1',
                    'days' => [['apps' => [['nms' => [['nmId' => '2'] + $nm]]]]],
                ]],
            );

            try {
                $this->parser->parse($payload);
                self::fail('Expected invalid analytics value to be rejected.');
            } catch (\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $expenses
     * @param list<array<string, mixed>> $statistics
     */
    private function payload(array $expenses, array $statistics): string
    {
        return json_encode([
            'schema' => 'wb-ad-daily-spend-v1',
            'expenses' => $expenses,
            'statistics' => $statistics,
        ], \JSON_THROW_ON_ERROR);
    }

    private static function assertEntry(
        AdRawEntry $entry,
        string $campaignId,
        string $campaignName,
        string $parentSku,
        string $cost,
        int $impressions,
        int $clicks,
    ): void {
        self::assertSame($campaignId, $entry->campaignId);
        self::assertSame($campaignName, $entry->campaignName);
        self::assertSame($parentSku, $entry->parentSku);
        self::assertSame($cost, $entry->cost);
        self::assertSame($impressions, $entry->impressions);
        self::assertSame($clicks, $entry->clicks);
    }
}
