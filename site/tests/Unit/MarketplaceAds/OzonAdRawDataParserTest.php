<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdRawDataParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class OzonAdRawDataParserTest extends TestCase
{
    private OzonAdRawDataParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OzonAdRawDataParser();
    }

    private function createTestLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    public function testSupportsOzonMarketplace(): void
    {
        self::assertTrue($this->parser->supports(MarketplaceType::OZON->value));
        self::assertFalse($this->parser->supports(MarketplaceType::WILDBERRIES->value));
        self::assertFalse($this->parser->supports('unknown'));
    }

    public function testParsesSingleRow(): void
    {
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '123', 'campaign_name' => 'Кампания 1', 'sku' => '456',
                 'spend' => 150.50, 'views' => 1000, 'clicks' => 50],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertInstanceOf(AdRawEntry::class, $result[0]);
        self::assertSame('123', $result[0]->campaignId);
        self::assertSame('Кампания 1', $result[0]->campaignName);
        self::assertSame('456', $result[0]->parentSku);
        self::assertSame('150.50', $result[0]->cost);
        self::assertSame(1000, $result[0]->impressions);
        self::assertSame(50, $result[0]->clicks);
    }

    public function testAggregatesDuplicateCampaignSkuPairs(): void
    {
        // Один SKU (456) в одной кампании (123) встречается дважды — разные объявления/группы.
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '123', 'campaign_name' => 'К1', 'sku' => '456',
                 'spend' => 100.25, 'views' => 500, 'clicks' => 20],
                ['campaign_id' => '123', 'campaign_name' => 'К1', 'sku' => '456',
                 'spend' => 50.75, 'views' => 300, 'clicks' => 15],
                ['campaign_id' => '123', 'campaign_name' => 'К1', 'sku' => '999',
                 'spend' => 25.00, 'views' => 100, 'clicks' => 5],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(2, $result);

        // Первая запись — агрегированная (123 + 456)
        self::assertSame('123', $result[0]->campaignId);
        self::assertSame('456', $result[0]->parentSku);
        self::assertSame('151.00', $result[0]->cost);
        self::assertSame(800, $result[0]->impressions);
        self::assertSame(35, $result[0]->clicks);

        // Вторая запись — отдельный SKU (123 + 999)
        self::assertSame('123', $result[1]->campaignId);
        self::assertSame('999', $result[1]->parentSku);
        self::assertSame('25.00', $result[1]->cost);
    }

    public function testSeparatesDifferentCampaigns(): void
    {
        // Одинаковый SKU в разных кампаниях — НЕ агрегировать.
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '111', 'campaign_name' => 'A', 'sku' => '456',
                 'spend' => 10.00, 'views' => 100, 'clicks' => 1],
                ['campaign_id' => '222', 'campaign_name' => 'B', 'sku' => '456',
                 'spend' => 20.00, 'views' => 200, 'clicks' => 2],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(2, $result);
        self::assertSame('111', $result[0]->campaignId);
        self::assertSame('222', $result[1]->campaignId);
    }

    public function testEmptyRowsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->parser->parse('{"rows": []}'));
        self::assertSame([], $this->parser->parse('{}'));
    }

    public function testCostIsFormattedAsTwoDecimalString(): void
    {
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 1.005, 'views' => 1, 'clicks' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertIsString($result[0]->cost);
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $result[0]->cost);
    }

    /**
     * Регрессия на кумулятивную ошибку округления: округление ДО агрегации
     * дало бы 0.01 + 0.01 + 0.01 = 0.03. Правильный ответ — 0.005 × 3 = 0.015 → 0.02 (HALF-UP).
     */
    public function testAggregationPreservesPrecision(): void
    {
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 0.005, 'views' => 1, 'clicks' => 0],
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 0.005, 'views' => 1, 'clicks' => 0],
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 0.005, 'views' => 1, 'clicks' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertSame('0.02', $result[0]->cost);
    }

    public function testSkipsRowsWithMissingRequiredFields(): void
    {
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 10.00, 'views' => 100, 'clicks' => 5],
                ['campaign_name' => 'no id', 'sku' => 'Y',
                 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
                ['campaign_id' => '2', 'campaign_name' => 'no sku',
                 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertSame('1', $result[0]->campaignId);
    }

    public function testThrowsOnMalformedJson(): void
    {
        $this->expectException(\JsonException::class);
        $this->parser->parse('not a json');
    }

    public function testLogsWarningForEachSkippedRowAndSummary(): void
    {
        $logger = $this->createTestLogger();
        $parser = new OzonAdRawDataParser($logger);

        $json = json_encode([
            'rows' => [
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 10.00, 'views' => 100, 'clicks' => 5],
                ['campaign_name' => 'no id', 'sku' => 'Y',
                 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
                ['campaign_id' => '2', 'campaign_name' => 'no sku',
                 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
                'not-an-array',
            ],
        ], JSON_THROW_ON_ERROR);

        $parser->parse($json);

        $warnings = array_filter($logger->records, static fn(array $r) => $r['level'] === 'warning');
        self::assertCount(2, $warnings, 'Each skipped row with missing fields must emit a warning');

        $summaries = array_filter($logger->records, static fn(array $r) => $r['level'] === 'info');
        self::assertCount(1, $summaries, 'Exactly one summary info-log must be emitted when rows were skipped');

        $summary = array_values($summaries)[0];
        self::assertSame(4, $summary['context']['total_rows']);
        self::assertSame(1, $summary['context']['skipped_non_array']);
        self::assertSame(2, $summary['context']['skipped_missing_fields']);
        self::assertSame(1, $summary['context']['aggregated_entries']);
    }

    public function testDoesNotLogWhenAllRowsAreValid(): void
    {
        $logger = $this->createTestLogger();
        $parser = new OzonAdRawDataParser($logger);

        $json = json_encode([
            'rows' => [
                ['campaign_id' => '1', 'campaign_name' => 'A', 'sku' => 'X',
                 'spend' => 10.00, 'views' => 100, 'clicks' => 5],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser->parse($json);

        self::assertSame([], $logger->records);
    }

    public function testParsesNestedCampaignsFormat(): void
    {
        $json = json_encode([
            'campaigns' => [
                [
                    'campaign_id' => '24449058',
                    'campaign_name' => '07.04 Ловушка для мух желтая',
                    'rows' => [
                        ['sku' => '3765141162', 'spend' => '17.87', 'views' => 219, 'clicks' => 1],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertInstanceOf(AdRawEntry::class, $result[0]);
        self::assertSame('24449058', $result[0]->campaignId);
        self::assertSame('07.04 Ловушка для мух желтая', $result[0]->campaignName);
        self::assertSame('3765141162', $result[0]->parentSku);
        self::assertSame('17.87', $result[0]->cost);
        self::assertSame(219, $result[0]->impressions);
        self::assertSame(1, $result[0]->clicks);
    }

    /**
     * Регрессионный guard: одни и те же данные в обоих форматах должны
     * давать идентичные AdRawEntry — это инвариант, на который опирается
     * ProcessAdRawDocumentAction при обработке legacy + новых raw-документов.
     */
    public function testFlatAndNestedProduceIdenticalEntries(): void
    {
        $flatJson = json_encode([
            'rows' => [
                ['campaign_id' => '111', 'campaign_name' => 'C1', 'sku' => 'A',
                 'spend' => 12.34, 'views' => 100, 'clicks' => 3],
                ['campaign_id' => '111', 'campaign_name' => 'C1', 'sku' => 'B',
                 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
            ],
        ], JSON_THROW_ON_ERROR);

        $nestedJson = json_encode([
            'campaigns' => [
                [
                    'campaign_id' => '111',
                    'campaign_name' => 'C1',
                    'rows' => [
                        ['sku' => 'A', 'spend' => 12.34, 'views' => 100, 'clicks' => 3],
                        ['sku' => 'B', 'spend' => 5.00, 'views' => 50, 'clicks' => 1],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        self::assertEquals($this->parser->parse($flatJson), $this->parser->parse($nestedJson));
    }

    public function testNestedMultipleCampaignsMultipleRows(): void
    {
        $json = json_encode([
            'campaigns' => [
                [
                    'campaign_id' => '111',
                    'campaign_name' => 'Alpha',
                    'rows' => [
                        ['sku' => 'A', 'spend' => 1.00, 'views' => 10, 'clicks' => 1],
                        ['sku' => 'B', 'spend' => 2.00, 'views' => 20, 'clicks' => 2],
                    ],
                ],
                [
                    'campaign_id' => '222',
                    'campaign_name' => 'Beta',
                    'rows' => [
                        ['sku' => 'A', 'spend' => 3.00, 'views' => 30, 'clicks' => 3],
                        ['sku' => 'C', 'spend' => 4.00, 'views' => 40, 'clicks' => 4],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(4, $result);

        $byKey = [];
        foreach ($result as $entry) {
            $byKey[$entry->campaignId.'|'.$entry->parentSku] = $entry;
        }

        self::assertArrayHasKey('111|A', $byKey);
        self::assertSame('Alpha', $byKey['111|A']->campaignName);
        self::assertSame('1.00', $byKey['111|A']->cost);

        self::assertArrayHasKey('111|B', $byKey);
        self::assertSame('222', $byKey['222|A']->campaignId);
        self::assertSame('Beta', $byKey['222|A']->campaignName);
        self::assertSame('3.00', $byKey['222|A']->cost);

        self::assertArrayHasKey('222|C', $byKey);
    }

    public function testNestedEmptyCampaignsReturnsEmpty(): void
    {
        self::assertSame([], $this->parser->parse('{"campaigns": []}'));
    }

    public function testNestedCampaignWithoutRowsIsSkipped(): void
    {
        $json = json_encode([
            'campaigns' => [
                ['campaign_id' => '111', 'campaign_name' => 'NoRows'],
                [
                    'campaign_id' => '222',
                    'campaign_name' => 'WithRows',
                    'rows' => [
                        ['sku' => 'X', 'spend' => 9.99, 'views' => 10, 'clicks' => 1],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertSame('222', $result[0]->campaignId);
        self::assertSame('X', $result[0]->parentSku);
    }

    /**
     * Defensive для будущих вариаций payload: если nested row уже несёт
     * свой campaign_id — НЕ перезатираем его родительским.
     */
    public function testNestedRowOwnCampaignIdNotOverwritten(): void
    {
        $json = json_encode([
            'campaigns' => [
                [
                    'campaign_id' => 'PARENT',
                    'campaign_name' => 'ParentName',
                    'rows' => [
                        ['campaign_id' => 'ROW', 'campaign_name' => 'RowName',
                         'sku' => 'X', 'spend' => 1.00, 'views' => 1, 'clicks' => 0],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertSame('ROW', $result[0]->campaignId);
        self::assertSame('RowName', $result[0]->campaignName);
    }
}
