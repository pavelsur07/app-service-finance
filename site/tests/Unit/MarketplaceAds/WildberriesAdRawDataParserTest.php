<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdRawDataParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class WildberriesAdRawDataParserTest extends TestCase
{
    private WildberriesAdRawDataParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WildberriesAdRawDataParser();
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

    public function testSupportsWildberriesMarketplace(): void
    {
        self::assertTrue($this->parser->supports(MarketplaceType::WILDBERRIES->value));
        self::assertFalse($this->parser->supports(MarketplaceType::OZON->value));
        self::assertFalse($this->parser->supports('unknown'));
    }

    public function testParsesSingleAdvert(): void
    {
        $json = json_encode([
            'adverts' => [
                ['advertId' => 123, 'advertName' => 'Кампания 1', 'nmId' => 456,
                 'sum' => 150.50, 'views' => 1000, 'clicks' => 50],
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

    public function testAggregatesDuplicateAdvertNmIdPairs(): void
    {
        // Один nmId (456) в одной кампании (123) встречается дважды — разные группы объявлений.
        $json = json_encode([
            'adverts' => [
                ['advertId' => 123, 'advertName' => 'К1', 'nmId' => 456,
                 'sum' => 100.25, 'views' => 500, 'clicks' => 20],
                ['advertId' => 123, 'advertName' => 'К1', 'nmId' => 456,
                 'sum' => 50.75, 'views' => 300, 'clicks' => 15],
                ['advertId' => 123, 'advertName' => 'К1', 'nmId' => 999,
                 'sum' => 25.00, 'views' => 100, 'clicks' => 5],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(2, $result);

        self::assertSame('123', $result[0]->campaignId);
        self::assertSame('456', $result[0]->parentSku);
        self::assertSame('151.00', $result[0]->cost);
        self::assertSame(800, $result[0]->impressions);
        self::assertSame(35, $result[0]->clicks);

        self::assertSame('123', $result[1]->campaignId);
        self::assertSame('999', $result[1]->parentSku);
        self::assertSame('25.00', $result[1]->cost);
    }

    public function testSeparatesDifferentAdverts(): void
    {
        $json = json_encode([
            'adverts' => [
                ['advertId' => 111, 'advertName' => 'A', 'nmId' => 456,
                 'sum' => 10.00, 'views' => 100, 'clicks' => 1],
                ['advertId' => 222, 'advertName' => 'B', 'nmId' => 456,
                 'sum' => 20.00, 'views' => 200, 'clicks' => 2],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(2, $result);
        self::assertSame('111', $result[0]->campaignId);
        self::assertSame('222', $result[1]->campaignId);
    }

    public function testEmptyAdvertsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->parser->parse('{"adverts": []}'));
        self::assertSame([], $this->parser->parse('{}'));
    }

    public function testCostIsFormattedString(): void
    {
        $json = json_encode([
            'adverts' => [
                ['advertId' => 1, 'advertName' => 'A', 'nmId' => 2,
                 'sum' => 1.005, 'views' => 1, 'clicks' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

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
            'adverts' => [
                ['advertId' => 1, 'advertName' => 'A', 'nmId' => 2,
                 'sum' => 0.005, 'views' => 1, 'clicks' => 0],
                ['advertId' => 1, 'advertName' => 'A', 'nmId' => 2,
                 'sum' => 0.005, 'views' => 1, 'clicks' => 0],
                ['advertId' => 1, 'advertName' => 'A', 'nmId' => 2,
                 'sum' => 0.005, 'views' => 1, 'clicks' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json);

        self::assertCount(1, $result);
        self::assertSame('0.02', $result[0]->cost);
    }

    public function testSkipsRowsWithMissingRequiredFields(): void
    {
        $json = json_encode([
            'adverts' => [
                ['advertId' => 1, 'advertName' => 'A', 'nmId' => 2,
                 'sum' => 10.00, 'views' => 100, 'clicks' => 5],
                ['advertName' => 'no id', 'nmId' => 3,
                 'sum' => 5.00, 'views' => 50, 'clicks' => 1],
                ['advertId' => 2, 'advertName' => 'no nmId',
                 'sum' => 5.00, 'views' => 50, 'clicks' => 1],
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
        $parser = new WildberriesAdRawDataParser($logger);

        $json = json_encode([
            'adverts' => [
                ['advertId' => 1, 'advertName' => 'A', 'nmId' => 2,
                 'sum' => 10.00, 'views' => 100, 'clicks' => 5],
                ['advertName' => 'no id', 'nmId' => 3,
                 'sum' => 5.00, 'views' => 50, 'clicks' => 1],
                ['advertId' => 2, 'advertName' => 'no nmId',
                 'sum' => 5.00, 'views' => 50, 'clicks' => 1],
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
        $parser = new WildberriesAdRawDataParser($logger);

        $json = json_encode([
            'adverts' => [
                ['advertId' => 1, 'advertName' => 'A', 'nmId' => 2,
                 'sum' => 10.00, 'views' => 100, 'clicks' => 5],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser->parse($json);

        self::assertSame([], $logger->records);
    }
}
